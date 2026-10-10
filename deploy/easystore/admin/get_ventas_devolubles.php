<?php
/**
 * Devoluciones: ventas que aun tienen mercaderia por devolver.
 *
 * Solo entran ventas cuyo stock ya fue descontado, o sea en estado
 * 'Pago Validado', 'Preparando' o 'Entregado'. 'Pendiente Pago' y
 * 'Pago Enviado' quedan fuera porque ahi el cliente todavia no pago.
 *
 * El pendiente de cada linea se calcula restando las devoluciones ACTIVAS,
 * por eso una devolucion anulada vuelve a liberar la mercaderia.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once 'check_session.php';
requerirAdmin();

asegurarEsquemaBD($pdo);

try {
    $busqueda = isset($_GET['buscar']) ? trim((string)$_GET['buscar']) : '';
    $soloConPendiente = !isset($_GET['todas']) || $_GET['todas'] !== '1';

    $sql = "
        SELECT v.id, v.total_usd, v.total_bs, v.tasa_cambio, v.estado, v.fecha_venta,
               v.usuario_id,
               COALESCE(NULLIF(u.nombre_completo, ''), u.usuario) AS cliente_nombre,
               (SELECT COALESCE(SUM(vd.cantidad), 0) FROM venta_detalles vd
                 WHERE vd.venta_id = v.id) AS unidades,
               (SELECT COALESCE(SUM(dd.cantidad), 0) FROM devolucion_detalles dd
                  JOIN devoluciones d ON d.id = dd.devolucion_id
                 WHERE d.venta_id = v.id AND d.estado = 'ACTIVA') AS unidades_devueltas
          FROM ventas v
          LEFT JOIN clientes u ON u.id = v.usuario_id
         WHERE v.estado IN ('Pago Validado', 'Preparando', 'Entregado')
    ";

    $params = [];
    if ($busqueda !== '') {
        $sql .= " AND (v.id = ? OR u.usuario LIKE ? OR u.nombre_completo LIKE ? OR u.cedula LIKE ?)";
        $like = '%' . $busqueda . '%';
        $params[] = (int)$busqueda;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($soloConPendiente) {
        // Solo ventas donde queda al menos una unidad por devolver.
        // Se comparan los dos subqueries en el WHERE en vez de usar HAVING
        // sobre alias: es lo que MySQL resuelve de forma consistente.
        $sql .= "
           AND (SELECT COALESCE(SUM(vd.cantidad), 0) FROM venta_detalles vd
                 WHERE vd.venta_id = v.id)
             > (SELECT COALESCE(SUM(dd.cantidad), 0) FROM devolucion_detalles dd
                  JOIN devoluciones d ON d.id = dd.devolucion_id
                 WHERE d.venta_id = v.id AND d.estado = 'ACTIVA')
        ";
    }

    $sql .= " ORDER BY v.fecha_venta DESC, v.id DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ventas as &$v) {
        $v['id']                = (int)$v['id'];
        // null en ventas de mostrador: no pertenecen a ningun cliente de la lista
        $v['usuario_id']        = $v['usuario_id'] === null ? null : (int)$v['usuario_id'];
        $v['total_usd']         = (float)$v['total_usd'];
        $v['total_bs']          = (float)$v['total_bs'];
        $v['tasa_cambio']       = (float)$v['tasa_cambio'];
        $v['unidades']          = (int)$v['unidades'];
        $v['unidades_devueltas'] = (int)$v['unidades_devueltas'];
        $v['unidades_pendientes'] = max(0, $v['unidades'] - $v['unidades_devueltas']);
    }
    unset($v);

    echo json_encode(['success' => true, 'ventas' => $ventas]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
