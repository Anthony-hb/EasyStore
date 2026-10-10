<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS abastecimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT,
        nombre_producto VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL,
        precio_compra_unitario DECIMAL(10, 2) NOT NULL,
        precio_venta_unitario DECIMAL(10, 2) NOT NULL,
        fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $sql = "SELECT * FROM abastecimientos ORDER BY fecha_abastecimiento DESC";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
