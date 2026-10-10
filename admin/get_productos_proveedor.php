<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

// Productos comprados a un proveedor concreto, con todas las marcas distintas
// con las que se le compraron. Se pide solo un proveedor a la vez para no
// traer el catalogo completo, que es lo que hacia lento el formulario antes.
$proveedorId = filter_var($_GET['proveedor_id'] ?? null, FILTER_VALIDATE_INT);
if ($proveedorId === false || $proveedorId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Proveedor no valido']);
    exit;
}

try {
    // 1) Un renglon por producto comprado a este proveedor.
    //    La linea mas reciente define el orden (lo ultimo comprado, primero).
    //    El GROUP BY solo agrupa por la clave resuelta del producto, asi que no
    //    hace falta repetir todas las columnas de productos en el GROUP BY.
    $sql = "SELECT t.producto_id,
                   t.nombre,
                   t.ultima_linea,
                   p.categoria, p.subcategoria, p.marca,
                   p.precio_compra, p.precio_usd, p.iva_alicuota, p.stock, p.stock_minimo,
                   p.imagen, p.imagenes, p.descripcion, p.ficha_tecnica, p.publicado
            FROM (
                SELECT COALESCE(d.producto_id, 0) AS producto_id,
                       COALESCE(NULLIF(p0.nombre, ''), d.nombre_producto) AS nombre,
                       MAX(d.id) AS ultima_linea
                FROM compras c
                INNER JOIN compra_detalles d ON d.compra_id = c.id
                LEFT JOIN productos p0 ON p0.id = d.producto_id
                WHERE c.proveedor_id = :prov
                  AND d.nombre_producto IS NOT NULL
                  AND d.nombre_producto <> ''
                GROUP BY COALESCE(d.producto_id, 0),
                         COALESCE(NULLIF(p0.nombre, ''), d.nombre_producto)
            ) t
            LEFT JOIN productos p ON p.id = t.producto_id
            ORDER BY t.ultima_linea DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':prov' => $proveedorId]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) Marcas distintas por producto. Se ordenan por la linea mas reciente
    //    para que la marca de la ultima compra quede de primera.
    $sqlMarcas = "SELECT COALESCE(d.producto_id, 0) AS producto_id,
                         COALESCE(NULLIF(p0.nombre, ''), d.nombre_producto) AS nombre,
                         d.marca_producto,
                         MAX(d.id) AS ultima_linea
                  FROM compras c
                  INNER JOIN compra_detalles d ON d.compra_id = c.id
                  LEFT JOIN productos p0 ON p0.id = d.producto_id
                  WHERE c.proveedor_id = :prov
                    AND d.marca_producto IS NOT NULL
                    AND d.marca_producto <> ''
                  GROUP BY COALESCE(d.producto_id, 0),
                           COALESCE(NULLIF(p0.nombre, ''), d.nombre_producto),
                           d.marca_producto";

    $stmtM = $pdo->prepare($sqlMarcas);
    $stmtM->execute([':prov' => $proveedorId]);

    // clave de cruce: id + nombre, para que un producto sin id (detalle huerfano)
    // no se mezcle con otro que tenga el mismo nombre.
    $clave = static function ($id, $nombre): string {
        return (int)$id . '|' . mb_strtolower(trim((string)$nombre));
    };

    $marcasPorProducto = [];
    foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $marcasPorProducto[$clave($m['producto_id'], $m['nombre'])][] = [
            'marca' => trim($m['marca_producto']),
            'linea' => (int)$m['ultima_linea'],
        ];
    }

    $lista = [];
    foreach ($filas as $f) {
        $productoId = (int)$f['producto_id'];
        $nombre = (string)$f['nombre'];

        // Marcas de este producto a este proveedor, de la mas reciente a la mas
        // antigua y sin repetir (comparando sin distinguir mayusculas).
        $marcas = $marcasPorProducto[$clave($productoId, $nombre)] ?? [];
        usort($marcas, static fn($a, $b) => $b['linea'] <=> $a['linea']);

        $nombresMarca = [];
        foreach ($marcas as $m) {
            $nombreMarca = $m['marca'];
            if ($nombreMarca === '') continue;
            $yaEsta = false;
            foreach ($nombresMarca as $ya) {
                if (mb_strtolower($ya) === mb_strtolower($nombreMarca)) { $yaEsta = true; break; }
            }
            if (!$yaEsta) $nombresMarca[] = $nombreMarca;
        }

        // imagenes viene como JSON en la base; el formulario espera un arreglo.
        $imagenes = null;
        if (!empty($f['imagenes'])) {
            $decodificadas = json_decode($f['imagenes'], true);
            if (is_array($decodificadas)) {
                $imagenes = $decodificadas;
            }
        }

        $lista[] = [
            'id'              => $productoId,
            'nombre'          => $nombre,
            'categoria'       => $f['categoria'] ?? '',
            'subcategoria'    => $f['subcategoria'],
            'marca'           => $f['marca'] ?? '',
            'precio_compra'   => (float)($f['precio_compra'] ?? 0),
            'precio_usd'      => (float)($f['precio_usd'] ?? 0),
            'iva_alicuota'    => (float)($f['iva_alicuota'] ?? 16.00),
            'stock'           => (int)($f['stock'] ?? 0),
            'stock_minimo'    => (int)($f['stock_minimo'] ?? 0),
            'imagen'          => $f['imagen'] ?? '',
            'imagenes'        => $imagenes,
            'descripcion'     => $f['descripcion'] ?? '',
            'ficha_tecnica'   => $f['ficha_tecnica'] ?? '',
            'publicado'       => (int)($f['publicado'] ?? 0),
            // Marcas con las que se le compro a ESTE proveedor: es la lista que
            // se despliega en el campo Marca, para no escribirla a mano.
            'marcas'          => $nombresMarca,
            'ultima_linea'    => (int)$f['ultima_linea'],
        ];
    }

    echo json_encode([
        'success'      => true,
        'proveedor_id' => $proveedorId,
        'total'        => count($lista),
        'productos'    => $lista,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Error al obtener los productos del proveedor',
        'detalle' => $e->getMessage(),
    ]);
}
