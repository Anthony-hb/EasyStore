<?php
// Devuelve metricas internas del negocio (pagos por verificar, ventas
// pendientes), por lo que es de uso exclusivo del administrador.
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requieren permisos de administrador.']);
    exit;
}

try {
    // Contar pagos pendientes de verificación (para badge del admin)
    $stmt = $pdo->query("SELECT 
        COUNT(CASE WHEN estado = 'Pago Enviado' THEN 1 END) as pagos_por_verificar,
        COUNT(CASE WHEN estado = 'Pendiente Pago' THEN 1 END) as ventas_pendientes_pago
        FROM ventas
    ");
    $counts = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'pagos_por_verificar' => (int)$counts['pagos_por_verificar'],
        'ventas_pendientes_pago' => (int)$counts['ventas_pendientes_pago'],
        'total' => (int)$counts['pagos_por_verificar'] + (int)$counts['ventas_pendientes_pago']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudieron obtener las notificaciones.']);
}
?>
