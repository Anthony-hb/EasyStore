<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../includes/db.php';
require_once '../includes/kardex.php';
require_once 'check_session.php';
requerirAdmin();

// Obtener datos del body de la petición
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$ventaId = $data['venta_id'] ?? null;
$estado = $data['estado'] ?? null;

if (!$ventaId || !$estado) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan parámetros requeridos.']);
    exit;
}

$estadosValidos = ['Pendiente Pago', 'Pago Enviado', 'Pago Validado', 'Preparando', 'Entregado', 'Cancelado', 'Pendiente'];
if (!in_array($estado, $estadosValidos)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El estado provisto no es válido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Obtener estado actual
    $stmtOld = $pdo->prepare("SELECT estado FROM ventas WHERE id = ?");
    $stmtOld->execute([$ventaId]);
    $oldState = $stmtOld->fetchColumn();

    if (!$oldState) {
        throw new Exception("Pedido no encontrado.");
    }

    // 2. Actualizar estado
    $stmt = $pdo->prepare("UPDATE ventas SET estado = ? WHERE id = ?");
    $stmt->execute([$estado, $ventaId]);
    
    // 3. Manejo inteligente del stock según el cambio de estado
    // El stock ÚNICAMENTE está descontado en 'Pago Validado', 'Preparando' y 'Entregado'.
    // En 'Pendiente Pago' y 'Pago Enviado' el stock NO ha sido descontado aún.
    $estadosConStockDescontado = ['Pago Validado', 'Preparando', 'Entregado'];
    $oldConStock = in_array($oldState, $estadosConStockDescontado, true);
    $newConStock = in_array($estado, $estadosConStockDescontado, true);

    // Si se cancela o regresa a Pendiente Pago / Pago Enviado desde un estado con stock descontado -> devolver stock
    if ($oldConStock && !$newConStock) {
        $stmtDetalles = $pdo->prepare("SELECT producto_id, cantidad, nombre_producto FROM venta_detalles WHERE venta_id = ?");
        $stmtDetalles->execute([$ventaId]);
        $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

        foreach ($detalles as $d) {
            if ($d['producto_id'] === null) {
                continue;
            }
            aplicarMovimientoKardex($pdo, [
                'producto_id'     => (int)$d['producto_id'],
                'nombre_producto' => $d['nombre_producto'] ?? null,
                'cantidad'        => (int)$d['cantidad'],
                'concepto'        => 'ANULACION_VENTA',
                'documento_tipo'  => 'VENTA',
                'documento_id'    => (int)$ventaId,
                'numero_referencia' => 'Venta #' . $ventaId,
                'venta_id'        => (int)$ventaId,
                'observaciones'   => 'Venta #' . $ventaId . ' paso de "' . $oldState . '" a "' . $estado . '"',
            ]);
        }
    }
    // Si pasa de un estado sin stock descontado a un estado validado -> validar y descontar stock
    elseif (!$oldConStock && $newConStock) {
        $stmtDetalles = $pdo->prepare("
            SELECT vd.id AS venta_detalle_id, vd.producto_id, vd.cantidad, vd.nombre_producto, vd.costo_unitario_usd, p.stock
            FROM venta_detalles vd
            JOIN productos p ON vd.producto_id = p.id
            WHERE vd.venta_id = ?
        ");
        $stmtDetalles->execute([$ventaId]);
        $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

        // Validar si hay stock suficiente
        foreach ($detalles as $d) {
            if ($d['stock'] < $d['cantidad']) {
                throw new Exception("Stock insuficiente para '{$d['nombre_producto']}'. Disponibles: {$d['stock']}, requeridos: {$d['cantidad']}.");
            }
        }

        foreach ($detalles as $d) {
            aplicarMovimientoKardex($pdo, [
                'producto_id'        => (int)$d['producto_id'],
                'nombre_producto'    => $d['nombre_producto'],
                'cantidad'           => -((int)$d['cantidad']),
                'concepto'           => 'VENTA',
                'documento_tipo'     => 'VENTA',
                'documento_id'       => (int)$ventaId,
                'numero_referencia' => 'Venta #' . $ventaId,
                'costo_unitario_usd' => $d['costo_unitario_usd'] ?? null,
                'venta_id'           => (int)$ventaId,
                'venta_detalle_id'   => (int)$d['venta_detalle_id'],
                'estricto'           => true,
                'observaciones'      => 'Venta #' . $ventaId . ' paso de "' . $oldState . '" a "' . $estado . '"',
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Estado del pedido actualizado correctamente.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
