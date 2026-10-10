<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/kardex.php';
require_once 'check_session.php';
requerirAdmin();

$input = json_decode(file_get_contents('php://input'), true);
$apartadoId = isset($input['apartado_id']) ? (int)$input['apartado_id'] : 0;
$motivo = trim($input['motivo'] ?? 'Cancelado por administrador');
$reingresarStock = isset($input['reingresar_stock']) ? (bool)$input['reingresar_stock'] : true;

if ($apartadoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de apartado no válido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM apartados WHERE id = ? FOR UPDATE");
    $stmt->execute([$apartadoId]);
    $apartado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$apartado) {
        throw new Exception("El apartado no existe.");
    }

    if ($apartado['estado'] === 'Cancelado') {
        throw new Exception("Este apartado ya se encuentra cancelado.");
    }

    $ventaId = $apartado['venta_id'];

    // Si se solicita reingresar stock de los productos
    if ($reingresarStock) {
        $stmtD = $pdo->prepare("SELECT producto_id, nombre_producto, cantidad FROM venta_detalles WHERE venta_id = ? AND producto_id IS NOT NULL");
        $stmtD->execute([$ventaId]);
        $items = $stmtD->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $it) {
            aplicarMovimientoKardex($pdo, [
                'producto_id'     => (int)$it['producto_id'],
                'nombre_producto' => $it['nombre_producto'],
                'cantidad'        => (int)$it['cantidad'],
                'concepto'        => 'ANULACION_VENTA',
                'documento_tipo'  => 'APARTADO',
                'documento_id'    => (int)$apartadoId,
                'numero_referencia' => 'Apartado #' . $apartadoId,
                'venta_id'        => (int)$ventaId,
                'observaciones'   => 'Cancelacion del apartado #' . $apartadoId
                                    . ($motivo !== '' ? ' - ' . $motivo : ''),
            ]);
        }
    }

    // Actualizar apartado a 'Cancelado'
    $stmtUpdAp = $pdo->prepare("
        UPDATE apartados SET 
            estado = 'Cancelado', 
            fecha_cancelacion = NOW(), 
            motivo_cancelacion = ? 
        WHERE id = ?
    ");
    $stmtUpdAp->execute([$motivo, $apartadoId]);

    // Actualizar venta a 'Cancelado'
    $stmtUpdV = $pdo->prepare("UPDATE ventas SET estado = 'Cancelado' WHERE id = ?");
    $stmtUpdV->execute([$ventaId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Apartado cancelado exitosamente." . ($reingresarStock ? " La mercancía reservada fue reingresada al inventario." : "")
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
