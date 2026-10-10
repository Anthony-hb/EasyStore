<?php
/**
 * Acumulación por método de pago y verificación de pagos del turno de caja.
 *
 * Usado por admin/get_caja_turno.php (panel en vivo) y por
 * admin/save_caja_cierre.php (cierre), para que ambos calculen SIEMPRE lo
 * mismo. Reglas:
 *
 *  1. El total de cada venta del turno se reparte entre los métodos con los
 *     que realmente se pagó, en proporción al monto registrado en `pagos`
 *     (una venta puede combinarse: 300 Bs por pago móvil + 200 Bs en
 *     efectivo => 60% / 40% del total de la venta).
 *  2. Si la venta no tiene filas en `pagos` (ventas presenciales en efectivo
 *     o punto de venta) se usa `ventas.metodo_pago`.
 *  3. Los abonos a apartados del turno se acumulan en el método con el que
 *     se hicieron. Para no duplicar, los abonos de una venta cuyo total
 *     completo ya está contado en la regla 1 no se suman dos veces: en ese
 *     caso los abonos sirven para repartir el total de la venta entre los
 *     métodos usados.
 *  4. Cada devolución ACTIVA descuenta del método con el que se reembolsó.
 *     Solo la devolución en efectivo toca el saldo físico esperado del
 *     cajón; las demás devuelven dinero de la cuenta bancaria.
 *  5. Verificación: se listan los pagos del turno que siguen en
 *     'pendiente' / 'en_verificacion'. Si quedan, el cierre no se permite
 *     sin confirmación explícita (campo `forzar`).
 */

/**
 * Catálogo de métodos de pago del caja: clave interna => etiqueta y color.
 */
function cajaMetodosDef(): array
{
    return [
        'efectivo'      => ['etiqueta' => 'Efectivo',        'icono' => '💵', 'color' => '#27ae60'],
        'pago_movil'    => ['etiqueta' => 'Pago Móvil',      'icono' => '📱', 'color' => '#1565c0'],
        'transferencia' => ['etiqueta' => 'Transferencia',   'icono' => '🏦', 'color' => '#8e44ad'],
        'zelle'         => ['etiqueta' => 'Zelle',           'icono' => '🌐', 'color' => '#ca6f1e'],
        'biopago'       => ['etiqueta' => 'Biopago',         'icono' => '⚡', 'color' => '#148f77'],
        'punto_venta'   => ['etiqueta' => 'Punto de Venta',  'icono' => '💳', 'color' => '#6b7280'],
        'otros'         => ['etiqueta' => 'Otros',           'icono' => '❓', 'color' => '#94a3b8'],
    ];
}

/**
 * Traduce cualquier texto de método (del enum de `pagos`, de las etiquetas del
 * formulario de venta presencial o de los abonos) a una clave del catálogo.
 */
function cajaNormalizarMetodo($metodo): string
{
    $m = mb_strtolower(trim((string)$metodo), 'UTF-8');
    $m = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $m);

    if ($m === '') {
        return 'efectivo';
    }
    if (strpos($m, 'efectivo') !== false) {
        // El efectivo en bolívares se registra aparte del efectivo en divisas.
        if (strpos($m, 'bs') !== false || strpos($m, 'bolivar') !== false) {
            return 'efectivo_bs';
        }
        return 'efectivo';
    }
    if (strpos($m, 'punto') !== false || strpos($m, 'tarjeta') !== false) {
        return 'punto_venta';
    }
    if (strpos($m, 'pago') !== false && strpos($m, 'movil') !== false) {
        return 'pago_movil';
    }
    if (strpos($m, 'transf') !== false) {
        return 'transferencia';
    }
    if (strpos($m, 'zelle') !== false) {
        return 'zelle';
    }
    if (strpos($m, 'bio') !== false) {
        return 'biopago';
    }
    return 'otros';
}

/**
 * Método para ventas SIN filas en `pagos`.
 *
 * `ventas.metodo_pago` tiene por defecto 'pago_movil': las ventas online no
 * escriben ese campo (su método real vive en `pagos`, por eso llegan aquí sin
 * filas solo si están pendientes de pago y quedan fuera del turno). Por eso
 * ese valor por defecto, sin pagos asociados, se trata como venta en
 * efectivo (ventas presenciales antiguas, que tampoco escribían el campo).
 */
