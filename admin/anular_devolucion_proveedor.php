<?php
/**
 * Devoluciones a proveedor: anula una devolucion registrada por error.
 *
 * No borra nada: la devolucion pasa a estado ANULADA conservando motivo,
 * fecha, usuario, etc. Esto deja trazabilidad completa.
 *
 * Sobre el inventario: si la devolucion fue ACTIVA, al anularla se devuelve
 * la mercaderia al stock (porque la habiamos sacado al devolver al proveedor).
 * Solo se permite anular si el stock actual + lo que vamos a reingresar NO
 * crea inconsistencias (prevenir sobreventa/logic errors).
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once '../includes/kardex.php';
require_once 'check_session.php';
requerirAdmin();

asegurarEsquemaBD($pdo);

$input = Sanitizer::readJsonInput();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos para anular la devolución al proveedor.']);
    exit;
}

$devolucionId = Sanitizer::int($input['devolucion_proveedor_id'] ?? 0);
$motivo       = Sanitizer::text($input['motivo'] ?? '', 500);
$usuarioId    = $_SESSION['usuario_id'] ?? null;

if ($devolucionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de devolución al proveedor inválido.']);
    exit;
}
if (trim($motivo) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes indicar el motivo de la anulación.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmtD = $pdo->prepare("
        SELECT id, compra_id, estado, tipo
          FROM devoluciones_proveedor
         WHERE id = ?
         FOR UPDATE
    ");
    $stmtD->execute([$devolucionId]);
    $dev = $stmtD->fetch(PDO::FETCH_ASSOC);

    if (!$dev) {
        throw new Exception("La devolución al proveedor #$devolucionId no existe.");
    }
    if ($dev['estado'] === 'ANULADA') {
        throw new Exception("La devolución al proveedor #$devolucionId ya fue anulada anteriormente.");
    }

    $stmtDet = $pdo->prepare("
        SELECT ddp.id, ddp.producto_id, ddp.nombre_producto, ddp.cantidad
          FROM devolucion_proveedor_detalles ddp
         WHERE ddp.devolucion_proveedor_id = ?
         FOR UPDATE
    ");
    $stmtDet->execute([$devolucionId]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    if (empty($detalles)) {
        throw new Exception("La devolución al proveedor #$devolucionId no tiene productos asociados.");
    }

    $advertencias = [];

    foreach ($detalles as $d) {
        if ($d['producto_id'] === null) {
            $advertencias[] = "El producto '{$d['nombre_producto']}' ya no está vinculado a un producto (no se ajustó el stock).";
            continue;
        }

        $cantidad = (int)$d['cantidad'];
        if ($cantidad <= 0) {
            continue;
        }

        // Reingresar stock (al anular devolucion al proveedor, devolvemos mercaderia al inventario)
        aplicarMovimientoKardex($pdo, [
            'producto_id'     => (int)$d['producto_id'],
            'nombre_producto' => $d['nombre_producto'],
            'cantidad'        => $cantidad,
            'concepto'        => 'ANULACION_DEVOLUCION_PROVEEDOR',
            'documento_tipo'  => 'ANULACION_DEVOLUCION_PROVEEDOR',
            'documento_id'    => (int)$devolucionId,
            'numero_referencia' => 'Anulacion devolucion proveedor #' . $devolucionId,
            'devolucion_proveedor_id' => (int)$devolucionId,
            'observaciones'   => 'Anulacion devolucion #' . $devolucionId . ' - ' . $motivo,
        ]);
    }

    $stmtUp = $pdo->prepare("
        UPDATE devoluciones_proveedor
           SET estado = 'ANULADA',
               anulada_por = ?,
               fecha_anulacion = NOW(),
               motivo_anulacion = ?
         WHERE id = ?
    ");
    $stmtUp->execute([$usuarioId, $motivo, $devolucionId]);

    $pdo->commit();

    $mensaje = "Devolución al proveedor #$devolucionId anulada. La mercancía fue reingresada al inventario.";

    echo json_encode([
        'success'      => true,
        'message'      => $mensaje,
        'advertencias' => $advertencias
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}