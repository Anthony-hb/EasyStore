<?php
/**
 * Migración completa: Sistema de pagos + comprobantes
 * http://localhost/tienda/migracion_pagos_completa.php
 */
require_once __DIR__ . '/includes/db.php';

echo "<style>body{font-family:sans-serif;padding:20px;} .ok{color:green;} .err{color:red;} .skip{color:gray;}</style>";
echo "<h1>🔄 Migración Completa: Pagos + Comprobantes</h1>";

try {
    // 1. Modificar ENUM de estado en ventas
    echo "<p>📋 Actualizando estados de ventas...</p>";
    $pdo->exec("ALTER TABLE ventas MODIFY COLUMN estado 
        ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente') 
        NOT NULL DEFAULT 'Pendiente Pago'");
    echo "<p class='ok'>✅ Estados de ventas actualizados</p>";

    // 2. Crear tabla pagos con receipt_path incluido desde el principio
    echo "<p>💳 Creando/actualizando tabla 'pagos'...</p>";
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<p class='ok'>✅ Tabla 'pagos' lista</p>";

    // 3. Añadir receipt_path y correccion_mensaje si ya existía la tabla sin esas columnas
    $cols = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'receipt_path'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN receipt_path VARCHAR(500) NULL AFTER monto");
        echo "<p class='ok'>✅ Columna receipt_path añadida</p>";
    } else {
        echo "<p class='skip'>⏭ Columna receipt_path ya existe</p>";
    }

    $colsCorr = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'correccion_mensaje'")->fetchAll();
    if (empty($colsCorr)) {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN correccion_mensaje TEXT NULL AFTER receipt_path");
        echo "<p class='ok'>✅ Columna correccion_mensaje añadida</p>";
    } else {
        echo "<p class='skip'>⏭ Columna correccion_mensaje ya existe</p>";
    }

    // 4. Insertar configuraciones de pago con las claves correctas (pm_ y transf_)
    echo "<p>⚙️ Insertando configuraciones de pago...</p>";
    $configs = [
        // Pago Móvil
        ['pm_telefono',  '0412-0000000'],
        ['pm_banco',     'Banesco'],
        ['pm_cedula',    'V-00000000'],
        ['pm_titular',   'Nombre del Titular'],
        // Transferencia
        ['transf_banco',   'Banesco'],
        ['transf_cuenta',  '0134-0000-00-0000000000'],
        ['transf_titular', 'Nombre del Titular'],
        ['transf_cedula',  'V-00000000'],
        // Datos del negocio
        ['negocio_horario',   'Estamos abiertos de 8:00 AM hasta las 5:00 PM'],
        ['negocio_ubicacion', 'Tu ubicación aquí'],
        ['negocio_telefono',  '0412-0000000'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO tasa_dolar (clave, valor) VALUES (?, ?)");
    foreach ($configs as $c) {
        $stmt->execute($c);
    }
    echo "<p class='ok'>✅ Configuraciones insertadas</p>";

    // 5. Crear carpeta uploads/comprobantes/
    $dir = __DIR__ . '/uploads/comprobantes/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "<p class='ok'>✅ Carpeta uploads/comprobantes/ creada</p>";
    } else {
        echo "<p class='skip'>⏭ Carpeta uploads/comprobantes/ ya existe</p>";
    }
    // Proteger directorio
    file_put_contents($dir . '.htaccess', "Options -Indexes\n");

    echo "<br><h2 class='ok'>✅ ¡Migración completada exitosamente!</h2>";
    echo "<p><a href='index.html'>→ Ir a la tienda</a> &nbsp;|&nbsp; <a href='admin/index.php'>→ Panel administrativo</a></p>";

} catch (Exception $e) {
    echo "<h2 class='err'>❌ Error:</h2><pre>" . $e->getMessage() . "</pre>";
}
?>
