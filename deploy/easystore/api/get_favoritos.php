<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => true, 'logged_in' => false, 'favorited_ids' => [], 'productos' => []]);
    exit;
}

require '../includes/db.php';
$userId = $_SESSION['usuario_id'];

try {
    // Asegurar tabla
    $pdo->exec("CREATE TABLE IF NOT EXISTS favoritos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        producto_id INT NOT NULL,
        fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY cliente_producto (cliente_id, producto_id),
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $pdo->prepare("
        SELECT p.*, f.fecha_agregado 
        FROM productos p 
        INNER JOIN favoritos f ON p.id = f.producto_id 
        WHERE f.cliente_id = :cliente_id 
        ORDER BY f.fecha_agregado DESC
    ");
    $stmt->execute([':cliente_id' => $userId]);
    $productos = $stmt->fetchAll();

    $favoritedIds = array_map(function($p) {
        return intval($p['id']);
    }, $productos);

    echo json_encode([
        'success' => true,
        'logged_in' => true,
        'favorited_ids' => $favoritedIds,
        'productos' => $productos
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener favoritos: ' . $e->getMessage()]);
}
?>
