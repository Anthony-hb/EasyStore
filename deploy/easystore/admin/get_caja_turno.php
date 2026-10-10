<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once '../includes/caja.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

try {
    asegurarEsquemaBD($pdo);
} catch (Exception $e) {
    // Continuar si ya existen las tablas
}

try {
    // 1. Obtener la tasa del dólar actual
    $stmtTasa = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
    $tasaActual = (float)($stmtTasa->fetchColumn() ?: 50.00);

    // 2. Buscar turno activo (ABIERTA)
    $stmtAct = $pdo->query("SELECT * FROM cajas_turnos WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
    $turnoActivo = $stmtAct->fetch(PDO::FETCH_ASSOC);

    // Resumen en vivo: acumulación por método de pago + saldo esperado del
    // cajón + pagos del turno que siguen sin revisar (verificación).
    $resumenEnVivo = $turnoActivo ? cajaResumenTurno($pdo, $turnoActivo, $tasaActual) : null;

    // 3. Historial de cierres de caja anteriores
    $stmtHist = $pdo->query("
        SELECT c.*, a.usuario as admin_usuario 
        FROM cajas_turnos c 
        LEFT JOIN administrador a ON c.usuario_admin_id = a.id 
        ORDER BY c.id DESC LIMIT 50
    ");
    $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    ob_clean();
    echo json_encode([
        'success' => true,
        'tasa_cambio' => $tasaActual,
        'turno_activo' => $turnoActivo,
        'resumen_en_vivo' => $resumenEnVivo,
        'historial' => $historial
    ]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
