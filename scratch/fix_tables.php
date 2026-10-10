<?php
require 'db.php';

try {
    // 1. Borrar tabla duplicada
    $pdo->exec("DROP TABLE IF EXISTS usuario");
    echo "Tabla 'usuario' eliminada.\n";

    // 2. Crear tabla abastecimientos
    $sql = "CREATE TABLE IF NOT EXISTS abastecimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT,
        nombre_producto VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL,
        precio_compra_unitario DECIMAL(10, 2) NOT NULL,
        precio_venta_unitario DECIMAL(10, 2) NOT NULL,
        fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "Tabla 'abastecimientos' creada con éxito.";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
