<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$host = 'localhost';
$dbname = 'tienda';
$user = 'root';
$pass = ''; // Por defecto en XAMPP suele ser vacío

// Identidad del sistema (software). El nombre del negocio es configurable
// en el panel (Mi Cuenta → Información del Negocio).
define('SISTEMA_NOMBRE', 'EasyStore');
define('SISTEMA_VERSION', '1.0.0');
define('SISTEMA_TAGLINE', 'Sistema de Punto de Venta y Gestión de Tienda');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Forzar nombres de columnas a minúsculas para evitar problemas de compatibilidad
    $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
} catch (PDOException $e) {
    throw new Exception("Error de conexión a la base de datos: " . $e->getMessage());
}

require_once __DIR__ . '/sanitizer.php';
require_once __DIR__ . '/seguridad.php';
require_once __DIR__ . '/schema.php';
try {
    asegurarEsquemaBD($pdo);
} catch (Exception $e) {
    error_log("Error al verificar esquema de base de datos: " . $e->getMessage());
}
?>
