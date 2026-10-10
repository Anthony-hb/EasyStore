<?php
require 'db.php';
echo "--- TABLAS EN LA BASE DE DATOS ---\n";
$stmt = $pdo->query("SHOW TABLES");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
?>
