<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

try {
    $devoluciones = $pdo->query("
        SELECT dp.id,
               dp.compra_id,
               DATE_FORMAT(dp.fecha_devolucion, '%Y-%m-%d %H:%i:%s') AS fecha_devolucion,
               dp.tipo,
               dp.estado,
               dp.metodo_reembolso,
               dp.monto_devuelto_usd,
               dp.monto_devuelto_bs,
               dp.tasa_cambio,
               dp.motivo,
               dp.notas,
               dp.anulada_por,
               DATE_FORMAT(dp.fecha_anulacion, '%Y-%m-%d %H:%i:%s') AS fecha_anulacion,
               dp.motivo_anulacion,
               p.nombre_negocio AS proveedor_nombre,
               (
                 SELECT GROUP_CONCAT(CONCAT(ddp.cantidad, 'x ', ddp.nombre_producto) SEPARATOR ', ')
                   FROM devolucion_proveedor_detalles ddp
                  WHERE ddp.devolucion_proveedor_id = dp.id
               ) AS productos,
               (
                 SELECT COUNT(*) FROM devolucion_proveedor_detalles ddp
                  WHERE ddp.devolucion_proveedor_id = dp.id
               ) AS lineas
          FROM devoluciones_proveedor dp
          LEFT JOIN compras c ON c.id = dp.compra_id
          LEFT JOIN proveedores p ON p.id = c.proveedor_id
         ORDER BY dp.fecha_devolucion DESC, dp.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Totales: las devoluciones ANULADAS no cuentan
    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total_devoluciones,
            COALESCE(SUM(CASE WHEN estado = 'ACTIVA' THEN 1 ELSE 0 END), 0) AS activas,
            COALESCE(SUM(CASE WHEN estado = 'ACTIVA' THEN monto_devuelto_usd ELSE 0 END), 0) AS monto_activo_usd,
            COALESCE(SUM(CASE WHEN estado = 'ACTIVA' AND metodo_reembolso = 'efectivo' THEN monto_devuelto_usd ELSE 0 END), 0) AS monto_efectivo_usd,
            COALESCE(SUM(CASE WHEN estado = 'ACTIVA' AND metodo_reembolso = 'nota_credito' THEN monto_devuelto_usd ELSE 0 END), 0) AS monto_nota_credito_usd,
            COALESCE(SUM(CASE WHEN estado = 'ANULADA' THEN 1 ELSE 0 END), 0) AS anuladas
          FROM devoluciones_proveedor
    ")->fetch(PDO::FETCH_ASSOC);

    foreach ($devoluciones as &$d) {
        $d['monto_devuelto_usd'] = (float)($d['monto_devuelto_usd'] ?? 0);
        $d['monto_devuelto_bs'] = (float)($d['monto_devuelto_bs'] ?? 0);
        $d['tasa_cambio']       = (float)($d['tasa_cambio'] ?? 0);
        $d['id']                = (int)$d['id'];
        $d['compra_id']         = (int)$d['compra_id'];
        $d['lineas']            = (int)($d['lineas'] ?? 0);
    }

    echo json_encode([
        'success'      => true,
        'devoluciones' => $devoluciones,
        'stats'        => [
            'total_devoluciones'       => (int)$stats['total_devoluciones'],
            'activas'                  => (int)$stats['activas'],
            'anuladas'                 => (int)$stats['anuladas'],
            'monto_activo_usd'         => round((float)$stats['monto_activo_usd'], 2),
            'monto_efectivo_usd'       => round((float)$stats['monto_efectivo_usd'], 2),
            'monto_nota_credito_usd'   => round((float)$stats['monto_nota_credito_usd'], 2),
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}