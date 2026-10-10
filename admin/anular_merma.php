<?php
/**
 * Anulación de mermas / salidas no operativas.
 *
 * Revierte por completo una merma registrada:
 *   - Devuelve las unidades al stock del producto
 *   - Registra el movimiento inverso en el Kardex
 *   - Genera el asiento contable de reversión (revierte el gasto por merma)
 *   - Marca la merma como ANULADA conservando el registro original como evidencia
 *
 * La merma NO se borra: queda en estado ANULADA con el motivo y el usuario que la anuló.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once '../includes/kardex.php';
// modulo de contabilidad retirado: no se carga includes/contabilidad.php
require_once 'check_session.php';
requerirAdmin();

asegurarEsquemaBD($pdo);

$input = Sanitizer::readJsonInput();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de la anulación.']);
    exit;
}

$mermaId = Sanitizer::int($input['merma_id'] ?? 0);
$motivoAnulacion = Sanitizer::text($input['motivo_anulacion'] ?? '', 500);
$usuarioId = $_SESSION['usuario_id'] ?? null;

if ($mermaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La pérdida indicada no es válida.']);
    exit;
}

if ($motivoAnulacion === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes indicar el motivo de la anulación.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Bloquear la merma para evitar anulaciones simultáneas del mismo registro
    $stmtM = $pdo->prepare("SELECT id, producto_id, cantidad, costo_unitario_usd, costo_total_usd, estado
                            FROM mermas_perdidas WHERE id = ? FOR UPDATE");
    $stmtM->execute([$mermaId]);
    $merma = $stmtM->fetch(PDO::FETCH_ASSOC);

    if (!$merma) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'El registro de pérdida no existe.']);
        exit;
    }

    if ($merma['estado'] === 'ANULADA') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Esta pérdida ya fue anulada anteriormente.']);
        exit;
    }

    // Bloquear el producto y devolver las unidades
    $stmtP = $pdo->prepare("SELECT id, nombre, stock, precio_compra FROM productos WHERE id = ? FOR UPDATE");
    $stmtP->execute([$merma['producto_id']]);
    $producto = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        throw new Exception("El producto de la pérdida ya no existe en el catálogo, no se puede devolver el stock.");
    }

    $cantidad = (int)$merma['cantidad'];
    $costoUnitario = (float)$merma['costo_unitario_usd'];
    $costoTotalUSD = round((float)$merma['costo_total_usd'], 2);

    // 1. Devolver el stock y asentar el movimiento inverso en el Kardex.
    $advertencias = [];

    if (aplicarMovimientoKardex($pdo, [
        'producto_id'      => (int)$merma['producto_id'],
        'nombre_producto'  => $producto['nombre'],
        'cantidad'         => $cantidad,
        'concepto'         => 'ANULACION_MERMA',
        'documento_tipo'   => 'MERMA_ANULADA',
        'documento_id'     => (int)$mermaId,
        'numero_referencia' => 'Anulación de pérdida #' . $mermaId,
        'costo_unitario_usd' => $costoUnitario,
        'merma_id'         => (int)$mermaId,
        'usuario_id'       => $usuarioId,
        'observaciones'    => 'Anulación de la pérdida #' . $mermaId . ($motivoAnulacion !== '' ? ' - ' . $motivoAnulacion : ''),
    ]) === null) {
        $advertencias[] = "El producto ya no existe en el catálogo; la anulación se registró sin ajuste de inventario.";
    }

    $stockRestaurado = (int)$pdo->query("SELECT stock FROM productos WHERE id = " . (int)$merma['producto_id'])->fetchColumn();

    // 2. Marcar la merma como anulada (sin borrarla: queda la evidencia)
    $stmtA = $pdo->prepare("UPDATE mermas_perdidas
        SET estado = 'ANULADA',
            anulada_por = ?,
            fecha_anulacion = NOW(),
            motivo_anulacion = ?
        WHERE id = ?");
    $stmtA->execute([$usuarioId, $motivoAnulacion, $mermaId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Pérdida #{$mermaId} anulada. Se devolvieron $cantidad unidad(es) al inventario (stock actual: $stockRestaurado).",
        'stock_restaurado' => $stockRestaurado,
        'unidades_devueltas' => $cantidad,
        'costo_revertido_usd' => $costoTotalUSD,
        'advertencias' => $advertencias
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}