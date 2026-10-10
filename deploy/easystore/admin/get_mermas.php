<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once 'check_session.php';
requerirAdmin();

asegurarEsquemaBD($pdo);

try {
    // Obtener lista completa de registros de mermas y pérdidas
    $stmt = $pdo->query("
        SELECT 
            m.id,
            m.producto_id,
            p.nombre AS producto_nombre,
            p.imagen AS producto_imagen,
            cd.codigo_producto AS producto_sku,
            m.cantidad,
            m.tipo_salida,
            m.costo_unitario_usd,
            m.costo_total_usd,
            m.motivo,
            m.fecha_registro,
            m.usuario_id,
            m.estado,
            m.fecha_anulacion,
            m.motivo_anulacion,
            COALESCE(a.nombre_completo, a.usuario, 'Administrador') AS registrado_por,
            COALESCE(an.nombre_completo, an.usuario) AS anulada_por_nombre
        FROM mermas_perdidas m
        LEFT JOIN productos p ON m.producto_id = p.id
        LEFT JOIN (
            SELECT cd1.producto_id, cd1.codigo_producto
            FROM compra_detalles cd1
            INNER JOIN (
                SELECT producto_id, MAX(id) AS max_id
                FROM compra_detalles
                GROUP BY producto_id
            ) latest ON cd1.id = latest.max_id
        ) cd ON p.id = cd.producto_id
        LEFT JOIN administrador a ON m.usuario_id = a.id
        LEFT JOIN administrador an ON m.anulada_por = an.id
        ORDER BY m.fecha_registro DESC, m.id DESC
    ");
    $mermas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear tipos de datos
    $totalUSD = 0.00;
    $totalUnidades = 0;
    $anuladasUSD = 0.00;
    $anuladasUnidades = 0;
    $registrosAnulados = 0;
    $desgloseTipos = [
        'DAÑADO' => ['cantidad' => 0, 'usd' => 0.00],
        'ROBADO' => ['cantidad' => 0, 'usd' => 0.00],
        'EXTRAVIADO' => ['cantidad' => 0, 'usd' => 0.00],
        'VENCIDO' => ['cantidad' => 0, 'usd' => 0.00],
        'USO_INTERNO' => ['cantidad' => 0, 'usd' => 0.00]
    ];

    foreach ($mermas as &$m) {
        $m['id'] = (int)$m['id'];
        $m['producto_id'] = (int)$m['producto_id'];
        $m['cantidad'] = (int)$m['cantidad'];
        $m['costo_unitario_usd'] = (float)$m['costo_unitario_usd'];
        $m['costo_total_usd'] = (float)$m['costo_total_usd'];
        $m['producto_sku'] = $m['producto_sku'] ?? '';
        $m['producto_nombre'] = $m['producto_nombre'] ?? '(Producto eliminado)';
        $m['estado'] = $m['estado'] ?? 'ACTIVA';
        $m['anulada_por_nombre'] = $m['anulada_por_nombre'] ?? null;
        $m['motivo_anulacion'] = $m['motivo_anulacion'] ?? '';
        $m['fecha_formateada'] = date('d/m/Y h:i A', strtotime($m['fecha_registro']));
        $m['fecha_anulacion_formateada'] = $m['fecha_anulacion']
            ? date('d/m/Y h:i A', strtotime($m['fecha_anulacion']))
            : null;

        // Las mermas anuladas se conservan como evidencia pero quedan fuera de las
        // pérdidas efectivas: se reportan en sus propios totales.
        if ($m['estado'] === 'ANULADA') {
            $anuladasUSD += $m['costo_total_usd'];
            $anuladasUnidades += $m['cantidad'];
            $registrosAnulados++;
            continue;
        }

        $totalUSD += $m['costo_total_usd'];
        $totalUnidades += $m['cantidad'];

        $tipo = $m['tipo_salida'];
        if (isset($desgloseTipos[$tipo])) {
            $desgloseTipos[$tipo]['cantidad'] += $m['cantidad'];
            $desgloseTipos[$tipo]['usd'] += $m['costo_total_usd'];
        }
    }
    unset($m);

    echo json_encode([
        'success' => true,
        'mermas' => $mermas,
        'stats' => [
            'total_perdidas_usd' => round($totalUSD, 2),
            'total_unidades' => $totalUnidades,
            'total_registros' => count($mermas) - $registrosAnulados,
            'desglose' => $desgloseTipos,
            'total_anuladas_usd' => round($anuladasUSD, 2),
            'total_anuladas_unidades' => $anuladasUnidades,
            'total_registros_anulados' => $registrosAnulados
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
