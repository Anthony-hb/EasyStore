<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../includes/db.php';
require_once '../includes/schema.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

try {
    asegurarEsquemaBD($pdo);
} catch (Exception $e) {}

$input = json_decode(file_get_contents('php://input'), true);

// Acepta cuerpos vacios ("{}") -> el array vacio vale como dato valido.
if ($input === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos de apertura no recibidos.']);
    exit;
}

$observaciones = trim($input['observaciones'] ?? 'Apertura de turno diario');

// Obtener tasa actual del dólar
$tasaCambio = 50.00;
try {
    $stmtTasa = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
    $rowT = $stmtTasa->fetch(PDO::FETCH_ASSOC);
    if ($rowT && floatval($rowT['tasa']) > 0) {
        $tasaCambio = floatval($rowT['tasa']);
    }
} catch (Exception $e) {}

$usuarioAdminId = $_SESSION['usuario_id'] ?? null;
try {
    // Verificar que no haya una caja ya abierta
    $stmtCheck = $pdo->query("SELECT id FROM cajas_turnos WHERE estado = 'ABIERTA' LIMIT 1");
    if ($stmtCheck->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ya existe un turno de caja abierto. Debes cerrarlo antes de abrir uno nuevo.']);
        exit;
    }

    $stmtIns = $pdo->prepare("INSERT INTO cajas_turnos 
        (usuario_admin_id, tasa_cambio, estado, observaciones) 
        VALUES (?, ?, 'ABIERTA', ?)");
    $stmtIns->execute([
        $usuarioAdminId,
        $tasaCambio,
        $observaciones
    ]);
    $turnoId = $pdo->lastInsertId();

    ob_clean();
    echo json_encode([
        'success' => true, 
        'message' => 'Caja abierta exitosamente. Turno iniciado.',
        'turno_id' => $turnoId
    ]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
