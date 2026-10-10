<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';

$usuarioId = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
if ($usuarioId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Debes iniciar sesión para reportar un abono.']);
    exit;
}

try {
    // Si viene como multipart/form-data o JSON
    $apartadoId = isset($_POST['apartado_id']) ? (int)$_POST['apartado_id'] : 0;
    $montoUsd   = isset($_POST['monto_usd']) ? (float)$_POST['monto_usd'] : 0;
    $metodoPago = trim($_POST['metodo_pago'] ?? 'pago_movil');
    $referencia = trim($_POST['referencia'] ?? '');
    $notas      = trim($_POST['notas'] ?? '');

    if ($apartadoId <= 0) {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            $apartadoId = (int)($data['apartado_id'] ?? 0);
            $montoUsd   = (float)($data['monto_usd'] ?? 0);
            $metodoPago = trim($data['metodo_pago'] ?? 'pago_movil');
            $referencia = trim($data['referencia'] ?? '');
            $notas      = trim($data['notas'] ?? '');
        }
    }

    if ($apartadoId <= 0 || $montoUsd <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de apartado o monto inválido.']);
        exit;
    }

    if (empty($referencia)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Indica el número de referencia del pago.']);
        exit;
    }

    // Procesar comprobante si se sube archivo
    $comprobantePath = null;
    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $fileType = mime_content_type($_FILES['comprobante']['tmp_name']);
        if (in_array($fileType, $allowed)) {
            $ext = pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION);
            $fileName = 'abono_' . $apartadoId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
            $uploadDir = '../uploads/comprobantes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $uploadDir . $fileName)) {
                $comprobantePath = 'uploads/comprobantes/' . $fileName;
            }
        }
    }

    // Tasa BCV
    $stmtT = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
    $rowT = $stmtT->fetch();
    $tasaCambio = $rowT && (float)$rowT['tasa'] > 0 ? (float)$rowT['tasa'] : 50.00;

    $pdo->beginTransaction();

    // Validar que el apartado pertenece al usuario autenticado
    $stmtAp = $pdo->prepare("SELECT * FROM apartados WHERE id = ? AND usuario_id = ? FOR UPDATE");
    $stmtAp->execute([$apartadoId, $usuarioId]);
    $apartado = $stmtAp->fetch(PDO::FETCH_ASSOC);

    if (!$apartado) {
        throw new Exception("El apartado no existe o no pertenece a tu cuenta.");
    }

    if ($apartado['estado'] === 'Cancelado') {
        throw new Exception("Este apartado se encuentra cancelado.");
    }

    $totalUsd = (float)$apartado['total_usd'];
    $abonadoActual = (float)$apartado['monto_abonado_usd'];
    $pendienteActual = (float)$apartado['monto_pendiente_usd'];

    if ($montoUsd > ($pendienteActual + 0.01)) {
        throw new Exception("El monto reportado ($" . number_format($montoUsd, 2) . ") excede el saldo pendiente ($" . number_format($pendienteActual, 2) . ").");
    }

    $montoBs = round($montoUsd * $tasaCambio, 2);
    $nuevoAbonadoUsd = round($abonadoActual + $montoUsd, 2);
    $nuevoPendienteUsd = round(max(0, $totalUsd - $nuevoAbonadoUsd), 2);
    $nuevoAbonadoBs = round((float)$apartado['monto_abonado_bs'] + $montoBs, 2);

    // Insertar abono
    $stmtInsAb = $pdo->prepare("
        INSERT INTO apartado_abonos 
        (apartado_id, monto_usd, monto_bs, tasa_cambio, metodo_pago, referencia, comprobante_img, notas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtInsAb->execute([
        $apartadoId,
        $montoUsd,
        $montoBs,
        $tasaCambio,
        $metodoPago,
        $referencia,
        $comprobantePath,
        $notas
    ]);

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

    if ($esCompletado) {
        $stmtUpdV = $pdo->prepare("UPDATE ventas SET estado = 'Pago Validado' WHERE id = ? AND estado IN ('Apartado', 'Pendiente Pago')");
        $stmtUpdV->execute([$apartado['venta_id']]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $esCompletado
            ? "¡Felicidades! Has completado el 100% del pago de tu apartado. Ya puedes pasar a retirar tu producto."
            : "¡Abono registrado con éxito! Tu nuevo saldo pendiente es de $" . number_format($nuevoPendienteUsd, 2) . " USD.",
        'completado' => $esCompletado,
        'nuevo_pendiente_usd' => $nuevoPendienteUsd
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
