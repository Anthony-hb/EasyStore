<?php
require 'db.php';
try {
    // Pasar las ventas del usuario 1 (admin) al usuario 2 (Anthony) para demostración
    $pdo->exec("UPDATE ventas SET usuario_id = 2 WHERE usuario_id = 1");
    echo "Ventas actualizadas con éxito. Ahora pertenecen a Anthony Hernandez.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
