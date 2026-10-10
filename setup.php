<?php
// Configuración de conexión inicial (sin DB)
$host = 'localhost';
$user = 'root';
$pass = '';

echo "<h1>🛠️ Instalador Automático de Base de Datos</h1>";

try {
    // 1. Conexión inicial para crear la DB
    $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<p>🔄 Creando base de datos 'tienda'...</p>";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS tienda CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE tienda");

    // 2. Crear Tabla Productos
    echo "<p>📦 Creando tabla 'productos'...</p>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(255) NOT NULL,
        descripcion TEXT NOT NULL,
        precio_usd DECIMAL(10,2) NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        stock_minimo INT DEFAULT 0,
        destacado TINYINT(1) DEFAULT 0,
        categoria VARCHAR(100) NOT NULL,
        subcategoria VARCHAR(100),
        marca VARCHAR(100) DEFAULT NULL,
        imagen TEXT NOT NULL,
        imagenes TEXT,
        ficha_tecnica TEXT,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 3. Crear tablas de administrador y clientes
    echo "<p>👥 Creando tablas 'administrador' y 'clientes'...</p>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS administrador (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        nombre_completo VARCHAR(100),
        email VARCHAR(100),
        telefono VARCHAR(20),
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        negocio_nombre VARCHAR(255),
        negocio_telefono VARCHAR(50),
        negocio_horario VARCHAR(255),
        negocio_ubicacion VARCHAR(255),
        pm_banco VARCHAR(100),
        pm_telefono VARCHAR(50),
        pm_cedula VARCHAR(50),
        transf_banco VARCHAR(100),
        transf_cuenta VARCHAR(100),
        transf_titular VARCHAR(150),
        transf_cedula VARCHAR(50)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        nombre_completo VARCHAR(100),
        email VARCHAR(100),
        telefono VARCHAR(20),
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 4. Crear Tabla Tasa del Dólar (Historial de actualizaciones)
    echo "<p>⚙️ Creando tabla 'tasa_dolar'...</p>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasa_dolar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tasa DECIMAL(10,2) NOT NULL,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 5. Crear Tablas de Proveedores y Compras
    echo "<p>📦 Creando tablas de proveedores y compras...</p>";
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
        tipo_producto VARCHAR(100) DEFAULT NULL,
        marca_producto VARCHAR(255) DEFAULT NULL,
        fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proveedor_id INT NOT NULL,
        total_usd DECIMAL(10,2) NOT NULL,
        total_bs DECIMAL(10,2) NOT NULL,
        tasa_cambio DECIMAL(10,2) NOT NULL,
        fecha_compra TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        estado ENUM('Pendiente', 'Recibido', 'Anulado') NOT NULL DEFAULT 'Pendiente',
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compra_detalles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        compra_id INT NOT NULL,
        producto_id INT NOT NULL,
        cantidad INT NOT NULL,
        precio_unitario_usd DECIMAL(10,2) NOT NULL,
        subtotal_usd DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    // 6. Crear Tablas de Ventas
    echo "<p>🛒 Creando tablas de 'ventas'...</p>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS ventas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        total_usd DECIMAL(10,2) NOT NULL,
        total_bs DECIMAL(10,2) NOT NULL,
        tasa_cambio DECIMAL(10,2) NOT NULL,
        usuario_id INT,
        fecha_venta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        estado ENUM('Pendiente', 'Preparando', 'Entregado') NOT NULL DEFAULT 'Pendiente',
        FOREIGN KEY (usuario_id) REFERENCES clientes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS venta_detalles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        producto_id INT NOT NULL,
        nombre_producto VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL,
        precio_unitario_usd DECIMAL(10,2) NOT NULL,
        costo_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        subtotal_usd DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        metodo_pago ENUM('pago_movil', 'transferencia') NOT NULL,
        banco_origen VARCHAR(100) NOT NULL,
        telefono_origen VARCHAR(20),
        referencia VARCHAR(100) NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        receipt_path VARCHAR(500) NULL,
        correccion_mensaje TEXT NULL,
        fecha_pago TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        estado ENUM('pendiente', 'en_verificacion', 'validado', 'rechazado') DEFAULT 'en_verificacion',
        FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS abastecimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT,
        nombre_producto VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL,
        precio_compra_unitario DECIMAL(10,2) NOT NULL,
        precio_venta_unitario DECIMAL(10,2) NOT NULL,
        fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // 5. Insertar Datos por Defecto
    echo "<p>🌱 Insertando datos iniciales...</p>";

    // La cuenta de administrador ahora se crea desde admin_setup.php

    // Tasa Dolar
    $pdo->exec("INSERT INTO tasa_dolar (tasa) VALUES (50.00)");

    // Productos de ejemplo
    $stmt = $pdo->prepare("INSERT IGNORE INTO productos (nombre, descripcion, precio_usd, stock, stock_minimo, destacado, categoria, subcategoria, imagen, imagenes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute(['Smartphone Galaxy S23', 'El último grito de la tecnología móvil.', 850.00, 15, 3, 1, 'Celulares', 'Samsung', 'https://images.unsplash.com/photo-1610945265064-0e34e5519bbf', '[]']);
    $stmt->execute(['Teclado Mecánico Pro', 'Switches rojos para máxima velocidad.', 85.00, 25, 5, 0, 'Accesorios', 'Periféricos', 'https://images.unsplash.com/photo-1511467687858-23d96c32e4ae', '[]']);

    echo "<h2 style='color:green;'>✅ ¡Todo listo! La base de datos ha sido configurada correctamente.</h2>";
    echo "<p>Ya puedes borrar este archivo.</p>";
    echo "<a href='admin_setup.php' style='display:inline-block; margin-top:20px; padding:10px 20px; background:#4361ee; color:white; text-decoration:none; border-radius:10px; font-weight:bold;'>Configurar Administrador Inicial</a>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>❌ Error durante la instalación:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<p>Asegúrate de que XAMPP (MySQL) esté encendido.</p>";
}
?>
