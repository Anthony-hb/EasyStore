<?php
require_once 'includes/db.php';
try {
    $res = $pdo->query("SHOW TABLES");
    while ($r = $res->fetch(PDO::FETCH_COLUMN)) {
        echo $r . "\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}