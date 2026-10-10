<?php
require 'db.php';
try {
    $sql = "INSERT INTO productos (nombre, precio_usd, stock, categoria) VALUES ('TEST', 10, 10, 'TEST')";
    $pdo->exec($sql);
    echo "SUCCESS: Product inserted";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
