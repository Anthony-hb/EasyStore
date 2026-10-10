<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    require '../includes/db.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos.']);
    exit;
}

require 'check_session.php';
requerirAdmin();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos del pedido.']);
    exit;
}

$id = intval($input['id'] ?? 0);
$proveedor_id = intval($input['proveedor_id'] ?? 0);
$estado = $input['estado'] ?? 'pendiente';
$estadosValidos = ['pendiente', 'enviado', 'recibido', 'cancelado'];
if (!in_array($estado, $estadosValidos, true)) {
    $estado = 'pendiente';
}
$observaciones = Sanitizer::string($input['observaciones'] ?? '', 800);
$items = is_array($input['items'] ?? null) ? $input['items'] : [];
$usuario_admin_id = $_SESSION['usuario_id'] ?? null;

// Fecha del pedido: viene del input datetime-local ("YYYY-MM-DDTHH:MM").
// Si no llega, se usa el momento actual (comportamiento anterior).
$fecha_pedido = trim((string)($input['fecha_pedido'] ?? ''));
if ($fecha_pedido !== '') {
    $ts = strtotime(str_replace('T', ' ', $fecha_pedido));
    $fecha_pedido = $ts ? date('Y-m-d H:i:s', $ts) : null;
} else {
    $fecha_pedido = null;
}

$unidadesValidas = ['unidad', 'caja', 'paquete'];

if ($proveedor_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Selecciona un proveedor antes de guardar el pedido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $stmtUp = $pdo->prepare("UPDATE pedidos SET proveedor_id = ?, estado = ?, observaciones = ?, fecha_pedido = COALESCE(?, fecha_pedido), usuario_admin_id = ? WHERE id = ?");
        $stmtUp->execute([$proveedor_id, $estado, $observaciones, $fecha_pedido, $usuario_admin_id, $id]);
        // Se eliminan los items y se reinsertan con los valores actualizados
        $pdo->prepare("DELETE FROM pedido_items WHERE pedido_id = ?")->execute([$id]);
    } else {
        $stmtIns = $pdo->prepare("INSERT INTO pedidos (proveedor_id, estado, observaciones, fecha_pedido, usuario_admin_id) VALUES (?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP), ?)");
        $stmtIns->execute([$proveedor_id, $estado, $observaciones, $fecha_pedido, $usuario_admin_id]);
        $id = $pdo->lastInsertId();
    }

    $stmtBuscarNombre = $pdo->prepare("SELECT id, nombre FROM productos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
    $stmtItem = $pdo->prepare("INSERT INTO pedido_items (pedido_id, producto_id, nombre_producto, cantidad, unidad_medida, precio_unitario_usd) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($items as $item) {
        $cantidad = intval($item['cantidad'] ?? 0);
        $precio = floatval($item['precio_unitario'] ?? 0);
        $nombre = trim($item['nombre_producto'] ?? '');
        $productoId = intval($item['producto_id'] ?? 0);
        $unidad = strtolower(trim((string)($item['unidad_medida'] ?? 'unidad')));
        if (!in_array($unidad, $unidadesValidas, true)) {
            $unidad = 'unidad';
        }

        if ($cantidad <= 0 || $precio < 0 || $nombre === '') {
            continue;
        }

        $finalId = $productoId;
        if ($finalId <= 0) {
            $stmtBuscarNombre->execute([$nombre]);
            $row = $stmtBuscarNombre->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $finalId = intval($row['id']);
                $nombre = $row['nombre'];
            }
        }

        $stmtItem->execute([
            $id,
            $finalId > 0 ? $finalId : null,
            Sanitizer::string($nombre, 255),
            $cantidad,
            $unidad,
            round($precio, 2)
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'pedido_id' => $id]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error save_pedido.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al guardar el pedido: ' . $e->getMessage()]);
}