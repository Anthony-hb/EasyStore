<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    $sql = "SELECT id, usuario, nombre_completo, cedula, email, telefono, fecha_registro 
            FROM clientes 
            ORDER BY fecha_registro DESC";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
