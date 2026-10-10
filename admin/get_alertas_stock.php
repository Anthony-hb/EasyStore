<?php
session_start();
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    // Obtener productos donde el stock actual sea menor o igual al stock mínimo,
    // y excluyendo aquellos donde el stock mínimo no se haya configurado (ej: 0) si se prefiere,
    // pero según la petición: "si el dueño al publicar el producto puso como stock minimo 5, cuando le queden 5 disponibles..."
    // asumo que si stock_minimo es > 0 y stock <= stock_minimo, entonces es alerta.
    // MODIFICACION: Solo tomamos en cuenta los productos publicados (publicado = 1)
    $stmt = $pdo->prepare("SELECT id, nombre, stock, stock_minimo FROM productos WHERE publicado = 1 AND stock_minimo > 0 AND stock <= stock_minimo ORDER BY stock ASC");
    $stmt->execute();
    $alertas = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'alertas' => $alertas,
        'total' => count($alertas)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
