<?php
require_once 'includes/db.php';
try {
    $c = $pdo->query("SELECT COUNT(*) FROM administrador")->fetchColumn();
    echo $c;
} catch (Exception $e) {
    echo 'ERR';
}