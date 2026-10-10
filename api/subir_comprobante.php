<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

function valorEnumDisponible(PDO $pdo, string $tabla, string $columna, string $valor): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tabla` LIKE ?");
    $stmt->execute([$columna]);
    $col = $stmt->fetch();
    if (!$col) {
        return false;
    }
    $type = $col['type'] ?? $col['Type'] ?? '';
    if (!preg_match("/^enum\\('(.*)'\\)$/", $type, $matches)) {
        return false;
    }
    $values = array_map(fn($v) => str_replace("''", "'", $v), explode("','", $matches[1]));
    return in_array($valor, $values, true);
}

function agregarReceiptPathSiFalta(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'receipt_path'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE pagos ADD COLUMN receipt_path VARCHAR(500) NULL AFTER monto");
        }
    } catch (Exception $e) {
        // Ignorar.
    }
}

function agregarCorreccionMensajeSiFalta(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'correccion_mensaje'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE pagos ADD COLUMN correccion_mensaje TEXT NULL AFTER receipt_path");
        }
    } catch (Exception $e) {
        // Ignorar.
    }
}

function asegurarEstadosVentas(PDO $pdo): void {
    try {
        if (!valorEnumDisponible($pdo, 'ventas', 'estado', 'Pago Enviado')) {
            $pdo->exec("ALTER TABLE ventas MODIFY COLUMN estado ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente') NOT NULL DEFAULT 'Pendiente Pago'");
        }
    } catch (Exception $e) {
        // Ignorar.
    }
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $request = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $request = $_POST;
}

if (!isset($request['venta_id'])) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
    exit;
}

$ventaIdRaw = $request['venta_id'] ?? null;
$ventaId = filter_var($ventaIdRaw, FILTER_VALIDATE_INT);
$usuarioId  = (int)($_SESSION['usuario_id'] ?? 0);
$metodo     = trim($request['metodo_pago'] ?? '');
$banco      = trim($request['banco_origen'] ?? '');
$telefono   = trim($request['telefono_origen'] ?? '');
if ($telefono !== '') {
    $telefono = preg_replace('/\D/', '', $telefono); // Sanitizar eliminando no dígitos
}
$referencia = trim($request['referencia'] ?? '');
$montoRaw   = $request['monto'] ?? 0;
$monto      = is_numeric($montoRaw) ? (float)$montoRaw : 0.0;

if ($ventaId === false || $ventaId === false || $ventaId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de venta inválido']);
    exit;
}

if (!is_numeric($montoRaw) || $monto < 0) {
    echo json_encode(['success' => false, 'message' => 'El monto debe ser un número válido']);
    exit;
}

if ($metodo !== '' && !in_array($metodo, ['pago_movil', 'transferencia', 'zelle', 'biopago'], true)) {
    echo json_encode(['success' => false, 'message' => 'Método de pago inválido']);
    exit;
}

if ($banco !== '' && (strlen($banco) > 80 || !preg_match('/^[\pL0-9\s().+-]{2,80}$/u', $banco))) {
    echo json_encode(['success' => false, 'message' => 'El banco de origen contiene caracteres no válidos']);
    exit;
}

if ($referencia !== '' && (strlen($referencia) > 50 || !preg_match('/^[\pL0-9\s().+-]{1,50}$/u', $referencia))) {
    echo json_encode(['success' => false, 'message' => 'La referencia de pago contiene caracteres no válidos']);
    exit;
}

if ($telefono !== '' && !preg_match('/^[\pL0-9\s().+-]{4,20}$/u', $telefono)) {
    echo json_encode(['success' => false, 'message' => 'El teléfono de origen contiene caracteres no válidos']);
    exit;
}

if (!$usuarioId) {
    echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión']);
    exit;
}

$hasFile = isset($_FILES['comprobante']) && is_array($_FILES['comprobante']) && $_FILES['comprobante']['error'] !== UPLOAD_ERR_NO_FILE;
$receiptPath = null;

try {
    asegurarEstadosVentas($pdo);
    agregarReceiptPathSiFalta($pdo);
    agregarCorreccionMensajeSiFalta($pdo);

    $stmt = $pdo->prepare("SELECT id, estado, total_bs FROM ventas WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$ventaId, $usuarioId]);
    $venta = $stmt->fetch();

    if (!$venta) {
        echo json_encode(['success' => false, 'message' => 'Venta no encontrada o no pertenece a este usuario']);
        exit;
    }

    $estadoVenta = trim((string)($venta['estado'] ?? ''));
    if ($estadoVenta === '') {
        $estadoVenta = 'Pendiente Pago';
    }

    $validStates = ['pendiente pago', 'pago enviado', 'pendiente', 'corrección solicitada', 'apartado'];
    if (!in_array(mb_strtolower($estadoVenta, 'UTF-8'), $validStates, true)) {
        echo json_encode(['success' => false, 'message' => 'Esta venta ya fue procesada', 'estado' => $venta['estado']]);
        exit;
    }

    if ($hasFile) {
        $file = $_FILES['comprobante'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
            exit;
        }

        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Formato no permitido. Usa PDF, JPG o PNG.']);
            exit;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'El archivo no puede superar 5 MB']);
            exit;
        }

        $dir = __DIR__ . '/../uploads/comprobantes/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = "comprobante_{$ventaId}_" . time() . ".{$ext}";
        $destino  = $dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            echo json_encode(['success' => false, 'message' => 'No se pudo guardar el archivo en el servidor']);
            exit;
        }

        $receiptPath = "uploads/comprobantes/{$filename}";
    }

    $stmtPago = $pdo->prepare("SELECT * FROM pagos WHERE venta_id = ? ORDER BY id DESC LIMIT 1");
    $stmtPago->execute([$ventaId]);
    $pagoExistente = $stmtPago->fetch(PDO::FETCH_ASSOC);

    $metodo = $metodo ?: ($pagoExistente['metodo_pago'] ?? '');
    $banco = $banco ?: ($pagoExistente['banco_origen'] ?? '');
    $telefono = $telefono ?: ($pagoExistente['telefono_origen'] ?? '');
    $referencia = $referencia ?: ($pagoExistente['referencia'] ?? '');
    $monto = $monto > 0 ? $monto : (float)($pagoExistente['monto'] ?? 0);

    // Validar y aplicar fallbacks si no hay pago existente (ej. subido directo desde Mis Pedidos)
    if (!$pagoExistente) {
        if ($metodo === '' && $referencia === '' && $monto <= 0 && $hasFile) {
            $metodo = 'pago_movil';
            $banco = 'No especificado (Subido desde Mis Pedidos)';
            $referencia = 'Captura Subida';
            $monto = (float)($venta['total_bs'] ?? 0);
        }

        // Si después de los fallbacks algún campo obligatorio sigue vacío, asignar valores seguros
        if ($metodo === '') {
            $metodo = 'pago_movil';
        }
        if ($banco === '') {
            $banco = 'Pago Móvil';
        }
        if ($referencia === '') {
            $referencia = 'Captura Subida';
        }
        if ($monto <= 0) {
            $monto = (float)($venta['total_bs'] ?? 0);
        }

        if ($metodo === '' || $banco === '' || $referencia === '' || $monto <= 0 || strlen($banco) > 80 || strlen($referencia) > 50 || ($telefono !== '' && !preg_match('/^\d{7,15}$/', $telefono))) {
            echo json_encode(['success' => false, 'message' => 'Completa todos los datos de pago antes de enviar.']);
            exit;
        }
    }

    $pdo->beginTransaction();

    if (!$pagoExistente) {
        $stmtInsert = $pdo->prepare("INSERT INTO pagos (venta_id, metodo_pago, banco_origen, telefono_origen, referencia, monto, receipt_path, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')");
        $stmtInsert->execute([$ventaId, $metodo, $banco, $telefono, $referencia, $monto, $receiptPath]);
    } else {
        $params = [$metodo, $banco, $telefono, $referencia, $monto];
        $query = "UPDATE pagos SET metodo_pago = ?, banco_origen = ?, telefono_origen = ?, referencia = ?, monto = ?, estado = 'pendiente'";

        if ($receiptPath !== null) {
            $query .= ", receipt_path = ?";
            $params[] = $receiptPath;
            // Al subir un nuevo comprobante limpiamos cualquier mensaje de corrección
            $query .= ", correccion_mensaje = NULL";
        }

        $query .= " WHERE id = ?";
        $params[] = $pagoExistente['id'];
        $stmtUpdate = $pdo->prepare($query);
        $stmtUpdate->execute($params);
    }

    // Actualizar el estado de la venta a 'Pago Enviado' (el stock se descontará únicamente cuando el administrador valide el pago)
    $stmtUpdateVenta = $pdo->prepare("UPDATE ventas SET estado = 'Pago Enviado' WHERE id = ?");
    $stmtUpdateVenta->execute([$ventaId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '✅ Tu pago fue enviado. El administrador revisará tu información.'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($destino) && file_exists($destino)) {
        unlink($destino);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
