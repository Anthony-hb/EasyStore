<?php
/**
 * Migración: Nuevos métodos de pago (Zelle y Biopago)
 * Ejecutar vía navegador: http://localhost/tienda/actualizar_metodos_pago_schema.php
 *
 * - Agrega columnas de configuración de Zelle / Biopago a la tabla administrador.
 * - Agrega columnas de resumen por método a la tabla cajas_turnos.
 * - Amplía el ENUM metodo_pago de la tabla pagos con 'zelle' y 'biopago'.
 */
$host = 'localhost';
$user = 'root';
$pass = '';

echo "<h1>🔄 Migración: Zelle + Biopago</h1>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=tienda;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Configuración de Zelle / Biopago en la tabla administrador
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'administrador'")->fetchAll(PDO::FETCH_COLUMN);
    $nuevas = [
        'zelle_correo'    => 'VARCHAR(120) DEFAULT NULL',
        'zelle_titular'   => 'VARCHAR(150) DEFAULT NULL',
        'biopago_banco'   => 'VARCHAR(100) DEFAULT NULL',
        'biopago_cedula'  => 'VARCHAR(50) DEFAULT NULL',
        'biopago_titular' => 'VARCHAR(150) DEFAULT NULL',
    ];
    foreach ($nuevas as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE administrador ADD COLUMN $col $def");
            echo "<p style='color:green;'>✅ Columna administrador.$col agregada</p>";
        }
    }

    // 2. Resumen por método en cajas_turnos
    $colsCaja = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cajas_turnos'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ventas_zelle_usd', $colsCaja, true)) {
        $pdo->exec("ALTER TABLE cajas_turnos ADD COLUMN ventas_zelle_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ventas_transferencia_usd");
        echo "<p style='color:green;'>✅ Columna cajas_turnos.ventas_zelle_usd agregada</p>";
    }
    if (!in_array('ventas_biopago_usd', $colsCaja, true)) {
        $pdo->exec("ALTER TABLE cajas_turnos ADD COLUMN ventas_biopago_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ventas_zelle_usd");
        echo "<p style='color:green;'>✅ Columna cajas_turnos.ventas_biopago_usd agregada</p>";
    }

    // 3. Ampliar ENUM de pagos.metodo_pago
    $pdo->exec("ALTER TABLE pagos MODIFY COLUMN metodo_pago ENUM('pago_movil','transferencia','zelle','biopago') NOT NULL");
    echo "<p style='color:green;'>✅ ENUM de pagos.metodo_pago ampliado (zelle, biopago)</p>";

    echo "<h2 style='color:green;'>✅ ¡Migración completada exitosamente!</h2>";
    echo "<p><a href='admin/index.php'>Ir al panel admin</a> | <a href='index.html'>Ir a la tienda</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>❌ Error:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}