<?php
/**
 * Devuelve las compras con mercaderia pendiente de devolucion al proveedor.
 * El pendiente de cada linea se calcula restando las devoluciones ACTIVAS,
 * por eso una devolucion anulada vuelve a liberar la mercaderia.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

$buscar = trim((string)($_GET['buscar'] ?? ''));

try {
    $sql = "
        SELECT c.id,
               c.proveedor_id,
               p.nombre_negocio AS proveedor_nombre,
               p.nombre AS proveedor_contacto_nombre,
               p.apellido AS proveedor_contacto_apellido,
               DATE_FORMAT(c.fecha_compra, '%Y-%m-%d %H:%i:%s') AS fecha_compra,
               c.numero_factura,
               c.total_usd,
               c.estado,
               (
                 SELECT COUNT(*)
                   FROM compra_detalles cd
                  WHERE cd.compra_id = c.id
               ) AS lineas,
               (
                 SELECT COALESCE(SUM(cd.cantidad), 0)
                   FROM compra_detalles cd
                  WHERE cd.compra_id = c.id
               ) AS total_und_vendidas,
               (
                 SELECT COALESCE(SUM(ddp.cantidad), 0)
                   FROM devolucion_proveedor_detalles ddp
                   JOIN devoluciones_proveedor dp ON dp.id = ddp.devolucion_proveedor_id
                  WHERE dp.estado = 'ACTIVA' AND ddp.compra_detalle_id IN (
                    SELECT cd2.id FROM compra_detalles cd2 WHERE cd2.compra_id = c.id
                  )
               ) AS total_und_devuelta
          FROM compras c
          LEFT JOIN proveedores p ON p.id = c.proveedor_id
         WHERE 1=1
    ";

    $params = [];

    if ($buscar !== '') {
        $sql .= " AND (c.id LIKE ? OR c.numero_factura LIKE ? OR p.nombre_negocio LIKE ? OR CONCAT(p.nombre, ' ', p.apellido) LIKE ?)";
        $needle = "%{$buscar}%";
        $params = [$needle, $needle, $needle, $needle];
    }

    $sql .= "
           ORDER BY c.fecha_compra DESC
           LIMIT 150
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];

    foreach ($compras as $c) {
        $vendidas  = (int)($c['total_und_vendidas'] ?? 0);
        $devueltas = (int)($c['total_und_devuelta'] ?? 0);
        $pendiente = max(0, $vendidas - $devueltas);

        if ($pendiente <= 0) {
            continue;
        }

        $resultado[] = [
            'id'                        => (int)$c['id'],
            'proveedor_id'              => $c['proveedor_id'] !== null ? (int)$c['proveedor_id'] : null,
            'proveedor_nombre'          => $c['proveedor_nombre'] ?? '',
            'proveedor_contacto_nombre' => $c['proveedor_contacto_nombre'] ?? '',
            'proveedor_contacto_apellido'=> $c['proveedor_contacto_apellido'] ?? '',
            'fecha_compra'              => $c['fecha_compra'],
            'numero_factura'            => $c['numero_factura'] ?? '',
            'total_usd'                 => (float)($c['total_usd'] ?? 0),
            'estado'                    => $c['estado'] ?? '',
            'lineas'                    => (int)($c['lineas'] ?? 0),
            'total_und_vendidas'        => $vendidas,
            'total_und_devuelta'        => $devueltas,
            'pendiente_devolver'        => $pendiente,
        ];
    }

    echo json_encode(['success' => true, 'compras' => $resultado]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}