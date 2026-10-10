<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    // Resumen de productos que necesitan reposición (contexto del módulo)
    $resumen = $pdo->query("SELECT COUNT(*) AS total,
                SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) AS agotados
                FROM productos
                WHERE publicado = 1 AND (stock <= stock_minimo OR stock = 0)")
                ->fetch(PDO::FETCH_ASSOC);

    $sql = "SELECT pe.*, p.nombre_negocio AS proveedor_nombre,
            (SELECT COUNT(*) FROM pedido_items pi WHERE pi.pedido_id = pe.id) AS total_items,
            (SELECT COALESCE(SUM(pi.cantidad * pi.precio_unitario_usd), 0)
             FROM pedido_items pi WHERE pi.pedido_id = pe.id) AS total_usd,
            (SELECT GROUP_CONCAT(CONCAT(pi.cantidad, ' ',
                CASE pi.unidad_medida WHEN 'caja' THEN 'cajas' WHEN 'paquete' THEN 'paquetes' ELSE 'und' END,
                ' · ', pi.nombre_producto) SEPARATOR ', ')
             FROM pedido_items pi WHERE pi.pedido_id = pe.id) AS productos
            FROM pedidos pe
            LEFT JOIN proveedores p ON pe.proveedor_id = p.id
            ORDER BY pe.fecha_pedido DESC";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as &$ped) {
        $ped['total_usd'] = floatval($ped['total_usd'] ?? 0);
        $ped['total_items'] = intval($ped['total_items'] ?? 0);
    }
    unset($ped);

    echo json_encode([
        'success' => true,
        'data' => $data,
        'resumen' => [
            'productos_reponer' => intval($resumen['total'] ?? 0),
            'agotados' => intval($resumen['agotados'] ?? 0)
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}