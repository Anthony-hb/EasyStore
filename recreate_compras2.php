<?php
require_once 'includes/db.php';
try {
    // Desactivar FK checks para eliminar
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("DROP TABLE IF EXISTS compra_detalles, compras, proveedores, devolucion_proveedor_detalles, devoluciones_proveedor");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo "dropped";
} catch (Exception $e) {
    echo $e->getMessage();
}