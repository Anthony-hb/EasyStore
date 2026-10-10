<?php
require_once 'includes/db.php';
require_once 'includes/schema.php';

header('Content-Type: text/html; charset=utf-8');

echo "<div style='font-family: sans-serif; padding: 20px;'>";
echo "<h2>🛠️ Actualizando Esquema de Base de Datos</h2>";

try {
    asegurarEsquemaBD($pdo);
    echo "<p style='color: green; font-weight: bold; font-size: 1.2rem;'>✅ Base de datos actualizada con éxito en MySQL (XAMPP).</p>";
    // No se listan nombres a mano: se consulta information_schema para no
    // seguir anunciando tablas que ya no existen (contabilidad_cuentas,
    // contabilidad_asientos, contabilidad_asiento_detalles, gastos_operativos,
    // que se eliminaron con el módulo de Contabilidad).
    $tablas = [
        'productos', 'administrador', 'clientes', 'proveedores',
        'compras', 'compra_detalles', 'ventas', 'venta_detalles',
        'mermas_perdidas', 'devoluciones', 'devolucion_detalles',
        'devoluciones_proveedor', 'devolucion_proveedor_detalles',
        'apartados', 'apartado_abonos', 'cajas_turnos',
        'kardex_inventario',
    ];

    echo "<ul>";
    foreach ($tablas as $t) {
        $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn();
        echo $existe
            ? "<li>✔ Tabla <code>{$t}</code> verificada/creada.</li>"
            : "<li style='color:#c00;'>✘ Tabla <code>{$t}</code> NO existe.</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
echo "</div>";
