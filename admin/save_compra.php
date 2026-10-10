<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    require '../includes/db.php';
} catch (Exception $e) {
    error_log("Error save_compra.php DB connect: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos.']);
    exit;
}

require 'check_session.php';
requerirAdmin();
require_once '../includes/kardex.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de compra.']);
    exit;
}

$proveedor_id = isset($input['proveedor_id']) ? intval($input['proveedor_id']) : 0;
$numero_factura = trim($input['numero_factura'] ?? '');
$fecha_factura = trim($input['fecha_factura'] ?? '');
$fecha_compra = trim($input['fecha_compra'] ?? '');
if ($fecha_compra) {
    $fecha_compra = str_replace('T', ' ', $fecha_compra);
}
$moneda = isset($input['moneda']) && in_array($input['moneda'], ['USD', 'BS']) ? $input['moneda'] : 'USD';
$tasa_cambio = floatval($input['tasa_cambio'] ?? 0);
$impuestos_pct = floatval($input['impuestos_pct'] ?? 0);
$descuento_total = floatval($input['descuento_total'] ?? 0);
// IGTF informativo: solo se carga si la compra se pagó en divisas.
$igtf_usd = round(floatval($input['igtf_usd'] ?? 0), 2);
if ($igtf_usd < 0) $igtf_usd = 0;
$estado = 'Recibido'; // Al registrar la compra se marca como Recibido para actualizar inventario
$fecha_recepcion = date('Y-m-d H:i:s');
$detalles = is_array($input['detalles'] ?? null) ? $input['detalles'] : [];
$usuario_admin_id = $_SESSION['usuario_id'] ?? null;

if ($proveedor_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Proveedor no válido.']);
    exit;
}

if (empty($detalles)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Agrega al menos un producto a la compra.']);
    exit;
}

if ($tasa_cambio <= 0) {
    $tasa_cambio = 1;
}

