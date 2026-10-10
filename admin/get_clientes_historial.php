<?php
/**
 * Indice de clientes para el modulo de historial unificado.
 *
 * Devuelve todos los clientes con un resumen de su actividad (compras,
 * apartados, devoluciones) para que el admin elija a quien consultar.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

try {
    $sql = "
        SELECT
            c.id, c.usuario, c.nombre_completo, c.cedula, c.email, c.telefono,
            DATE_FORMAT(c.fecha_registro, '%Y-%m-%d') AS fecha_registro,

            -- Ventas
            COALESCE(v.total_ventas, 0)   AS total_ventas,
            COALESCE(v.ventas_pagadas, 0) AS ventas_pagadas,
            COALESCE(v.compras_usd, 0)    AS compras_usd,
            COALESCE(v.ganancia_usd, 0)   AS ganancia_usd,
            COALESCE(v.ultima_venta, '')  AS ultima_venta,

            -- Apartados
            COALESCE(a.apartados_activos, 0) AS apartados_activos,
            COALESCE(a.saldo_pendiente, 0)   AS saldo_pendiente,

            -- Devoluciones
            COALESCE(d.devoluciones_activas, 0) AS devoluciones_activas,
            COALESCE(d.reembolsado_usd, 0)      AS reembolsado_usd

        FROM clientes c
        LEFT JOIN (
            SELECT usuario_id,
                   COUNT(*) AS total_ventas,
                   SUM(CASE WHEN estado IN ('Pago Validado', 'Preparando', 'Entregado') THEN 1 ELSE 0 END) AS ventas_pagadas,
                   COALESCE(SUM(CASE WHEN estado IN ('Pago Validado', 'Preparando', 'Entregado') THEN total_usd ELSE 0 END), 0) AS compras_usd,
                   COALESCE(SUM(CASE WHEN estado IN ('Pago Validado', 'Preparando', 'Entregado')
                                     THEN (SELECT COALESCE(SUM(COALESCE(NULLIF(vd.base_usd, 0), vd.subtotal_usd) - vd.cantidad * vd.costo_unitario_usd), 0)
                                           FROM venta_detalles vd WHERE vd.venta_id = ventas.id)
                                     ELSE 0 END), 0) AS ganancia_usd,
                   MAX(fecha_venta) AS ultima_venta
            FROM ventas
            WHERE usuario_id IS NOT NULL
            GROUP BY usuario_id
        ) v ON v.usuario_id = c.id
        LEFT JOIN (
            SELECT usuario_id,
                   SUM(CASE WHEN estado = 'Activo' THEN 1 ELSE 0 END) AS apartados_activos,
                   COALESCE(SUM(CASE WHEN estado = 'Activo' THEN monto_pendiente_usd ELSE 0 END), 0) AS saldo_pendiente
            FROM apartados
            WHERE usuario_id IS NOT NULL
            GROUP BY usuario_id
        ) a ON a.usuario_id = c.id
        LEFT JOIN (
            SELECT v.usuario_id,
                   SUM(CASE WHEN d.estado = 'ACTIVA' THEN 1 ELSE 0 END) AS devoluciones_activas,
                   COALESCE(SUM(CASE WHEN d.estado = 'ACTIVA' THEN d.monto_devuelto_usd ELSE 0 END), 0) AS reembolsado_usd
            FROM devoluciones d
            JOIN ventas v ON v.id = d.venta_id
            WHERE v.usuario_id IS NOT NULL
            GROUP BY v.usuario_id
        ) d ON d.usuario_id = c.id
        ORDER BY c.nombre_completo ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientes as &$c) {
        $c['id'] = (int)$c['id'];
        $c['total_ventas'] = (int)$c['total_ventas'];
        $c['ventas_pagadas'] = (int)$c['ventas_pagadas'];
        $c['compras_usd'] = (float)$c['compras_usd'];
        $c['ganancia_usd'] = (float)$c['ganancia_usd'];
        $c['apartados_activos'] = (int)$c['apartados_activos'];
        $c['saldo_pendiente'] = (float)$c['saldo_pendiente'];
        $c['devoluciones_activas'] = (int)$c['devoluciones_activas'];
        $c['reembolsado_usd'] = (float)$c['reembolsado_usd'];

        // Marca util para ordenar: quien tiene movimiento va primero.
        $c['tiene_movimiento'] = ($c['total_ventas'] + $c['apartados_activos'] + $c['devoluciones_activas']) > 0;
    }
    unset($c);

    echo json_encode(['success' => true, 'clientes' => $clientes]);
} catch (Exception $e) {
    error_log("get_clientes_historial.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al cargar los clientes: ' . $e->getMessage()]);
}