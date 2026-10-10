<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'pago_movil' => [
            'telefono' => $admin['pm_telefono'] ?? '',
            'banco' => $admin['pm_banco'] ?? '',
            'cedula' => $admin['pm_cedula'] ?? '',
            'titular' => $admin['nombre_completo'] ?? '',
        ],
        'transferencia' => [
            'banco' => $admin['transf_banco'] ?? '',
            'cuenta' => $admin['transf_cuenta'] ?? '',
            'titular' => $admin['transf_titular'] ?? '',
            'cedula' => $admin['transf_cedula'] ?? '',
        ],
        'zelle' => [
            'correo' => $admin['zelle_correo'] ?? '',
            'titular' => $admin['zelle_titular'] ?? '',
        ],
        'biopago' => [
            'banco' => $admin['biopago_banco'] ?? '',
            'cedula' => $admin['biopago_cedula'] ?? '',
            'titular' => $admin['biopago_titular'] ?? '',
        ],
        'negocio' => [
            'nombre' => $admin['negocio_nombre'] ?? '',
            'horario' => $admin['negocio_horario'] ?? '',
            'ubicacion' => $admin['negocio_ubicacion'] ?? '',
            'telefono' => $admin['negocio_telefono'] ?? '',
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
