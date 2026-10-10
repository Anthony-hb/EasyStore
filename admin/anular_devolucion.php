<?php
/**
 * Devoluciones: anula una devolucion registrada por error.
 *
 * No borra nada: la devolucion pasa a estado ANULADA conservando motivo,
 * fecha y usuario, igual que las mermas.
 *
 * Al anular se deshace el reingreso de la mercaderia al inventario, porque la
 * devolucion fue incorrecta y los productos siguen en manos del cliente.
 * Si el stock ya no alcanza (porque esos productos se vendieron de nuevo),
 * NO se anula: seria dejar el inventario en negativo.
 *
 * Sobre la caja: una devolucion anulada deja de descontar del saldo esperado.
 * Si el turno de caja ya estaba cerrado cuando se registro, ese arqueo conserva
 * su numero historico y no se reescribe.
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
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de la anulación.']);
    exit;
}

$devolucionId = Sanitizer::int($input['devolucion_id'] ?? 0);
$motivo       = Sanitizer::text($input['motivo_anulacion'] ?? '', 500);
$usuarioId    = $_SESSION['usuario_id'] ?? null;

if ($devolucionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Devolución no especificada.']);
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
        SELECT id, venta_id, estado, metodo_reembolso, monto_devuelto_usd
          FROM devoluciones
         WHERE id = ?
         FOR UPDATE
    ");
    $stmtD->execute([$devolucionId]);
    $dev = $stmtD->fetch(PDO::FETCH_ASSOC);

    if (!$dev) {
        throw new Exception("La devolución #$devolucionId no existe.");
    }
    if ($dev['estado'] === 'ANULADA') {
        throw new Exception("La devolución #$devolucionId ya fue anulada anteriormente.");
    }

    // Deshacer el reingreso de stock, linea por linea
    $stmtDet = $pdo->prepare("
        SELECT producto_id, nombre_producto, cantidad
          FROM devolucion_detalles
         WHERE devolucion_id = ?
    ");
    $stmtDet->execute([$devolucionId]);
    $detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

    $advertencias = [];
    foreach ($detalles as $d) {
        if ($d['producto_id'] === null) {
            $advertencias[] = "'{$d['nombre_producto']}' ya no está vinculado a un producto, no se descontó stock.";
            continue;
        }

        $cantidad = (int)$d['cantidad'];

        // Stock suficiente: si los productos se vendieron de nuevo, no hay
        // mercaderia en el inventario para devolver y la anulacion no procede.
        try {
            aplicarMovimientoKardex($pdo, [
                'producto_id'     => (int)$d['producto_id'],
                'nombre_producto' => $d['nombre_producto'],
                'cantidad'        => -$cantidad,
                'concepto'        => 'ANULACION_VENTA',
                'documento_tipo'  => 'ANULACION_DEVOLUCION',
                'documento_id'    => (int)$devolucionId,
                'numero_referencia' => 'Anulacion devolucion #' . $devolucionId,
                'devolucion_id'   => (int)$devolucionId,
                'estricto'        => true,
                'observaciones'   => 'Anulacion devolucion #' . $devolucionId . ' - ' . $motivo,
            ]);
        } catch (Exception $e) {
            $stockActual = (int)$pdo->query("SELECT stock FROM productos WHERE id = " . (int)$d['producto_id'])->fetchColumn();
            throw new Exception(
                "No se puede anular: '{$d['nombre_producto']}' tiene stock $stockActual y la devolución reingresó $cantidad und. "
                . "Esos productos ya se vendieron de nuevo. Registra la devolución como correcta y ajusta el inventario a mano."
            );
        }
    }

    $stmtUp = $pdo->prepare("
        UPDATE devoluciones
           SET estado = 'ANULADA',
               anulada_por = ?,
               fecha_anulacion = NOW(),
               motivo_anulacion = ?
         WHERE id = ?
    ");
    $stmtUp->execute([$usuarioId, $motivo, $devolucionId]);

    $pdo->commit();

    $mensaje = "Devolución #$devolucionId anulada. ";
    $mensaje .= $dev['metodo_reembolso'] === 'efectivo'
        ? "El efectivo de \$" . number_format((float)$dev['monto_devuelto_usd'], 2) . " deja de descontarse del saldo esperado de caja."
        : "La mercadería vuelve a descontarse del inventario.";

    echo json_encode([
        'success'      => true,
        'message'      => $mensaje,
        'advertencias' => $advertencias
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
