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
    $stmt = $pdo->prepare("SELECT pe.*, p.nombre_negocio AS proveedor_nombre
            FROM pedidos pe
            LEFT JOIN proveedores p ON pe.proveedor_id = p.id
            WHERE pe.id = ?");
    $stmt->execute([$id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        echo json_encode(['success' => false, 'error' => 'Pedido no encontrado.']);
        exit;
    }

    $stmtItems = $pdo->prepare("SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id ASC");
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$it) {
        $it['cantidad'] = intval($it['cantidad']);
        $it['precio_unitario_usd'] = floatval($it['precio_unitario_usd']);
    }
    unset($it);

    $pedido['items'] = $items;

    echo json_encode(['success' => true, 'pedido' => $pedido]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}