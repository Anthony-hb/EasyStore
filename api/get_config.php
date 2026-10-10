<?php
header('Content-Type: application/json; charset=utf-8');
require '../includes/db.php';

try {
    // 1. Obtener última tasa registrada
    $stmtTasa = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
    $tasaRow = $stmtTasa->fetch(PDO::FETCH_ASSOC);
    $tasa = $tasaRow ? (float)$tasaRow['tasa'] : 50.00;

    // 2. Obtener datos del negocio desde la tabla administrador
    $stmtAdmin = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
    $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: [];

    $config = [
        'tasa_dolar' => $tasa,
        'negocio_nombre' => $admin['negocio_nombre'] ?? '',
        'negocio_telefono' => $admin['negocio_telefono'] ?? '',
        'negocio_horario' => $admin['negocio_horario'] ?? '',
        'negocio_ubicacion' => $admin['negocio_ubicacion'] ?? '',
        'pm_banco' => $admin['pm_banco'] ?? '',
        'pm_telefono' => $admin['pm_telefono'] ?? '',
        'pm_cedula' => $admin['pm_cedula'] ?? '',
        'transf_banco' => $admin['transf_banco'] ?? '',
        'transf_cuenta' => $admin['transf_cuenta'] ?? '',
        'transf_titular' => $admin['transf_titular'] ?? '',
        'transf_cedula' => $admin['transf_cedula'] ?? '',
        // Reglas de apartados definidas por el administrador
        'apartado_cuotas' => !empty($admin['apartado_cuotas']) ? (int)$admin['apartado_cuotas'] : 3,
        'apartado_dias_limite' => !empty($admin['apartado_dias_limite']) ? (int)$admin['apartado_dias_limite'] : 15
    ];

    echo json_encode(['success' => true, 'config' => $config]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener configuración', 'detalle' => $e->getMessage()]);
}
?>
