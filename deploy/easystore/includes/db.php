<?php
// =============================================================================
//  EasyStore - Conexion a la base de datos (PRODUCCION / InfinityFree)
// =============================================================================
//  COMO LLENAR ESTOS DATOS EN INFINITYFREE:
//
//   1. En el panel de control del hosting abre "MySQL Databases".
//   2. Crea una base de datos nueva ("Create New Database"). Ahí verás 4 datos
//      que debes copiar a este archivo:
//         - Database name  (suele ser:  if0_00000000_easystore)
//         - Database user  (suele ser:  if0_00000000)
//         - Database password (la que elegiste al crearla)
//         - MySQL Hostname / Server  (NO es "localhost"; suele verse
//           como "sqlXXX.000webhost.com", "sqlXXX.infinityfree.com" u otro
//           que indique el panel). El panel lo muestra junto a phpMyAdmin.
//   3. Pega los 4 valores abajo.
//   4. Sube el archivo por FTP (FileZilla) a:  htdocs/includes/db.php
//
//  OJO:  NO uses el hostname de phpMyAdmin como host de la app. Usa el que el
//  panel indica como "MySQL Hostname" para conexiones remotas/aplicaciones.
// =============================================================================

// En produccion conviene apagar la salida de errores por pantalla.
// Pon display_errors en 0 (y error_reporting E_ALL se puede mantener).
ini_set('display_errors', 1);
error_reporting(E_ALL);

$host   = 'sql000.infinityfree.com';   // <-- MySQL Hostname que muestra el panel
$dbname = 'if0_00000000_easystore';    // <-- Nombre de tu base de datos
$user   = 'if0_00000000';              // <-- Usuario de tu base de datos
$pass   = 'TU_CLAVE_DE_LA_BD';         // <-- Clave de tu base de datos

// Zona horaria del negocio (Venezuela). Evita el desfase conocido entre PHP y
// MySQL (la hora del servidor no tiene por qué coincidir con la del negocio).
date_default_timezone_set('America/Caracas');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Forzar nombres de columnas a minusculas para evitar problemas de compatibilidad
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