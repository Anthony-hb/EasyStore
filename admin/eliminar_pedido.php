<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de pedido inválido.']);
    exit;
}

try {
    // El borrado en cascada elimina también pedido_items
    $stmt = $pdo->prepare("DELETE FROM pedidos WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'El pedido no existe.']);
    } else {
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar el pedido.']);
}