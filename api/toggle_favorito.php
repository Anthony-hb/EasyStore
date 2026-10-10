<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'logged_in' => false, 'message' => 'Debes iniciar sesión para agregar a favoritos.']);
    exit;
}

require '../includes/db.php';

$userId = $_SESSION['usuario_id'];
$inputJSON = json_decode(file_get_contents('php://input'), true);
$productoId = intval($inputJSON['producto_id'] ?? $_POST['producto_id'] ?? 0);

if ($productoId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de producto no válido.']);
    exit;
}

try {
    // Asegurar que la tabla exista
    $pdo->exec("CREATE TABLE IF NOT EXISTS favoritos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        producto_id INT NOT NULL,
        fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY cliente_producto (cliente_id, producto_id),
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Verificar si ya es favorito
    $stmtCheck = $pdo->prepare("SELECT id FROM favoritos WHERE cliente_id = :cliente_id AND producto_id = :producto_id LIMIT 1");
    $stmtCheck->execute([':cliente_id' => $userId, ':producto_id' => $productoId]);
    $fav = $stmtCheck->fetch();

    if ($fav) {
        // Quitar de favoritos
        $stmtDelete = $pdo->prepare("DELETE FROM favoritos WHERE id = :id");
        $stmtDelete->execute([':id' => $fav['id']]);
        echo json_encode([
            'success' => true,
            'is_favorito' => false,
            'message' => 'Producto eliminado de favoritos'
        ]);
    } else {
        // Agregar a favoritos
        $stmtInsert = $pdo->prepare("INSERT INTO favoritos (cliente_id, producto_id) VALUES (:cliente_id, :producto_id)");
        $stmtInsert->execute([':cliente_id' => $userId, ':producto_id' => $productoId]);
        echo json_encode([
            'success' => true,
            'is_favorito' => true,
            'message' => '¡Producto agregado a tus favoritos! ⭐'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error al procesar favoritos: ' . $e->getMessage()]);
}
?>
