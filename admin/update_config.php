<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['tasa_dolar'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Tasa del dólar requerida']);
    exit;
}

try {
    $tasa = floatval($data['tasa_dolar']);
    if ($tasa <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Tasa inválida']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO tasa_dolar (tasa) VALUES (:tasa)");
    $stmt->execute([':tasa' => $tasa]);

    echo json_encode(['success' => true, 'mensaje' => 'Tasa actualizada globalmente']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al actualizar configuración', 'detalle' => $e->getMessage()]);
}
?>
