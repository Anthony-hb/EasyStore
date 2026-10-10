<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    // 1. Estadísticas de Ventas (Ingresos, Costos, Ganancias)
    // Solo tomamos en cuenta ventas en estado válido ('Pago Validado', 'Preparando', 'Entregado')
    $estadosValidos = "'Pago Validado', 'Preparando', 'Entregado'";

    $ingresos = (float)$pdo->query("SELECT COALESCE(SUM(total_usd), 0) FROM ventas WHERE estado IN ($estadosValidos)")->fetchColumn();
    // Ventas netas (sin IVA) e IVA recaudado. En ventas viejas sin desglose
    // (base_gravada_usd = 0) se usa el total como base, igual que antes.
    $ingresosNetos = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN base_gravada_usd > 0 THEN base_gravada_usd ELSE total_usd END), 0) FROM ventas WHERE estado IN ($estadosValidos)")->fetchColumn();
    $ivaRecaudado = (float)$pdo->query("SELECT COALESCE(SUM(iva_usd), 0) FROM ventas WHERE estado IN ($estadosValidos)")->fetchColumn();
    $costos = (float)$pdo->query("SELECT COALESCE(SUM(vd.cantidad * vd.costo_unitario_usd), 0) FROM venta_detalles vd JOIN ventas v ON vd.venta_id = v.id WHERE v.estado IN ($estadosValidos)")->fetchColumn();
    $numVentas = (int)$pdo->query("SELECT COUNT(*) FROM ventas WHERE estado IN ($estadosValidos)")->fetchColumn();

    // Si no hay ventas validadas aún, permitimos calcular sobre todas las ventas que no estén anuladas
    if ($ingresos == 0) {
        $ingresos = (float)$pdo->query("SELECT COALESCE(SUM(total_usd), 0) FROM ventas WHERE estado != 'Anulado'")->fetchColumn();
        $ingresosNetos = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN base_gravada_usd > 0 THEN base_gravada_usd ELSE total_usd END), 0) FROM ventas WHERE estado != 'Anulado'")->fetchColumn();
        $ivaRecaudado = (float)$pdo->query("SELECT COALESCE(SUM(iva_usd), 0) FROM ventas WHERE estado != 'Anulado'")->fetchColumn();
        $costos = (float)$pdo->query("SELECT COALESCE(SUM(vd.cantidad * vd.costo_unitario_usd), 0) FROM venta_detalles vd JOIN ventas v ON vd.venta_id = v.id WHERE v.estado != 'Anulado'")->fetchColumn();
        $numVentas = (int)$pdo->query("SELECT COUNT(*) FROM ventas WHERE estado != 'Anulado'")->fetchColumn();
    }

    // La ganancia se calcula sobre ventas NETAS: el IVA cobrado no es ingreso,
    // es un pasivo. El costo ya viene neto desde compras.
    $ganancia = $ingresosNetos - $costos;
    $margen = $ingresosNetos > 0 ? ($ganancia / $ingresosNetos) * 100 : 0;

    // 2. Alertas de Stock (Solo productos publicados)
    $sqlAlertas = "SELECT COUNT(*) as total_alertas FROM productos WHERE publicado = 1 AND stock_minimo > 0 AND stock <= stock_minimo";
    $stmtAlertas = $pdo->query($sqlAlertas);
    $totalAlertas = $stmtAlertas->fetchColumn();

    // 3. Valor del Inventario (suma stock * precio_usd de todos los productos con stock > 0)
    $sqlInv = "SELECT COALESCE(SUM(stock * precio_usd), 0) as valor_inventario FROM productos WHERE stock > 0";
    $stmtInv = $pdo->query($sqlInv);
    $valorInventario = round((float)$stmtInv->fetchColumn(), 2);

    // 4. Pérdidas activas (módulo Pérdidas: solo registros no anulados)
    $pTot = $pdo->query("SELECT COALESCE(SUM(costo_total_usd),0), COALESCE(SUM(cantidad),0), COUNT(*) FROM mermas_perdidas WHERE estado = 'ACTIVA'")->fetch(PDO::FETCH_NUM);
    $perdidasUsd        = round((float)$pTot[0], 2);
    $perdidasUnidades   = (int)$pTot[1];
    $perdidasRegistros  = (int)$pTot[2];

    // 5. Conteos generales
    $numClientes  = (int)$pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
    $numCompras   = (int)$pdo->query("SELECT COUNT(*) FROM compras WHERE estado = 'Recibido'")->fetchColumn();
    $numProductos = (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE publicado = 1")->fetchColumn();

    // 6. Ingresos de los últimos 7 días (para la gráfica de barras)
    $desde = date('Y-m-d', strtotime('-6 days'));
    $stmtSemana = $pdo->prepare("SELECT DATE(fecha_venta) AS dia, SUM(total_usd) AS ingresos, COUNT(*) AS ventas
                                 FROM ventas WHERE estado IN ($estadosValidos) AND fecha_venta >= ?
                                 GROUP BY DATE(fecha_venta)");
    $stmtSemana->execute([$desde . ' 00:00:00']);
    $porDia = [];
    foreach ($stmtSemana->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $porDia[$fila['dia']] = $fila;
    }
    $etiquetasDias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $ventasSemana = [];
    for ($i = 6; $i >= 0; $i--) {
        $dia = date('Y-m-d', strtotime("-$i days"));
        $fila = $porDia[$dia] ?? null;
        $ventasSemana[] = [
            'dia'      => $dia,
            'label'    => $etiquetasDias[(int)date('w', strtotime($dia))],
            'ingresos' => round((float)($fila['ingresos'] ?? 0), 2),
            'ventas'   => (int)($fila['ventas'] ?? 0),
        ];
    }

    // 7. Top 5 productos más vendidos
    $stmtTop = $pdo->query("SELECT vd.nombre_producto AS nombre,
                                   SUM(vd.cantidad) AS unidades,
                                   SUM(vd.subtotal_usd) AS ventas_usd
                            FROM venta_detalles vd
                            JOIN ventas v ON vd.venta_id = v.id
                            WHERE v.estado IN ($estadosValidos)
                            GROUP BY vd.nombre_producto
                            ORDER BY unidades DESC
                            LIMIT 5");
    $topProductos = [];
    foreach ($stmtTop->fetchAll(PDO::FETCH_ASSOC) as $tp) {
        $topProductos[] = [
            'nombre'    => $tp['nombre'],
            'unidades'  => (int)$tp['unidades'],
            'ventas_usd'=> round((float)$tp['ventas_usd'], 2),
        ];
    }

    // 8. Pérdidas por tipo (activas), ordenadas por valor
    $etiquetasTipos = [
        'DAÑADO'      => 'Dañado',
        'ROBADO'      => 'Robado',
        'EXTRAVIADO'  => 'Extraviado',
        'VENCIDO'     => 'Vencido',
        'USO_INTERNO' => 'Uso Interno',
    ];
    $stmtTipos = $pdo->query("SELECT tipo_salida, SUM(cantidad) AS unidades, SUM(costo_total_usd) AS valor
                              FROM mermas_perdidas WHERE estado = 'ACTIVA'
                              GROUP BY tipo_salida ORDER BY valor DESC");
    $perdidasPorTipo = [];
    foreach ($stmtTipos->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $perdidasPorTipo[] = [
            'tipo'     => $t['tipo_salida'],
            'etiqueta' => $etiquetasTipos[$t['tipo_salida']] ?? $t['tipo_salida'],
            'unidades' => (int)$t['unidades'],
            'valor'    => round((float)$t['valor'], 2),
        ];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'ingresos'            => $ingresos,
            'ingresos_netos'      => $ingresosNetos,
            'iva_recaudado'       => $ivaRecaudado,
            'costos'              => $costos,
            'ganancia'            => $ganancia,
            'margen'              => round($margen, 2),
            'num_ventas'          => $numVentas,
            'alertas'             => (int)$totalAlertas,
            'valor_inventario'    => $valorInventario,
            'perdidas_usd'        => $perdidasUsd,
            'perdidas_unidades'   => $perdidasUnidades,
            'perdidas_registros'  => $perdidasRegistros,
            'clientes'            => $numClientes,
            'compras'             => $numCompras,
            'productos'           => $numProductos,
            'ventas_semana'       => $ventasSemana,
            'top_productos'       => $topProductos,
            'perdidas_por_tipo'   => $perdidasPorTipo,
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>