<?php
require_once 'includes/db.php';
$r = $pdo->query("SHOW TABLES LIKE 'administrador'");
if ($r->fetchColumn()) {
    echo 'yes';
} else {
    echo 'no';
}