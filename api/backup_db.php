<?php
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    die('Acceso denegado. Solo administradores pueden realizar respaldos.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/schema.php';

try {
    // 0. Garantizar que todas las tablas necesarias existan antes del volcado
    asegurarEsquemaBD($pdo);

    // 1. Obtener todas las tablas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $sqlDump = "-- ========================================================\n";
    $sqlDump .= "-- RESPALDO DE BASE DE DATOS TIENDA DIGITAL\n";
    $sqlDump .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
    $sqlDump .= "-- ========================================================\n\n";
    $sqlDump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        // Estructura de la tabla
        $showCreate = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $showCreate['Create Table'] ?? ($showCreate['create table'] ?? '');

        if (!empty($createSql)) {
            $sqlDump .= "-- --------------------------------------------------------\n";
            $sqlDump .= "-- Estructura para la tabla `$table`\n";
            $sqlDump .= "-- --------------------------------------------------------\n";
            $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
            $sqlDump .= $createSql . ";\n\n";
        }

        // Datos de la tabla
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $sqlDump .= "-- Volcado de datos para la tabla `$table`\n";
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $pdo->quote($val);
                    }
                }
                $sqlDump .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sqlDump .= "\n";
        }
    }

    $sqlDump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    // Forzar descarga del archivo .sql
    $fileName = 'backup_tienda_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($sqlDump));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    echo $sqlDump;
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo "Error al generar el respaldo: " . $e->getMessage();
}
