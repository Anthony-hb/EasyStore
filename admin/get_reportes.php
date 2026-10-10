<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    $ventasPorEstado = $pdo->query(
        "SELECT estado, COUNT(*) AS total FROM ventas GROUP BY estado ORDER BY total DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $topProductos = $pdo->query(
        "SELECT vd.producto_id, vd.nombre_producto, SUM(vd.cantidad) AS unidades, SUM(vd.subtotal_usd) AS ingresos
         FROM venta_detalles vd
         JOIN ventas v ON v.id = vd.venta_id
         GROUP BY vd.producto_id, vd.nombre_producto
         ORDER BY unidades DESC, ingresos DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ventasUltimos7Dias = $pdo->query(
        "SELECT DATE(fecha_venta) AS fecha, COUNT(*) AS ventas, SUM(total_usd) AS ingresos
         FROM ventas
         WHERE fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         GROUP BY DATE(fecha_venta)
         ORDER BY fecha ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ventas_por_estado' => $ventasPorEstado,
        'top_productos' => $topProductos,
        'ventas_ultimos_7_dias' => $ventasUltimos7Dias
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
