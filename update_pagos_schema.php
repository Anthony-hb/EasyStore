<?php
/**
 * Migración: Sistema de Flujo de Pago con Verificación
 * Ejecutar vía navegador: http://localhost/tienda/update_pagos_schema.php
 */
$host = 'localhost';
$user = 'root';
$pass = '';

echo "<h1>🔄 Migración: Sistema de Pagos</h1>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=tienda;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Modificar ENUM de estado en ventas
    echo "<p>📋 Actualizando estados de ventas...</p>";
    $pdo->exec("ALTER TABLE ventas MODIFY COLUMN estado ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente') NOT NULL DEFAULT 'Pendiente Pago'");
    echo "<p style='color:green;'>✅ Estados de ventas actualizados</p>";

    // 2. Crear tabla de pagos
    echo "<p>💳 Creando tabla 'pagos'...</p>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venta_id INT NOT NULL,
        metodo_pago ENUM('pago_movil', 'transferencia') NOT NULL,
        banco_origen VARCHAR(100) NOT NULL,
        telefono_origen VARCHAR(20),
        referencia VARCHAR(100) NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        fecha_pago TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        estado ENUM('pendiente', 'en_verificacion', 'validado', 'rechazado') DEFAULT 'en_verificacion',
        FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<p style='color:green;'>✅ Tabla 'pagos' creada</p>";

    // 3. Insertar configuraciones de pago por defecto
    echo "<p>⚙️ Insertando configuraciones de pago...</p>";
    $configs = [
        // Pago Móvil
        ['pago_movil_telefono', '0412-0000000'],
        ['pago_movil_banco', 'Banesco'],
        ['pago_movil_cedula', 'V-00000000'],
        ['pago_movil_titular', 'Nombre del Titular'],
        // Transferencia
        ['transferencia_banco', 'Banesco'],
        ['transferencia_cuenta', '0134-0000-00-0000000000'],
        ['transferencia_titular', 'Nombre del Titular'],
        ['transferencia_cedula', 'V-00000000'],
        // Datos del negocio
        ['negocio_horario', 'Estamos abiertos de 8:00 AM hasta las 5:00 PM'],
        ['negocio_ubicacion', 'Ubicados en el sector La Represa, al lado de Unicasa'],
        ['negocio_telefono', '0412-0000000'],
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO tasa_dolar (clave, valor) VALUES (?, ?)");
    foreach ($configs as $c) {
        $stmt->execute($c);
    }
    echo "<p style='color:green;'>✅ Configuraciones de pago insertadas</p>";

    echo "<h2 style='color:green;'>✅ ¡Migración completada exitosamente!</h2>";
    echo "<p><a href='index.html'>Ir a la tienda</a> | <a href='admin/index.php'>Ir al panel admin</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>❌ Error:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>