function cajaBucketVentaSinPagos($metodoPagoCrudo): string
{
    if (trim((string)$metodoPagoCrudo) === 'pago_movil') {
        return 'efectivo';
    }
    return cajaNormalizarMetodo($metodoPagoCrudo);
}

/**
 * Devuelve ['bruto', 'abonos', 'reembolsos', 'neto'] con un monto por método.
 *  - bruto:      dinero que entró (ventas + abonos) sin descontar devoluciones.
 *  - abonos:     cuánto de ese bruto vino de abonos a apartados.
 *  - reembolsos: devoluciones hechas por ese método en este turno.
 *  - neto:       bruto - reembolsos (lo que debería reflejar el banco/cajón).
 */
function cajaAcumulacionTurno(PDO $pdo, string $fechaApertura): array
{
    $bruto = $abonos = $reembolsos = [];
    foreach (cajaMetodosDef() as $clave => $_def) {
        $bruto[$clave] = 0.0;
        $abonos[$clave] = 0.0;
        $reembolsos[$clave] = 0.0;
    }

    // El efectivo en bolívares se acumula aparte, en su propia moneda, y no
    // entra al arqueo en dólares del cajón.
    $efectivoBsTotal = 0.0;
    $abonosPorVenta = [];   // venta_id => bucket => ['usd' => x, 'bs' => y]

    // ------------------------------------------------------------------
    // 1) Ventas del turno, repartidas entre los métodos usados
    // ------------------------------------------------------------------
    $stmtVentas = $pdo->prepare("
        SELECT v.id, v.total_usd, v.total_bs, v.metodo_pago,
               pm.metodo_pago AS pm_metodo,
               pm.monto_metodo,
               pt.monto_total
          FROM ventas v
          LEFT JOIN (
                SELECT venta_id, metodo_pago, SUM(monto) AS monto_metodo
                  FROM pagos
                 WHERE estado <> 'rechazado'
                 GROUP BY venta_id, metodo_pago
          ) pm ON pm.venta_id = v.id
          LEFT JOIN (
                SELECT venta_id, SUM(monto) AS monto_total
                  FROM pagos
                 WHERE estado <> 'rechazado'
                 GROUP BY venta_id
          ) pt ON pt.venta_id = v.id
         WHERE v.fecha_venta >= ?
           AND v.estado IN ('Pago Validado', 'Preparando', 'Entregado')
    ");
    $stmtVentas->execute([$fechaApertura]);
    $ventasTurno = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------------
    // 2) Abonos a apartados hechos en este turno
    //    (con los datos de su venta para decidir si ya fueron contados)
    // ------------------------------------------------------------------
    $stmtAbonos = $pdo->prepare("
        SELECT ap.venta_id, v.fecha_venta, v.estado AS venta_estado,
               a.fecha_abono, a.monto_usd, a.monto_bs, a.metodo_pago
          FROM apartado_abonos a
          JOIN apartados ap ON ap.id = a.apartado_id
          LEFT JOIN ventas v ON v.id = ap.venta_id
         WHERE a.fecha_abono >= ?
         ORDER BY a.fecha_abono ASC
    ");
    $stmtAbonos->execute([$fechaApertura]);
    $abonosTurno = $stmtAbonos->fetchAll(PDO::FETCH_ASSOC);

    // Abonos cuya venta NO se cuenta en las reglas de arriba: entraron dinero
    // al cajón y nadie los contó todavía, así que se suman directos.
    // (Si la venta sí se cuenta, su total entra completo y los abonos solo
    //  sirven para repartirlo entre los métodos: no se suman dos veces.)
    $estadosContados = ['Pago Validado', 'Preparando', 'Entregado'];
    foreach ($abonosTurno as $ab) {
        $bucket = cajaNormalizarMetodo($ab['metodo_pago']);
        $esEfectivoBs = ($bucket === 'efectivo_bs');
        if (!$esEfectivoBs && !isset($bruto[$bucket])) {
            $bucket = 'otros';
        }
        $ventaContada = $ab['venta_id'] !== null
            && $ab['fecha_venta'] !== null
            && strtotime($ab['fecha_venta']) >= strtotime($fechaApertura)
            && in_array($ab['venta_estado'], $estadosContados, true);

        if ($ventaContada) {
            $vid = (int)$ab['venta_id'];
            $clave = $esEfectivoBs ? 'efectivo_bs' : $bucket;
            if (!isset($abonosPorVenta[$vid][$clave])) {
                $abonosPorVenta[$vid][$clave] = ['usd' => 0.0, 'bs' => 0.0];
            }
            $abonosPorVenta[$vid][$clave]['usd'] += (float)$ab['monto_usd'];
            $abonosPorVenta[$vid][$clave]['bs']  += (float)$ab['monto_bs'];
        } elseif ($esEfectivoBs) {
            // Efectivo en bolívares que entró al cajón: se registra en Bs.
            $efectivoBsTotal += (float)$ab['monto_bs'];
        } else {
            $abonos[$bucket] += (float)$ab['monto_usd'];
            $bruto[$bucket]  += (float)$ab['monto_usd'];
        }
    }

    // ------------------------------------------------------------------
    // 3) Repartir cada venta entre sus métodos
    // ------------------------------------------------------------------
    foreach ($ventasTurno as $venta) {
        $totalUSD = (float)$venta['total_usd'];
        $totalBs  = (float)$venta['total_bs'];

        if ($venta['pm_metodo'] !== null && (float)$venta['monto_total'] > 0) {
            // Venta con pagos registrados: proporción real de cada método.
            $bucket = cajaNormalizarMetodo($venta['pm_metodo']);
            if (!isset($bruto[$bucket])) {
                $bucket = 'otros';
            }
            $bruto[$bucket] += $totalUSD * ((float)$venta['monto_metodo'] / (float)$venta['monto_total']);
            continue;
        }

        $ventaId = (int)$venta['id'];
        $abonosVenta = $abonosPorVenta[$ventaId] ?? [];

        if (!empty($abonosVenta)) {
            // Apartado pagado con varios métodos: repartir el total de la
            // venta en proporción a cómo se abonó (USD y Bs por separado).
            $sumaUsd = 0.0;
            $sumaBs  = 0.0;
            foreach ($abonosVenta as $m) {
                $sumaUsd += $m['usd'];
                $sumaBs  += $m['bs'];
            }
            foreach ($abonosVenta as $bucket => $m) {
                if ($bucket === 'efectivo_bs') {
                    $efectivoBsTotal += $sumaBs > 0 ? $totalBs * ($m['bs'] / $sumaBs) : 0.0;
                } else {
                    $bruto[$bucket] += $sumaUsd > 0 ? $totalUSD * ($m['usd'] / $sumaUsd) : 0.0;
                }
            }
            continue;
        }

        // Venta sin pagos ni abonos: método declarado en la venta
        // (efectivo, punto de venta, etc.)
        $bucket = cajaBucketVentaSinPagos($venta['metodo_pago']);
        if ($bucket === 'efectivo_bs') {
            $efectivoBsTotal += $totalBs;
            continue;
        }
        if (!isset($bruto[$bucket])) {
            $bucket = 'otros';
        }
        $bruto[$bucket] += $totalUSD;
    }

    // ------------------------------------------------------------------
    // 4) Devoluciones del turno, descontadas del método con que se reembolsó
    // ------------------------------------------------------------------
    $stmtDev = $pdo->prepare("
        SELECT metodo_reembolso, COALESCE(SUM(monto_devuelto_usd), 0) AS monto
          FROM devoluciones
         WHERE estado = 'ACTIVA'
           AND fecha_devolucion >= ?
         GROUP BY metodo_reembolso
    ");
    $stmtDev->execute([$fechaApertura]);
    foreach ($stmtDev as $dev) {
        // 'credito': el dinero aún no salió de ninguna cuenta, no descuenta.
        if ($dev['metodo_reembolso'] === 'credito') {
            continue;
        }
        $bucket = cajaNormalizarMetodo($dev['metodo_reembolso']);
        if ($bucket === 'efectivo_bs') {
            continue; // hoy los reembolsos no se hacen en efectivo Bs
        }
        if (!isset($reembolsos[$bucket])) {
            $bucket = 'otros';
        }
        $reembolsos[$bucket] += (float)$dev['monto'];
    }

    $neto = [];
    foreach ($bruto as $clave => $monto) {
        $neto[$clave] = round($monto - $reembolsos[$clave], 2);
    }

    return [
        'bruto'       => array_map(fn($v) => round($v, 2), $bruto),
        'abonos'      => array_map(fn($v) => round($v, 2), $abonos),
        'reembolsos'  => array_map(fn($v) => round($v, 2), $reembolsos),
        'neto'        => $neto,
        'efectivo_bs' => round($efectivoBsTotal, 2),
    ];
}

/**
 * Pagos del turno que siguen sin resolver (verificación automática).
 * Mientras queden, el cierre de caja exige confirmación explícita.
 */
function cajaPagosPendientesTurno(PDO $pdo, string $fechaApertura): array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.venta_id, p.metodo_pago, p.monto, p.estado,
               p.fecha_pago, p.referencia
          FROM pagos p
         WHERE p.fecha_pago >= ?
           AND p.estado IN ('pendiente', 'en_verificacion')
         ORDER BY p.fecha_pago DESC
    ");
    $stmt->execute([$fechaApertura]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $montoBs = 0.0;
    foreach ($items as $item) {
        $montoBs += (float)$item['monto'];
    }

    return [
        'cantidad' => count($items),
        'monto_bs' => round($montoBs, 2),
        'items'    => array_slice($items, 0, 25),
    ];
}

/**
 * Resumen completo del turno: acumulación por método, saldo esperado en el
 * cajón y verificación de pagos pendientes. Estructura que consume el
 * frontend (admin/admin.js) tal cual.
 */
function cajaResumenTurno(PDO $pdo, array $turno, ?float $tasa = null): array
{
    $fechaApertura = $turno['fecha_apertura'];
    $tasa = ($tasa !== null && $tasa > 0) ? $tasa : 50.00;

    $acumulacion = cajaAcumulacionTurno($pdo, $fechaApertura);
    $pendientes  = cajaPagosPendientesTurno($pdo, $fechaApertura);

    $egresosCajaUSD = 0.0; // la tabla gastos_operativos fue retirada del sistema

    // El cajón solo recibe efectivo: ventas en efectivo (más los abonos en
    // efectivo), menos los reembolsos que salieron en efectivo. No hay fondo
    // inicial: cada turno arranca con el cajón en cero.
    $reembolsosEfectivoUSD = $acumulacion['reembolsos']['efectivo'];
    $totalEsperadoUSD = $acumulacion['bruto']['efectivo']
                      - $egresosCajaUSD
                      - $reembolsosEfectivoUSD;

    // Detalle por método (etiqueta/color para el panel y el modal de cierre)
    $detalle = [];
    foreach (cajaMetodosDef() as $clave => $def) {
        $detalle[$clave] = [
            'etiqueta'   => $def['etiqueta'],
            'icono'      => $def['icono'],
            'color'      => $def['color'],
            'bruto'      => $acumulacion['bruto'][$clave],
            'abonos'     => $acumulacion['abonos'][$clave],
            'reembolsos' => $acumulacion['reembolsos'][$clave],
            'neto'       => $acumulacion['neto'][$clave],
        ];
    }

    return [
        'ventas_efectivo_usd'        => $acumulacion['bruto']['efectivo'],
        'ventas_efectivo_bs'         => $acumulacion['efectivo_bs'],
        'ventas_pago_movil_usd'      => $acumulacion['neto']['pago_movil'],
        'ventas_transferencia_usd'   => $acumulacion['neto']['transferencia'],
        'ventas_zelle_usd'           => $acumulacion['neto']['zelle'],
        'ventas_biopago_usd'         => $acumulacion['neto']['biopago'],
        'ventas_punto_venta_usd'     => $acumulacion['neto']['punto_venta'],
        'ventas_otros_usd'           => $acumulacion['neto']['otros'],
        'abonos_apartados_usd'       => round(array_sum($acumulacion['abonos']), 2),
        'egresos_caja_usd'           => $egresosCajaUSD,
        'reembolsos_efectivo_usd'    => $reembolsosEfectivoUSD,
        'total_esperado_efectivo_usd'=> round($totalEsperadoUSD, 2),
        'total_esperado_efectivo_bs' => round($totalEsperadoUSD * $tasa, 2),
        'acumulacion'                => $detalle,
        'pagos_pendientes'           => $pendientes,
    ];
}
