<?php
require_once 'includes/db.php';
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("DROP TABLE IF EXISTS devolucion_proveedor_detalles, devoluciones_proveedor, compra_detalles, compras, proveedores, mermas_perdidas, devolucion_detalles, devoluciones, cajas_turnos, abastecimientos, pagos, venta_detalles, ventas, favoritos, productos, clientes, administrador, tasa_dolar");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo "ok";
} catch (Exception $e) {
    echo $e->getMessage();
}