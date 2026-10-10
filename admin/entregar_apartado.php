<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

$input = json_decode(file_get_contents('php://input'), true);
$apartadoId = isset($input['apartado_id']) ? (int)$input['apartado_id'] : 0;

if ($apartadoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de apartado no válido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT a.*, v.estado AS venta_estado FROM apartados a JOIN ventas v ON v.id = a.venta_id WHERE a.id = ? FOR UPDATE");
    $stmt->execute([$apartadoId]);
    $apartado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$apartado) {
        throw new Exception("El apartado no existe.");
    }

    if ($apartado['estado'] === 'Cancelado') {
        throw new Exception("No se puede entregar un apartado cancelado.");
    }

    $pendiente = (float)$apartado['monto_pendiente_usd'];
    if ($pendiente > 0.001) {
        throw new Exception("No se puede entregar el producto porque aún tiene un saldo pendiente de $" . number_format($pendiente, 2) . " USD. El cliente debe completar el 100% del pago para retirarlo.");
    }

    // Actualizar estado de la venta a 'Entregado'
    $stmtV = $pdo->prepare("UPDATE ventas SET estado = 'Entregado' WHERE id = ?");
    $stmtV->execute([$apartado['venta_id']]);

    // Asegurar que apartado esté marcado como Completado
    $stmtA = $pdo->prepare("UPDATE apartados SET estado = 'Completado', fecha_completado = COALESCE(fecha_completado, NOW()) WHERE id = ?");
    $stmtA->execute([$apartadoId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "¡Producto entregado con éxito al cliente! La venta quedó registrada como Entregada."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
