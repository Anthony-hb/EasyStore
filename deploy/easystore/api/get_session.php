<?php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'logged_in' => true,
        'user' => [
            'id' => $_SESSION['usuario_id'],
            'nombre' => $_SESSION['usuario_nombre'],
            'rol' => $_SESSION['rol']
        ]
    ]);
} else {
    echo json_encode(['logged_in' => false]);
}
?>
