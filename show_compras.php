<?php
require_once 'includes/db.php';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM compras")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['Field'] . "\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}