<?php
/**
 * Kardex de Inventario: listado de movimientos con filtros y resumen.
 *
 * Filtros: producto_id, desde, hasta, concepto, tipo (ENTRADA/SALIDA),
 *          busqueda (texto libre), limite.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/kardex.php';
require_once 'check_session.php';
requerirAdmin();

$esPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$entrada = $esPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;

$productoId = filter_var($entrada['producto_id'] ?? null, FILTER_VALIDATE_INT);
$desde      = trim((string)($entrada['desde'] ?? ''));
$hasta      = trim((string)($entrada['hasta'] ?? ''));
$concepto   = strtoupper(trim((string)($entrada['concepto'] ?? '')));
$tipo       = strtoupper(trim((string)($entrada['tipo'] ?? '')));
$busqueda   = trim((string)($entrada['busqueda'] ?? ''));
$limite     = (int)($entrada['limite'] ?? 500);
if ($limite <= 0 || $limite > 2000) {
    $limite = 500;
}

$where  = [];
$params = [];

if ($productoId !== false && $productoId > 0) {
    $where[] = 'k.producto_id = :producto_id';
    $params[':producto_id'] = $productoId;
}

if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    $where[] = 'DATE(k.fecha_movimiento) >= :desde';
    $params[':desde'] = $desde;
}

if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    $where[] = 'DATE(k.fecha_movimiento) <= :hasta';
    $params[':hasta'] = $hasta;
}

if ($concepto !== '' && array_key_exists($concepto, kardexConceptos())) {
    $where[] = 'k.concepto = :concepto';
    $params[':concepto'] = $concepto;
}

if ($tipo === 'ENTRADA' || $tipo === 'SALIDA') {
    $where[] = 'k.tipo_movimiento = :tipo';
    $params[':tipo'] = $tipo;
}

if ($busqueda !== '') {
    $where[] = '(k.nombre_producto LIKE :busqueda OR k.numero_referencia LIKE :busqueda
                OR k.observaciones LIKE :busqueda OR k.documento_tipo LIKE :busqueda)';
    $params[':busqueda'] = '%' . $busqueda . '%';
}

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $stmt = $pdo->prepare("SELECT k.id, k.producto_id, k.nombre_producto, k.fecha_movimiento,
                                  k.tipo_movimiento, k.concepto,
                                  k.documento_tipo, k.documento_id, k.numero_referencia,
                                  k.cantidad_entrada, k.cantidad_salida,
                                  k.stock_anterior, k.stock_nuevo,
                                  k.costo_unitario_usd, k.costo_total_usd,
                                  k.compra_id, k.venta_id, k.merma_id,
                                  k.devolucion_id, k.devolucion_proveedor_id,
                                  k.usuario_id, k.observaciones,
                                  COALESCE(a.nombre_completo, a.usuario, '') AS usuario_nombre
                           FROM kardex_inventario k
                           LEFT JOIN administrador a ON a.id = k.usuario_id
                           $sqlWhere
                           ORDER BY k.fecha_movimiento DESC, k.id DESC
                           LIMIT $limite");
    $stmt->execute($params);
    $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($movimientos as &$m) {
        $m['id']                = (int)$m['id'];
        $m['producto_id']       = $m['producto_id'] !== null ? (int)$m['producto_id'] : null;
        $m['cantidad_entrada']  = (int)$m['cantidad_entrada'];
        $m['cantidad_salida']   = (int)$m['cantidad_salida'];
        $m['stock_anterior']    = (int)$m['stock_anterior'];
        $m['stock_nuevo']       = (int)$m['stock_nuevo'];
        $m['costo_unitario_usd'] = (float)$m['costo_unitario_usd'];
        $m['costo_total_usd']   = (float)$m['costo_total_usd'];
        $m['compra_id']                = $m['compra_id'] !== null ? (int)$m['compra_id'] : null;
        $m['venta_id']                 = $m['venta_id'] !== null ? (int)$m['venta_id'] : null;
        $m['merma_id']                 = $m['merma_id'] !== null ? (int)$m['merma_id'] : null;
        $m['devolucion_id']            = $m['devolucion_id'] !== null ? (int)$m['devolucion_id'] : null;
        $m['devolucion_proveedor_id']  = $m['devolucion_proveedor_id'] !== null ? (int)$m['devolucion_proveedor_id'] : null;
        $m['usuario_id']               = $m['usuario_id'] !== null ? (int)$m['usuario_id'] : null;
        $m['concepto_texto'] = kardexConceptos()[$m['concepto']] ?? $m['concepto'];
    }
    unset($m);

    $resumen = kardexResumen($pdo, [
        'producto_id' => ($productoId !== false && $productoId > 0) ? (int)$productoId : 0,
        'desde'       => $desde,
        'hasta'       => $hasta,
    ]);

    echo json_encode([
        'success' => true,
        'total'   => count($movimientos),
        'data'    => $movimientos,
        'resumen' => $resumen,
        'limite'  => $limite,
    ]);
} catch (PDOException $e) {
    error_log('Error get_kardex.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener el Kardex de inventario.']);
}