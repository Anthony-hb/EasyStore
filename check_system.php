<?php
require 'includes/db.php';
header('Content-Type: text/html; charset=utf-8');

function checkTable($pdo, $tableName) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $tableName");
        $count = $stmt->fetchColumn();
        echo "✅ Tabla '$tableName' existe y tiene $count registros.<br>";
    } catch (PDOException $e) {
        echo "❌ Error con tabla '$tableName': " . $e->getMessage() . "<br>";
    }
}

echo "<h2>Diagnóstico del Sistema</h2>";
checkTable($pdo, 'productos');
checkTable($pdo, 'ventas');
checkTable($pdo, 'venta_detalles');
checkTable($pdo, 'abastecimientos');

echo "<h3>Últimos 5 Abastecimientos:</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM abastecimientos ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- Producto ID: {$row['producto_id']}, Cant: {$row['cantidad']}, Fecha: {$row['fecha_abastecimiento']}<br>";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }

echo "<h3>Últimas 5 Ventas:</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM ventas ORDER BY id DESC LIMIT 5");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- Venta ID: {$row['id']}, Total: {$row['total_usd']}, Fecha: {$row['fecha_venta']}<br>";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
?>
