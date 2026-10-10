<?php
// Este endpoint devuelve datos sensibles del proveedor (cedula, direccion,
// banco y numero de cuenta), por lo que exige sesion de administrador.
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = $pdo->query("SELECT * FROM proveedores ORDER BY id DESC");
    $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'proveedores' => $proveedores]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudieron cargar los proveedores.']);
}
?>
