<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos del abono.']);
    exit;
}

$apartadoId   = isset($input['apartado_id']) ? (int)$input['apartado_id'] : 0;
$montoUsd     = isset($input['monto_usd']) ? (float)$input['monto_usd'] : 0;
$metodoPago   = trim($input['metodo_pago'] ?? 'efectivo');
$referencia   = trim($input['referencia'] ?? '');
$notas        = trim($input['notas'] ?? '');
$tasaCambio   = isset($input['tasa_cambio']) && (float)$input['tasa_cambio'] > 0 ? (float)$input['tasa_cambio'] : 0;
$adminId      = $_SESSION['admin_id'] ?? ($_SESSION['usuario_id'] ?? null);

if ($apartadoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de apartado inválido.']);
    exit;
}

if ($montoUsd <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El monto del abono debe ser mayor a 0.']);
    exit;
}

try {
    // Si no se proporcionó tasa, buscar la última
    if ($tasaCambio <= 0) {
        $stmtT = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
        $rowT = $stmtT->fetch();
        $tasaCambio = $rowT && (float)$rowT['tasa'] > 0 ? (float)$rowT['tasa'] : 50.00;
    }

    $pdo->beginTransaction();

    // Consultar apartado con bloqueo
    $stmtAp = $pdo->prepare("SELECT * FROM apartados WHERE id = ? FOR UPDATE");
    $stmtAp->execute([$apartadoId]);
    $apartado = $stmtAp->fetch(PDO::FETCH_ASSOC);

    if (!$apartado) {
        throw new Exception("El apartado #{$apartadoId} no existe.");
    }

    if ($apartado['estado'] === 'Cancelado') {
        throw new Exception("No se pueden registrar abonos a un apartado cancelado.");
    }

    $totalUsd = (float)$apartado['total_usd'];
    $abonadoActual = (float)$apartado['monto_abonado_usd'];
    $pendienteActual = (float)$apartado['monto_pendiente_usd'];

    if ($pendienteActual <= 0 && $apartado['estado'] === 'Completado') {
        throw new Exception("Este apartado ya se encuentra 100% pagado.");
    }

    // No permitir abonar más de lo pendiente
    if ($montoUsd > ($pendienteActual + 0.01)) {
        throw new Exception("El monto ($" . number_format($montoUsd, 2) . ") supera el saldo pendiente ($" . number_format($pendienteActual, 2) . ").");
    }

    $montoBs = round($montoUsd * $tasaCambio, 2);
    $nuevoAbonadoUsd = round($abonadoActual + $montoUsd, 2);
    $nuevoPendienteUsd = round(max(0, $totalUsd - $nuevoAbonadoUsd), 2);
    $nuevoAbonadoBs = round((float)$apartado['monto_abonado_bs'] + $montoBs, 2);

    // Insertar abono en apartado_abonos
    $stmtInsAb = $pdo->prepare("
        INSERT INTO apartado_abonos 
        (apartado_id, monto_usd, monto_bs, tasa_cambio, metodo_pago, referencia, usuario_admin_id, notas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsAb->execute([
        $apartadoId,
        $montoUsd,
        $montoBs,
        $tasaCambio,
        $metodoPago,
        $referencia,
        $adminId,
        $notas
    ]);

    // Verificar si se completó el pago del 100%
    $esCompletado = ($nuevoPendienteUsd <= 0.001);
    $nuevoEstado = $esCompletado ? 'Completado' : 'Activo';
    $fechaCompletado = $esCompletado ? date('Y-m-d H:i:s') : null;

    // Actualizar apartado
    $stmtUpdAp = $pdo->prepare("
        UPDATE apartados SET
            monto_abonado_usd = ?,
            monto_abonado_bs = ?,
            monto_pendiente_usd = ?,
            estado = ?,
            fecha_completado = COALESCE(?, fecha_completado)
        WHERE id = ?
    ");
    $stmtUpdAp->execute([
        $nuevoAbonadoUsd,
        $nuevoAbonadoBs,
        $nuevoPendienteUsd,
        $nuevoEstado,
        $fechaCompletado,
        $apartadoId
    ]);

    // Si se completó el pago, actualizar venta para permitir entrega
    if ($esCompletado) {
        $stmtUpdV = $pdo->prepare("UPDATE ventas SET estado = 'Pago Validado' WHERE id = ? AND estado IN ('Apartado', 'Pendiente Pago')");
        $stmtUpdV->execute([$apartado['venta_id']]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $esCompletado 
            ? "¡Excelente! El cliente completó el 100% del pago. El producto ya puede ser entregado/retirado." 
            : "Abono de $" . number_format($montoUsd, 2) . " registrado con éxito. Saldo restante: $" . number_format($nuevoPendienteUsd, 2),
        'completado' => $esCompletado,
        'nuevo_abonado_usd' => $nuevoAbonadoUsd,
        'nuevo_pendiente_usd' => $nuevoPendienteUsd
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
