<?php
require_once 'includes/db.php';
try {
    $pdo->exec("DROP TABLE IF EXISTS compra_detalles, compras, proveedores, devolucion_proveedor_detalles, devoluciones_proveedor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_negocio VARCHAR(255) NOT NULL,
        nombre VARCHAR(100),
        apellido VARCHAR(100),
        documento_identidad VARCHAR(50),
        email VARCHAR(100),
        telefono VARCHAR(20),
        direccion VARCHAR(255),
        banco VARCHAR(100),
        cuenta_bancaria VARCHAR(100),
        telefono_pago_movil VARCHAR(20),
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT NOT NULL,
        numero_factura VARCHAR(100) NULL,
        fecha_factura DATE NULL,
        moneda ENUM('USD','VES') DEFAULT 'USD',
        impuestos_pct DECIMAL(5,2) DEFAULT 0.00,
        descuento_total DECIMAL(10,2) DEFAULT 0.00,
        total_usd DECIMAL(10,2) NOT NULL,
        total_bs DECIMAL(10,2) NOT NULL,
        tasa_cambio DECIMAL(10,2) NOT NULL,
        fecha_compra TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_recepcion DATETIME NULL,
        estado ENUM('Pendiente','Parcial','Recibido','Anulado') NOT NULL DEFAULT 'Pendiente',
        usuario_admin_id INT NULL,
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS compra_detalles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        compra_id INT NOT NULL,
        producto_id INT NOT NULL,
        codigo_producto VARCHAR(100),
        nombre_producto VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL,
        precio_unitario DECIMAL(10,2) NOT NULL,
        subtotal_usd DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "ok";
} catch (Exception $e) {
    echo $e->getMessage();
}