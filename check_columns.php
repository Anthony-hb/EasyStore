<?php
require 'db.php';
try {
    $stmt = $pdo->query("DESCRIBE productos");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Columnas de la tabla 'productos':</h3>";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})<br>";
    }
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }
?>