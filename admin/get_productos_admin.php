<?php
header('Content-Type: application/json; charset=utf-8');

// Habilitar CORS para pruebas locales si fuera necesario
header('Access-Control-Allow-Origin: *');

session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    $stmt = $pdo->query("SELECT p.*,
            cd.codigo_producto,
            pr.id AS proveedor_id,
            pr.nombre AS proveedor_nombre
        FROM productos p
        LEFT JOIN (
            SELECT cd1.producto_id, cd1.codigo_producto, cd1.marca_producto, c1.proveedor_id
            FROM compra_detalles cd1
            INNER JOIN compras c1 ON cd1.compra_id = c1.id
            INNER JOIN (
                SELECT cd2.producto_id, MAX(cd2.id) AS max_id
                FROM compra_detalles cd2
                GROUP BY cd2.producto_id
            ) latest ON cd1.id = latest.max_id
        ) cd ON p.id = cd.producto_id
        LEFT JOIN proveedores pr ON cd.proveedor_id = pr.id
        ORDER BY p.id DESC");
    $productos = $stmt->fetchAll();

    // Procesar campos para que el Frontend los reciba igual que antes
    foreach ($productos as &$p) {
        $p['id'] = (int)$p['id'];
        $p['precio_usd'] = (float)$p['precio_usd'];
        $p['iva_alicuota'] = (float)($p['iva_alicuota'] ?? 16.00);
        $p['precio_compra'] = (float)($p['precio_compra'] ?? 0);
        $p['stock'] = (int)$p['stock'];
        $p['stock_minimo'] = (int)$p['stock_minimo'];
        $p['codigo_producto'] = $p['codigo_producto'] ?? '';
        $p['proveedor_id'] = $p['proveedor_id'] ? (int)$p['proveedor_id'] : null;
        $p['proveedor_nombre'] = $p['proveedor_nombre'] ?? '';

        // Decodificar JSON de imagenes adicionales si existe
        if ($p['imagenes']) {
            $decodedImages = json_decode($p['imagenes'], true);
            if (is_array($decodedImages)) {
                $p['imagenes'] = $decodedImages;
            } elseif (is_string($decodedImages) && $decodedImages !== '') {
                $p['imagenes'] = [$decodedImages];
            } else {
                $p['imagenes'] = [$p['imagen']];
            }
        } else {
            $p['imagenes'] = [$p['imagen']];
        }
    }

    echo json_encode($productos);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener productos', 'detalle' => $e->getMessage()]);
}
?>
