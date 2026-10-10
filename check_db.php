<?php
require_once 'includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    echo "=== DIAGNÓSTICO DE BASE DE DATOS ===\n\n";

    // 1. Mostrar tablas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tablas encontradas:\n";
    foreach ($tables as $t) {
        echo "- $t\n";
    }
    echo "\n";

    // 2. Mostrar estructura de productos
    if (in_array('productos', $tables)) {
        echo "Estructura de 'productos':\n";
        $stmt = $pdo->query("DESCRIBE productos");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            echo "  - {$col['field']}: {$col['type']} (Null: {$col['null']}, Key: {$col['key']}, Default: {$col['default']})\n";
        }
    } else {
        echo "❌ La tabla 'productos' NO EXISTE.\n";
    }
    echo "\n";

    // 3. Mostrar estructura de pagos
    if (in_array('pagos', $tables)) {
        echo "Estructura de 'pagos':\n";
        $stmt = $pdo->query("DESCRIBE pagos");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            echo "  - {$col['field']}: {$col['type']} (Null: {$col['null']}, Key: {$col['key']}, Default: {$col['default']})\n";
        }
    } else {
        echo "❌ La tabla 'pagos' NO EXISTE.\n";
    }
    echo "\n";

    // 4. Mostrar configuraciones en tasa_dolar
    if (in_array('tasa_dolar', $tables)) {
        echo "Valores en 'tasa_dolar':\n";
        $stmt = $pdo->query("SELECT * FROM tasa_dolar");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "  - {$row['clave']}: {$row['valor']}\n";
        }
    } else {
        echo "❌ La tabla 'tasa_dolar' NO EXISTE.\n";
    }
    echo "\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
