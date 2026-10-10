<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/kardex.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['venta_id'], $input['accion'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

$ventaId = (int)$input['venta_id'];
$accion = $input['accion']; // 'validar' o 'corregir'
$razon = trim($input['razon'] ?? '');

try {
    $pdo->beginTransaction();

    if ($accion === 'validar') {
        // Verificar si la venta provenía de un estado donde el stock aún NO había sido descontado
        $stmtVenta = $pdo->prepare("SELECT estado FROM ventas WHERE id = ?");
        $stmtVenta->execute([$ventaId]);
        $oldState = $stmtVenta->fetchColumn();

        $estadosSinStockDescontado = ['pendiente pago', 'pago enviado', 'pendiente', 'corrección solicitada', 'correccion solicitada', 'apartado'];
        if (in_array(mb_strtolower((string)$oldState, 'UTF-8'), $estadosSinStockDescontado, true)) {
            $stmtDetalles = $pdo->prepare("
                SELECT vd.producto_id, vd.cantidad, vd.nombre_producto, p.stock 
                FROM venta_detalles vd 
                JOIN productos p ON vd.producto_id = p.id 
                WHERE vd.venta_id = ?
            ");
            $stmtDetalles->execute([$ventaId]);
            $items = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

            // Validar stock disponible para todos los ítems antes de proceder
            foreach ($items as $item) {
                if ($item['stock'] < $item['cantidad']) {
                    throw new Exception("Stock insuficiente para '{$item['nombre_producto']}'. Disponibles: {$item['stock']}, requeridos: {$item['cantidad']}.");
                }
            }

            // Descontar stock únicamente en este momento (validación exitosa del pago)
            foreach ($items as $item) {
                aplicarMovimientoKardex($pdo, [
                    'producto_id'     => (int)$item['producto_id'],
                    'nombre_producto' => $item['nombre_producto'],
                    'cantidad'        => -((int)$item['cantidad']),
                    'concepto'        => 'VENTA',
                    'documento_tipo'  => 'VENTA',
                    'documento_id'    => (int)$ventaId,
                    'numero_referencia' => 'Venta #' . $ventaId,
                    'venta_id'        => (int)$ventaId,
                    'estricto'        => true,
                    'observaciones'   => 'Venta #' . $ventaId . ' (pago validado)',
                ]);
            }
        }

        // 1. Validar el pago
        $stmt = $pdo->prepare("UPDATE pagos SET estado = 'validado' WHERE venta_id = ? AND estado IN ('en_verificacion', 'pendiente', 'correccion')");
        $stmt->execute([$ventaId]);

        // 2. Cambiar estado de la venta
        $stmt = $pdo->prepare("UPDATE ventas SET estado = 'Pago Validado' WHERE id = ?");
        $stmt->execute([$ventaId]);

        // 3. El modulo de contabilidad fue retirado: ya no se asienta la venta.
        //    El movimiento de stock y su asiento en el Kardex ya se registraron
        //    mas arriba (al descontar el stock).

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Pago validado correctamente']);

    } elseif ($accion === 'rechazar') {
        if ($razon === '') {
            echo json_encode(['success' => false, 'error' => 'Debes indicar el motivo por el cual el pago no es válido.']);
            exit;
        }

        // Marcar pago como rechazado y solicitar nueva captura
        $stmt = $pdo->prepare("UPDATE pagos SET estado = 'rechazado', correccion_mensaje = ? WHERE venta_id = ? AND estado IN ('en_verificacion', 'pendiente', 'correccion')");
        $stmt->execute([$razon, $ventaId]);

        // Mantener la venta en 'Pago Enviado' para que el cliente corrija la info (el stock se descontará al validar)
        $stmt = $pdo->prepare("UPDATE ventas SET estado = 'Pago Enviado' WHERE id = ?");
        $stmt->execute([$ventaId]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Pago marcado como no válido. El cliente debe enviar nueva información.']);

    } else {
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
