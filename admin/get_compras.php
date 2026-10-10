<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    $sql = "SELECT c.*, p.nombre_negocio AS proveedor_nombre, p.nombre AS proveedor_nombre_contacto, p.apellido AS proveedor_apellido,
            (SELECT GROUP_CONCAT(CONCAT(cd.cantidad, 'x ', cd.nombre_producto,
                     CASE WHEN cd.marca_producto IS NOT NULL AND cd.marca_producto <> ''
                          THEN CONCAT(' (', cd.marca_producto, ')') ELSE '' END)
                    SEPARATOR ', ') FROM compra_detalles cd WHERE cd.compra_id = c.id) AS productos
            FROM compras c
            LEFT JOIN proveedores p ON c.proveedor_id = p.id
            ORDER BY c.fecha_compra DESC";
    $stmt = $pdo->query($sql);
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($compras as &$compra) {
        $compra['total_usd'] = floatval($compra['total_usd'] ?? 0);
        $compra['total_bs'] = floatval($compra['total_bs'] ?? 0);
        $compra['impuestos_pct'] = floatval($compra['impuestos_pct'] ?? 0);
        $compra['descuento_total'] = floatval($compra['descuento_total'] ?? 0);
    }

    echo json_encode(['success' => true, 'data' => $compras]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
