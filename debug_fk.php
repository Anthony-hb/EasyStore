<?php
require_once 'includes/db.php';
try {
    $pdo->exec("SHOW ENGINE INNODB STATUS");
} catch (Exception $e) {
    echo $e->getMessage();
}