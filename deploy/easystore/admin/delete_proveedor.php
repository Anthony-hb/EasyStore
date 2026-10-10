<?php
// Endpoint destructivo: exige sesion de administrador.
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();
header('Content-Type: application/json; charset=utf-8');

$input = Sanitizer::readJsonInput();
$id = isset($input['id']) ? Sanitizer::int($input['id']) : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de proveedor no especificado.']);
    exit;
}

try {
    // Verificar si el proveedor tiene compras asociadas
    $check = $pdo->prepare("SELECT COUNT(*) FROM compras WHERE proveedor_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'No se puede eliminar el proveedor porque tiene compras registradas.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM proveedores WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'El proveedor indicado no existe.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Proveedor eliminado correctamente.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo eliminar el proveedor.']);
}
?>
