<?php
/**
 * Migración: Creación y verificación de la tabla 'abastecimientos'
 * Se puede ejecutar vía CLI o accediendo desde el navegador: http://localhost/tienda/update_abastecimientos_schema.php
 */
require_once __DIR__ . '/includes/db.php';

try {
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
    echo "✅ Tabla 'abastecimientos' creada/verificada correctamente.\n";
} catch (PDOException $e) {
    echo "❌ Error al crear la tabla 'abastecimientos': " . $e->getMessage() . "\n";
}
?>
