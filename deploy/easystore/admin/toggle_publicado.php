<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

$data = json_decode(file_get_contents("php://input"), true);
$id = isset($data['id']) ? intval($data['id']) : 0;
$publicado = isset($data['publicado']) ? intval($data['publicado']) : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de producto requerido']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE productos SET publicado = ? WHERE id = ?");
    $stmt->execute([$publicado, $id]);
    echo json_encode(['success' => true, 'mensaje' => 'Estado de publicación actualizado']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
