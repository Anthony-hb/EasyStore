<?php
/**
 * Ajuste manual de inventario (Kardex).
 *
 * Es la unica via para corregir un saldo que no proviene de una operacion real
 * (conteo fisico que no cuadra, error de carga inicial, donacion, etc.).
 * El ajuste queda registrado como movimiento AJUSTE_INVENTARIO con su motivo.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/kardex.php';
require_once 'check_session.php';
requerirAdmin();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos del ajuste.']);
    exit;
}

$productoId = (int)($input['producto_id'] ?? 0);
$cantidad   = (int)($input['cantidad'] ?? 0);
$motivo     = trim((string)($input['motivo'] ?? ''));

if ($productoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Selecciona el producto a ajustar.']);
    exit;
}

if ($cantidad === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La cantidad del ajuste debe ser distinta de cero (positiva suma, negativa resta).']);
    exit;
}

if (mb_strlen($motivo) < 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Indica el motivo del ajuste (mínimo 5 caracteres).']);
    exit;
}

try {
    $pdo->beginTransaction();

    $infoProducto = $pdo->query("SELECT nombre, stock FROM productos WHERE id = " . $productoId)->fetch(PDO::FETCH_ASSOC);
    if (!$infoProducto) {
        throw new Exception('El producto seleccionado ya no existe en el catálogo.');
    }
    $stockAnterior = (int)$infoProducto['stock'];

    if ($cantidad < 0 && $stockAnterior + $cantidad < 0) {
        throw new Exception(
            "No se puede restar " . abs($cantidad) . " unidad(es): '" . $infoProducto['nombre']
            . "' solo tiene $stockAnterior en inventario."
        );
    }

    $kardexId = registrarAjusteKardex($pdo, $productoId, $cantidad, $motivo);

    if ($kardexId === null) {
        throw new Exception('El producto seleccionado ya no existe en el catálogo.');
    }

    $stockNuevo = (int)$pdo->query("SELECT stock FROM productos WHERE id = " . $productoId)->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'kardex_id'      => $kardexId,
        'stock_anterior' => $stockAnterior,
        'stock_nuevo'    => $stockNuevo,
        'mensaje'        => 'Ajuste registrado. El stock de "' . $infoProducto['nombre'] . '" pasó de '
                            . $stockAnterior . ' a ' . $stockNuevo . ' unidad(es).',
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error save_ajuste_kardex.php: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}