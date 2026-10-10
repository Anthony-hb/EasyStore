<?php
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $receiptSelect = "p.receipt_path";
    try {
        $stmtCheck = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'receipt_path'");
        if (!$stmtCheck->fetch()) {
            $receiptSelect = "'' AS receipt_path";
        }
    } catch (Exception $e) {
        $receiptSelect = "'' AS receipt_path";
    }

    $sql = "
        SELECT
            p.id,
            p.venta_id,
            p.metodo_pago,
            p.banco_origen,
            p.telefono_origen,
            p.referencia,
            p.monto,
            {$receiptSelect},
            p.fecha_pago,
            p.estado AS pago_estado,
            v.total_usd,
            v.total_bs,
            v.estado  AS venta_estado,
            COALESCE(NULLIF(u.nombre_completo, ''), u.usuario) AS cliente_nombre,
            u.email    AS cliente_email,
            u.telefono AS cliente_telefono,
            (SELECT GROUP_CONCAT(CONCAT(vd.cantidad, 'x ', vd.nombre_producto) SEPARATOR ', ')
             FROM venta_detalles vd WHERE vd.venta_id = v.id) AS productos
        FROM pagos p
        JOIN ventas v ON p.venta_id = v.id
        LEFT JOIN clientes u ON v.usuario_id = u.id
        WHERE p.estado IN ('pendiente', 'en_verificacion', 'correccion', 'rechazado') AND v.estado IN ('Pago Enviado', 'Pendiente Pago')
        ORDER BY p.fecha_pago DESC
    ";

    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $data]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
