<?php
/**
 * Obtiene las lineas de una compra y calcula lo pendiente a devolver al proveedor.
 * El pendiente sale de restar las devoluciones ACTIVAS a la cantidad comprada,
 * asi que si una devolucion se anula la mercaderia vuelve a quedar disponible.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

$compraId = (int)($_GET['compra_id'] ?? 0);

if ($compraId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Falta compra_id.']);
    exit;
}

try {
    $stmtC = $pdo->prepare("
        SELECT c.id, c.proveedor_id, c.numero_factura, c.fecha_compra, c.total_usd,
               p.nombre_negocio AS proveedor_nombre,
               p.nombre AS proveedor_contacto_nombre,
               p.apellido AS proveedor_contacto_apellido
          FROM compras c
          LEFT JOIN proveedores p ON p.id = c.proveedor_id
         WHERE c.id = ?
    ");
    $stmtC->execute([$compraId]);
    $compra = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$compra) {
        throw new Exception("La compra #$compraId no existe.");
    }

    $stmtDet = $pdo->prepare("
        SELECT cd.id, cd.producto_id, cd.nombre_producto, cd.cantidad, cd.precio_unitario, cd.subtotal_usd
          FROM compra_detalles cd
         WHERE cd.compra_id = ?
    ");
    $stmtDet->execute([$compraId]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    $stmtPrev = $pdo->prepare("
        SELECT ddp.compra_detalle_id, COALESCE(SUM(ddp.cantidad), 0) AS devueltas
          FROM devolucion_proveedor_detalles ddp
          JOIN devoluciones_proveedor dp ON dp.id = ddp.devolucion_proveedor_id
         WHERE dp.estado = 'ACTIVA'
           AND ddp.compra_detalle_id IN (
                 SELECT cd.id FROM compra_detalles cd WHERE cd.compra_id = ?
               )
      GROUP BY ddp.compra_detalle_id
    ");
    $stmtPrev->execute([$compraId]);
    $prevPorLinea = [];
    while ($row = $stmtPrev->fetch(PDO::FETCH_ASSOC)) {
        $prevPorLinea[(int)$row['compra_detalle_id']] = (int)$row['devueltas'];
    }

    $items = [];

    foreach ($detalles as $det) {
        $cdId     = (int)$det['id'];
        $comprado = (int)$det['cantidad'];
        $devuelto = isset($prevPorLinea[$cdId]) ? $prevPorLinea[$cdId] : 0;
        $pendiente = max(0, $comprado - $devuelto);

        if ($pendiente <= 0) {
            continue;
        }

        $items[] = [
            'compra_detalle_id'       => $cdId,
            'producto_id'             => $det['producto_id'] !== null ? (int)$det['producto_id'] : null,
            'nombre_producto'         => $det['nombre_producto'],
            'comprado'                => $comprado,
            'devuelto_activo'         => $devuelto,
            'pendiente'               => $pendiente,
            'precio_unitario_usd'     => (float)($det['precio_unitario'] ?? 0),
            'precio_unitario'         => (float)($det['precio_unitario'] ?? 0),
            'subtotal_usd'            => (float)($det['subtotal_usd'] ?? 0),
        ];
    }

    echo json_encode([
        'success'  => true,
        'compra'   => $compra,
        'items'    => $items
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}