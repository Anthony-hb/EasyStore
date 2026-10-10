<?php
require_once 'includes/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS favoritos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        producto_id INT NOT NULL,
        fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY cliente_producto (cliente_id, producto_id),
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "Tabla 'favoritos' verificada/creada correctamente.\n";
} catch (PDOException $e) {
    echo "Error creando tabla favoritos: " . $e->getMessage() . "\n";
}
?>
