<?php
/**
 * Devoluciones: lineas de una venta con cuanto queda por devolver.
 *
 * El pendiente sale de restar las devoluciones ACTIVAS a la cantidad vendida,
 * asi que si una devolucion se anula la mercaderia vuelve a quedar disponible.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once 'check_session.php';
requerirAdmin();

asegurarEsquemaBD($pdo);

$ventaId = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
if ($ventaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Venta no especificada.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT v.id, v.total_usd, v.total_bs, v.tasa_cambio, v.estado, v.fecha_venta,
               COALESCE(NULLIF(u.nombre_completo, ''), u.usuario) AS cliente_nombre
          FROM ventas v
          LEFT JOIN clientes u ON u.id = v.usuario_id
         WHERE v.id = ?
    ");
    $stmt->execute([$ventaId]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'La venta no existe.']);
        exit;
    }

    if (!in_array($venta['estado'], ['Pago Validado', 'Preparando', 'Entregado'], true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => "Esta venta está en estado '{$venta['estado']}'. Solo se pueden devolver ventas con pago validado, en preparación o entregadas."
        ]);
        exit;
    }

    $stmtDet = $pdo->prepare("
        SELECT vd.id, vd.producto_id, vd.nombre_producto, vd.cantidad,
               vd.precio_unitario_usd, vd.subtotal_usd,
               COALESCE((
                   SELECT SUM(dd.cantidad)
                     FROM devolucion_detalles dd
                     JOIN devoluciones d ON d.id = dd.devolucion_id
                    WHERE dd.venta_detalle_id = vd.id AND d.estado = 'ACTIVA'
               ), 0) AS ya_devuelto,
               COALESCE(p.stock, -1) AS stock_actual
          FROM venta_detalles vd
          LEFT JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = ?
         ORDER BY vd.id ASC
    ");
    $stmtDet->execute([$ventaId]);
    $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    $hayPendiente = false;
    foreach ($items as &$it) {
        $it['id']                   = (int)$it['id'];
        $it['cantidad']             = (int)$it['cantidad'];
        $it['ya_devuelto']          = (int)$it['ya_devuelto'];
        $it['pendiente']            = max(0, $it['cantidad'] - $it['ya_devuelto']);
        $it['precio_unitario_usd']  = (float)$it['precio_unitario_usd'];
        $it['subtotal_usd']         = (float)$it['subtotal_usd'];
        $it['stock_actual']         = (int)$it['stock_actual'];
        $it['devuelto_totalmente']  = ($it['pendiente'] === 0);
        if ($it['pendiente'] > 0) {
            $hayPendiente = true;
        }
    }
    unset($it);

    echo json_encode([
        'success'       => true,
        'venta'         => [
            'id'            => (int)$venta['id'],
            'total_usd'     => (float)$venta['total_usd'],
            'total_bs'      => (float)$venta['total_bs'],
            'tasa_cambio'   => (float)$venta['tasa_cambio'],
            'estado'        => $venta['estado'],
            'fecha_venta'   => $venta['fecha_venta'],
            'cliente_nombre' => $venta['cliente_nombre'],
        ],
        'items'         => $items,
        'hay_pendiente' => $hayPendiente
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