try {
    // Asegurar que la tabla abastecimientos exista
    $pdo->exec("CREATE TABLE IF NOT EXISTS abastecimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT,
        nombre_producto VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL,
        precio_compra_unitario DECIMAL(10, 2) NOT NULL,
        precio_venta_unitario DECIMAL(10, 2) NOT NULL,
        fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->beginTransaction();

    // Statements para resolución y creación de productos
    $stmtExisteId = $pdo->prepare("SELECT id, nombre, precio_compra, precio_usd FROM productos WHERE id = ?");
    $stmtBuscarNombre = $pdo->prepare("SELECT id, nombre, precio_compra, precio_usd FROM productos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
    $stmtCrearProd = $pdo->prepare("INSERT INTO productos (nombre, descripcion, precio_compra, precio_usd, stock, stock_minimo, destacado, publicado, categoria, subcategoria, imagen, imagenes, ficha_tecnica) VALUES (?, '', ?, ?, 0, 0, 0, 0, 'General', NULL, '', NULL, NULL)");

    $total_usd = 0;
    $detalles_procesados = [];

    foreach ($detalles as $det) {
        $cant = intval($det['cantidad'] ?? 0);
        $precio_unit = floatval($det['precio_unitario'] ?? 0);
        $nombre = trim($det['nombre_producto'] ?? '');
        $marca = Sanitizer::string($det['marca_producto'] ?? '', 60);
        $pId = intval($det['producto_id'] ?? 0);

        if ($cant <= 0 || $precio_unit < 0) {
            continue;
        }

        if ($nombre === '') {
            continue;
        }

        $prodEncontrado = null;

        // Buscar por nombre (el ID es solo una pista del frontend)
        $stmtBuscarNombre->execute([$nombre]);
        $prodEncontrado = $stmtBuscarNombre->fetch(PDO::FETCH_ASSOC);

        // Si no hay resultado por nombre, intentar buscar por ID
        if (!$prodEncontrado && $pId > 0) {
            $stmtExisteId->execute([$pId]);
            $prodEncontrado = $stmtExisteId->fetch(PDO::FETCH_ASSOC);
        }

        if ($prodEncontrado) {
            $finalId = intval($prodEncontrado['id']);
            $finalNombre = $prodEncontrado['nombre'];
        } else {
            // Crear el producto si no existe en la base de datos
            $nombreFinalCrear = $nombre;
            $stmtCrearProd->execute([
                $nombreFinalCrear,
                $precio_unit,
                $precio_unit
            ]);
            $finalId = intval($pdo->lastInsertId());
            $finalNombre = $nombreFinalCrear;
        }

        $subtotal = round($cant * $precio_unit, 2);
        $total_usd += $subtotal;

        $detalles_procesados[] = [
            'producto_id' => $finalId,
            'nombre_producto' => $finalNombre,
            'marca_producto' => $marca !== '' ? $marca : null,
            'cantidad' => $cant,
            'precio_unitario' => $precio_unit,
            'subtotal_usd' => $subtotal
        ];
    }

    if (empty($detalles_procesados)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No hay productos válidos en el detalle de la compra.']);
        exit;
    }

    $subtotal_neto = $total_usd;   // costo neto (sin IVA) de las lineas
    $impuestos_total = round($total_usd * ($impuestos_pct / 100), 2);
    $total_usd = round($total_usd + $impuestos_total - $descuento_total, 2);
    if ($total_usd < 0) $total_usd = 0;
    $total_bs = round($total_usd * $tasa_cambio, 2);

    // Base gravable = costo neto menos descuento. El IVA va aparte como credito
    // fiscal: NO se carga al costo ni al margen.
    $base_usd = round($subtotal_neto - $descuento_total, 2);
    if ($base_usd < 0) $base_usd = 0;
    $iva_credito_usd = $impuestos_total;

    // Retencion de IVA (si la tienda es agente de retencion). Por defecto 0.
    $retencion_pct = floatval($input['retencion_pct'] ?? 0);
    $retencion_iva_usd = $retencion_pct > 0 ? round($iva_credito_usd * ($retencion_pct / 100), 2) : 0.00;

    // Registrar la compra
    $sqlCompra = "INSERT INTO compras (
            proveedor_id, numero_factura, fecha_factura, moneda, impuestos_pct,
            descuento_total, total_usd, base_usd, iva_credito_usd, retencion_iva_usd, igtf_usd,
            total_bs, tasa_cambio, fecha_compra, fecha_recepcion, estado, usuario_admin_id
        ) VALUES (
            :proveedor_id, :numero_factura, :fecha_factura, :moneda, :impuestos_pct,
            :descuento_total, :total_usd, :base_usd, :iva_credito_usd, :retencion_iva_usd, :igtf_usd,
            :total_bs, :tasa_cambio, :fecha_compra, :fecha_recepcion, :estado, :usuario_admin_id
        )";
    $stmtCompra = $pdo->prepare($sqlCompra);
    $stmtCompra->execute([
        ':proveedor_id' => $proveedor_id,
        ':numero_factura' => $numero_factura,
        ':fecha_factura' => $fecha_factura ?: null,
        ':moneda' => $moneda,
        ':impuestos_pct' => $impuestos_pct,
        ':descuento_total' => $descuento_total,
        ':total_usd' => $total_usd,
        ':base_usd' => $base_usd,
        ':iva_credito_usd' => $iva_credito_usd,
        ':retencion_iva_usd' => $retencion_iva_usd,
        ':igtf_usd' => $igtf_usd,
        ':total_bs' => $total_bs,
        ':tasa_cambio' => $tasa_cambio,
        ':fecha_compra' => $fecha_compra ?: null,
        ':fecha_recepcion' => $fecha_recepcion,
        ':estado' => $estado,
        ':usuario_admin_id' => $usuario_admin_id
    ]);

    $compra_id = $pdo->lastInsertId();

    // Registrar detalles y actualizar stock en inventario
    $stmtDetalle = $pdo->prepare("INSERT INTO compra_detalles (
            compra_id, producto_id, nombre_producto, marca_producto, cantidad, precio_unitario, impuesto_unitario, subtotal_usd
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtProductoPrecio = $pdo->prepare("SELECT precio_usd FROM productos WHERE id = ?");

    $stmtAbastecimiento = $pdo->prepare("INSERT INTO abastecimientos (
            producto_id, nombre_producto, cantidad, precio_compra_unitario, precio_venta_unitario
        ) VALUES (?, ?, ?, ?, ?)");

    foreach ($detalles_procesados as $det) {
        // IVA unitario de la linea (credito fiscal). El costo sigue siendo neto.
        $impuesto_unit = round($det['precio_unitario'] * $impuestos_pct / 100, 2);
        $stmtDetalle->execute([
            $compra_id,
            $det['producto_id'],
            $det['nombre_producto'],
            $det['marca_producto'],
            $det['cantidad'],
            $det['precio_unitario'],
            $impuesto_unit,
            $det['subtotal_usd']
        ]);
        $compraDetalleId = (int)$pdo->lastInsertId();

        // SUMAR EL STOCK DIRECTAMENTE AL INVENTARIO DE PRODUCTOS
        // (aplicarMovimientoKardex actualiza productos.stock y deja el registro
        //  del Kardex con el saldo anterior y el nuevo en la misma operacion)
        aplicarMovimientoKardex($pdo, [
            'producto_id'         => $det['producto_id'],
            'nombre_producto'     => $det['nombre_producto'],
            'cantidad'            => $det['cantidad'],
            'concepto'            => 'COMPRA',
            'documento_tipo'      => 'COMPRA',
            'documento_id'        => $compra_id,
            'numero_referencia'   => $numero_factura,
            'costo_unitario_usd'  => $det['precio_unitario'],
            'compra_id'           => $compra_id,
            'compra_detalle_id'   => $compraDetalleId,
            'usuario_id'          => $usuario_admin_id,
            'observaciones'       => 'Compra #' . $compra_id
                                    . ($numero_factura !== '' ? ' (Factura ' . $numero_factura . ')' : ''),
        ]);

        // El precio de compra solo se define si el producto aun no lo tiene.
        $pdo->prepare("UPDATE productos
            SET precio_compra = CASE WHEN (precio_compra IS NULL OR precio_compra = 0) THEN ? ELSE precio_compra END
            WHERE id = ?")->execute([$det['precio_unitario'], $det['producto_id']]);

        $stmtProductoPrecio->execute([$det['producto_id']]);
        $prodRow = $stmtProductoPrecio->fetch(PDO::FETCH_ASSOC);
        $precio_venta_unitario = $prodRow ? floatval($prodRow['precio_usd']) : 0;

        $stmtAbastecimiento->execute([
            $det['producto_id'],
            $det['nombre_producto'],
            $det['cantidad'],
            $det['precio_unitario'],
            $precio_venta_unitario
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'compra_id' => $compra_id]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error save_compra.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al guardar la compra en el servidor: ' . $e->getMessage()]);
}
?>
