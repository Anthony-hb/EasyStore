<?php
/**
 * Libro de IVA (control interno).
 *
 * Devuelve el libro de ventas (IVA débito fiscal), el libro de compras
 * (IVA crédito fiscal) y el cuadro resumen débito/crédito para un rango
 * de fechas. No emite factura fiscal ni numero de control.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

function fechaValidaLibro($valor, $defecto) {
    $valor = trim((string)$valor);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return $valor;
    }
    return $defecto;
}

$hoy = date('Y-m-d');
$desde = fechaValidaLibro($_GET['desde'] ?? '', date('Y-m-01'));
$hasta = fechaValidaLibro($_GET['hasta'] ?? '', $hoy);
if ($desde > $hasta) {
    // Si el usuario invierte el rango, se corrige para no devolver vacio.
    $tmp = $desde;
    $desde = $hasta;
    $hasta = $tmp;
}

try {
    // ---- Libro de ventas (IVA debito fiscal) ----
    $stmtVentas = $pdo->prepare("
        SELECT v.id,
               v.fecha_venta,
               v.cliente_nombre,
               v.metodo_pago,
               v.estado,
               v.total_usd,
               v.base_gravada_usd,
               v.iva_usd,
               v.igtf_usd
        FROM ventas v
        WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta
          AND v.estado <> 'Cancelado'
        ORDER BY v.fecha_venta ASC, v.id ASC
    ");
    $stmtVentas->execute([':desde' => $desde, ':hasta' => $hasta]);
    $ventasRaw = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);

    $ventas = [];
    $baseVentas = 0.0;
    $ivaDebito = 0.0;
    $igtfVentas = 0.0;
    $totalVentas = 0.0;
    foreach ($ventasRaw as $v) {
        $base = (float)$v['base_gravada_usd'];
        $iva = (float)$v['iva_usd'];
        $igtf = (float)$v['igtf_usd'];
        $total = (float)$v['total_usd'];

        $baseVentas += $base;
        $ivaDebito += $iva;
        $igtfVentas += $igtf;
        $totalVentas += $total;

        $ventas[] = [
            'id' => (int)$v['id'],
            'fecha' => $v['fecha_venta'],
            'cliente' => $v['cliente_nombre'] ?: 'Cliente Presencial',
            'metodo_pago' => $v['metodo_pago'],
            'estado' => $v['estado'],
            'base' => round($base, 2),
            'iva' => round($iva, 2),
            'igtf' => round($igtf, 2),
            'total' => round($total, 2),
            'desglosado' => ($base > 0 || $iva > 0),
        ];
    }

    // ---- Ventas por alicuota (base gravada e IVA por tarifa) ----
    $stmtAlic = $pdo->prepare("
        SELECT vd.iva_alicuota,
               SUM(vd.base_usd) AS base,
               SUM(vd.iva_usd)  AS iva,
               SUM(vd.subtotal_usd) AS total
        FROM venta_detalles vd
        JOIN ventas v ON v.id = vd.venta_id
        WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta
          AND v.estado <> 'Cancelado'
        GROUP BY vd.iva_alicuota
        ORDER BY vd.iva_alicuota DESC
    ");
    $stmtAlic->execute([':desde' => $desde, ':hasta' => $hasta]);
    $porAlicuota = array_map(function ($r) {
        return [
            'iva_alicuota' => (float)$r['iva_alicuota'],
            'base' => round((float)$r['base'], 2),
            'iva' => round((float)$r['iva'], 2),
            'total' => round((float)$r['total'], 2),
        ];
    }, $stmtAlic->fetchAll(PDO::FETCH_ASSOC));

    // ---- Libro de compras (IVA credito fiscal) ----
    $stmtCompras = $pdo->prepare("
        SELECT c.id,
               c.fecha_compra,
               c.numero_factura,
               c.total_usd,
               c.base_usd,
               c.iva_credito_usd,
               c.retencion_iva_usd,
               c.igtf_usd,
               c.estado,
               COALESCE(p.nombre, 'Sin proveedor') AS proveedor
        FROM compras c
        LEFT JOIN proveedores p ON p.id = c.proveedor_id
        WHERE DATE(c.fecha_compra) BETWEEN :desde AND :hasta
          AND c.estado NOT IN ('Anulado', 'Cancelado')
        ORDER BY c.fecha_compra ASC, c.id ASC
    ");
    $stmtCompras->execute([':desde' => $desde, ':hasta' => $hasta]);
    $comprasRaw = $stmtCompras->fetchAll(PDO::FETCH_ASSOC);

    $compras = [];
    $baseCompras = 0.0;
    $ivaCredito = 0.0;
    $retencionIva = 0.0;
    $igtfCompras = 0.0;
    $totalCompras = 0.0;
    foreach ($comprasRaw as $c) {
        $base = (float)$c['base_usd'];
        $iva = (float)$c['iva_credito_usd'];
        $ret = (float)$c['retencion_iva_usd'];
        $igtf = (float)$c['igtf_usd'];
        $total = (float)$c['total_usd'];

        $baseCompras += $base;
        $ivaCredito += $iva;
        $retencionIva += $ret;
        $igtfCompras += $igtf;
        $totalCompras += $total;

        $compras[] = [
            'id' => (int)$c['id'],
            'fecha' => $c['fecha_compra'],
            'proveedor' => $c['proveedor'],
            'numero_factura' => $c['numero_factura'] ?: '',
            'base' => round($base, 2),
            'iva_credito' => round($iva, 2),
            'retencion_iva' => round($ret, 2),
            'igtf' => round($igtf, 2),
            'total' => round($total, 2),
        ];
    }

    $ivaNeto = round($ivaDebito - $ivaCredito, 2);

    $resumen = [
        'base_ventas' => round($baseVentas, 2),
        'iva_debito' => round($ivaDebito, 2),
        'igtf_ventas' => round($igtfVentas, 2),
        'total_ventas' => round($totalVentas, 2),
        'base_compras' => round($baseCompras, 2),
        'iva_credito' => round($ivaCredito, 2),
        'retencion_iva' => round($retencionIva, 2),
        'igtf_compras' => round($igtfCompras, 2),
        'total_compras' => round($totalCompras, 2),
        'iva_neto' => $ivaNeto,
        'iva_a_pagar' => $ivaNeto > 0 ? $ivaNeto : 0,
        'credito_favor' => $ivaNeto < 0 ? abs($ivaNeto) : 0,
        'igtf_total' => round($igtfVentas + $igtfCompras, 2),
    ];

    echo json_encode([
        'success' => true,
        'desde' => $desde,
        'hasta' => $hasta,
        'resumen' => $resumen,
        'ventas_por_alicuota' => $porAlicuota,
        'libro_ventas' => $ventas,
        'libro_compras' => $compras,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al generar el libro de IVA: ' . $e->getMessage()]);
}
