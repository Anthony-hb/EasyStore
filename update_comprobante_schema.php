<?php
/**
 * Migración: Añadir soporte de comprobante a tabla pagos
 * Ejecutar vía navegador: http://localhost/tienda/update_comprobante_schema.php
 */
require_once __DIR__ . '/includes/db.php';

echo "<h1>🔄 Migración: Comprobantes de Pago</h1>";

try {
    // 1. Añadir columna receipt_path si no existe
    $cols = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'receipt_path'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN receipt_path VARCHAR(500) NULL AFTER monto");
        echo "<p style='color:green;'>✅ Columna receipt_path añadida</p>";
    } else {
        echo "<p style='color:gray;'>⏭ Columna receipt_path ya existe</p>";
    }

    // 2. Asegurar que la carpeta uploads/comprobantes/ existe
    $dir = __DIR__ . '/uploads/comprobantes/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "<p style='color:green;'>✅ Carpeta uploads/comprobantes/ creada</p>";
    } else {
        echo "<p style='color:gray;'>⏭ Carpeta uploads/comprobantes/ ya existe</p>";
    }

    // 3. Crear archivo .htaccess para proteger los comprobantes (solo admin puede verlos)
    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n");
        echo "<p style='color:green;'>✅ .htaccess creado (directorio protegido)</p>";
    }

    echo "<h2 style='color:green;'>✅ ¡Migración completada!</h2>";
    echo "<p><a href='index.html'>Ir a la tienda</a> | <a href='admin/index.php'>Panel admin</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>❌ Error:</h2><pre>" . $e->getMessage() . "</pre>";
}
?>
