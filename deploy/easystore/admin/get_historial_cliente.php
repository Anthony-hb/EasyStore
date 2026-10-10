<?php
/**
 * Historial unificado por cliente: ventas, apartados y devoluciones.
 *
 * A diferencia de los modulos sueltos, aqui no se filtra por estado: se ve
 * TODO lo que el cliente ha hecho, incluidas las ventas que aun no se pagan.
 *
 * Parametro: cliente_id (int, requerido)
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

$clienteId = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

if ($clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cliente no válido.']);
    exit;
}

try {
    // ---------- Datos del cliente ----------
    $stmtCliente = $pdo->prepare("
        SELECT id, usuario, nombre_completo, cedula, email, telefono, direccion, fecha_registro
        FROM clientes WHERE id = ? LIMIT 1
    ");
    $stmtCliente->execute([$clienteId]);
    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Cliente no encontrado.']);
        exit;
    }

    // ---------- Ventas (todos los estados) ----------
    // usuario_id es el vinculo con clientes. Se incluye 'cliente_cedula' para
    // poder rastrear ventas hechas cuando el cliente todavia no estaba
    // registrado y se capturaron los datos en el momento.
    $stmtVentas = $pdo->prepare("
        SELECT v.id, v.usuario_id, v.cliente_nombre, v.cliente_cedula, v.cliente_telefono,
               v.total_usd, v.tasa_cambio, v.total_bs, v.metodo_pago,
               v.referencia_pago, v.estado, v.fecha_venta,
               (SELECT GROUP_CONCAT(CONCAT(vd.cantidad, 'x ', vd.nombre_producto) SEPARATOR ', ')
                  FROM venta_detalles vd WHERE vd.venta_id = v.id) AS productos,
               (SELECT COALESCE(SUM(COALESCE(NULLIF(vd.base_usd, 0), vd.subtotal_usd) - vd.cantidad * vd.costo_unitario_usd), 0)
                  FROM venta_detalles vd WHERE vd.venta_id = v.id) AS ganancia_usd,
               (SELECT COUNT(*) FROM venta_detalles vd WHERE vd.venta_id = v.id) AS lineas
        FROM ventas v
        WHERE v.usuario_id = ?
        ORDER BY v.fecha_venta DESC, v.id DESC
    ");
    $stmtVentas->execute([$clienteId]);
    $ventas = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);

    // ---------- Apartados ----------
    $stmtApartados = $pdo->prepare("
        SELECT a.id, a.venta_id, a.total_usd, a.total_bs, a.tasa_cambio,
               a.monto_abonado_usd, a.monto_pendiente_usd, a.porcentaje_inicial,
               a.fecha_apartado, a.fecha_limite, a.dias_limite, a.cuotas, a.estado,
               a.fecha_completado, a.fecha_cancelacion, a.motivo_cancelacion, a.notas,
               v.estado AS venta_estado,
               DATEDIFF(a.fecha_limite, CURDATE()) AS dias_restantes,
               (SELECT GROUP_CONCAT(CONCAT(vd.cantidad, 'x ', vd.nombre_producto) SEPARATOR ', ')
                  FROM venta_detalles vd WHERE vd.venta_id = a.venta_id) AS productos,
               (SELECT COUNT(*) FROM apartado_abonos ab WHERE ab.apartado_id = a.id) AS total_abonos
        FROM apartados a
        LEFT JOIN ventas v ON v.id = a.venta_id
        WHERE a.usuario_id = ?
        ORDER BY
            CASE a.estado WHEN 'Activo' THEN 1 WHEN 'Completado' THEN 2 ELSE 3 END,
            a.fecha_limite ASC, a.id DESC
    ");
    $stmtApartados->execute([$clienteId]);
    $apartados = $stmtApartados->fetchAll(PDO::FETCH_ASSOC);

    // Historial de abonos de cada apartado
    $stmtAbonos = $pdo->prepare("
        SELECT id, apartado_id, monto_usd, monto_bs, metodo_pago, referencia,
               comprobante_img, fecha_abono, notas
        FROM apartado_abonos
        WHERE apartado_id = ?
        ORDER BY fecha_abono DESC, id DESC
    ");

    foreach ($apartados as &$ap) {
        $stmtAbonos->execute([$ap['id']]);
        $ap['abonos'] = $stmtAbonos->fetchAll(PDO::FETCH_ASSOC);

        $ap['id'] = (int)$ap['id'];
        $ap['venta_id'] = (int)$ap['venta_id'];
        foreach (['total_usd', 'total_bs', 'tasa_cambio', 'monto_abonado_usd', 'monto_pendiente_usd', 'porcentaje_inicial'] as $campo) {
            $ap[$campo] = (float)$ap[$campo];
        }
        $ap['total_abonos'] = (int)$ap['total_abonos'];
        $ap['dias_restantes'] = (int)$ap['dias_restantes'];

        // Alias que espera el modulo de Apartados, para poder reutilizar sus
        // modales de abono/entrega desde aqui sin duplicar la logica.
        $ap['total_abonos_count'] = $ap['total_abonos'];
        $ap['productos_resumen'] = $ap['productos'] !== null ? $ap['productos'] : 'Sin productos';
        $ap['cliente_nombre']    = $cliente['nombre_completo'];
        $ap['cliente_cedula']    = $cliente['cedula'] !== null && $cliente['cedula'] !== '' ? $cliente['cedula'] : '-';
        $ap['cliente_telefono']  = $cliente['telefono'] !== null && $cliente['telefono'] !== '' ? $cliente['telefono'] : '-';

        $porcentaje = $ap['total_usd'] > 0 ? round(($ap['monto_abonado_usd'] / $ap['total_usd']) * 100, 1) : 0;
        $ap['porcentaje_pagado'] = min($porcentaje, 100);

        $ap['situacion_tiempo'] = 'en_tiempo';
        if ($ap['estado'] === 'Activo' && $ap['dias_restantes'] < 0) {
            $ap['situacion_tiempo'] = 'vencido';
        } elseif ($ap['estado'] === 'Activo' && $ap['dias_restantes'] <= 3) {
            $ap['situacion_tiempo'] = 'proximo';
        } elseif (in_array($ap['estado'], ['Vencido', 'Cancelado'], true)) {
            $ap['situacion_tiempo'] = $ap['estado'] === 'Vencido' ? 'vencido' : 'cancelado';
        } elseif ($ap['estado'] === 'Completado') {
            $ap['situacion_tiempo'] = 'completado';
        }
    }
    unset($ap);

    // ---------- Devoluciones ----------
    // Las devoluciones cuelgan de una venta, asi que se llega por ahi.
    $stmtDevoluciones = $pdo->prepare("
        SELECT d.id, d.venta_id, d.tipo, d.estado, d.metodo_reembolso,
               d.monto_devuelto_usd, d.monto_devuelto_bs, d.fecha_devolucion,
               d.motivo, d.notas, d.fecha_anulacion, d.motivo_anulacion,
               v.fecha_venta,
               ad.usuario AS registrado_por,
               aa.usuario AS anulado_por,
               (SELECT GROUP_CONCAT(CONCAT(dd.cantidad, 'x ', dd.nombre_producto) SEPARATOR ', ')
                  FROM devolucion_detalles dd WHERE dd.devolucion_id = d.id) AS productos
        FROM devoluciones d
        JOIN ventas v ON v.id = d.venta_id
        LEFT JOIN administrador ad ON ad.id = d.usuario_admin_id
        LEFT JOIN administrador aa ON aa.id = d.anulada_por
        WHERE v.usuario_id = ?
        ORDER BY d.fecha_devolucion DESC, d.id DESC
    ");
    $stmtDevoluciones->execute([$clienteId]);
    $devoluciones = $stmtDevoluciones->fetchAll(PDO::FETCH_ASSOC);

    // ---------- Totales del cliente ----------
    $resumen = [
        'ventas_total'      => 0,
        'ventas_pagadas'    => 0,
        'ventas_apartadas'  => 0,
        'ventas_pendientes' => 0,
        'compras_usd'       => 0.0,
        'ganancia_usd'      => 0.0,
        'apartados_activos' => 0,
        'apartados_saldo'   => 0.0,
        'devoluciones_activas' => 0,
        'devoluciones_usd'  => 0.0,
    ];

    // Las ventas en estado 'Apartado' no son compras perdidas: ya tienen su
    // propia ficha en el bloque de apartados. Contarlas tambien como
    // "pendientes de pago" seria mostrar la misma deuda dos veces.
    $estadosPagados = ['Pago Validado', 'Preparando', 'Entregado'];

    foreach ($ventas as $v) {
        $resumen['ventas_total']++;

        if (in_array($v['estado'], $estadosPagados, true)) {
            $resumen['ventas_pagadas']++;
            $resumen['compras_usd'] += (float)$v['total_usd'];
            $resumen['ganancia_usd'] += (float)$v['ganancia_usd'];
        } elseif ($v['estado'] === 'Apartado') {
            $resumen['ventas_apartadas']++;
        } else {
            $resumen['ventas_pendientes']++;
        }
    }

    foreach ($apartados as $ap) {
        if ($ap['estado'] === 'Activo') {
            $resumen['apartados_activos']++;
            $resumen['apartados_saldo'] += $ap['monto_pendiente_usd'];
        }
    }

    foreach ($devoluciones as $dev) {
        if ($dev['estado'] === 'ACTIVA') {
            $resumen['devoluciones_activas']++;
            $resumen['devoluciones_usd'] += (float)$dev['monto_devuelto_usd'];
        }
    }

    $resumen['compras_usd'] = round($resumen['compras_usd'], 2);
    $resumen['ganancia_usd'] = round($resumen['ganancia_usd'], 2);
    $resumen['apartados_saldo'] = round($resumen['apartados_saldo'], 2);
    $resumen['devoluciones_usd'] = round($resumen['devoluciones_usd'], 2);

    echo json_encode([
        'success'      => true,
        'cliente'      => $cliente,
        'ventas'       => $ventas,
        'apartados'    => $apartados,
        'devoluciones' => $devoluciones,
        'resumen'      => $resumen,
    ]);
} catch (Exception $e) {
    error_log("get_historial_cliente.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al cargar el historial: ' . $e->getMessage()]);
}