<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Acceso denegado. Se requieren permisos de administrador.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/schema.php';

if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Por favor selecciona un archivo .sql válido.']);
    exit;
}

$file = $_FILES['sql_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($ext !== 'sql') {
    echo json_encode(['success' => false, 'message' => 'Formato no permitido. Debe ser un archivo con extensión .sql']);
    exit;
}

/**
 * Ejecuta scripts SQL complejos de forma segura procesando sentencia por sentencia.
 */
function ejecutarScriptSQL($pdo, $sqlContent) {
    $sqlContent = str_replace(["\r\n", "\r"], "\n", $sqlContent);

    $queries = [];
    $currentQuery = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sqlContent);

    for ($i = 0; $i < $len; $i++) {
        $char = $sqlContent[$i];
        $nextChar = ($i + 1 < $len) ? $sqlContent[$i + 1] : '';

        if ($inString) {
            $currentQuery .= $char;
            if ($char === '\\') {
                if ($i + 1 < $len) {
                    $i++;
                    $currentQuery .= $sqlContent[$i];
                }
            } elseif ($char === $stringChar) {
                $inString = false;
                $stringChar = '';
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $inString = true;
            $stringChar = $char;
            $currentQuery .= $char;
            continue;
        }

        if (($char === '-' && $nextChar === '-') || $char === '#') {
            while ($i < $len && $sqlContent[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($currentQuery);
            if (!empty($trimmed)) {
                $queries[] = $trimmed;
            }
            $currentQuery = '';
            continue;
        }

        $currentQuery .= $char;
    }

    $trimmed = trim($currentQuery);
    if (!empty($trimmed)) {
        $queries[] = $trimmed;
    }

    foreach ($queries as $q) {
        $pdo->exec($q);
    }
}

try {
    $sqlContent = file_get_contents($file['tmp_name']);
    if (empty(trim($sqlContent))) {
        echo json_encode(['success' => false, 'message' => 'El archivo .sql está vacío.']);
        exit;
    }

    // 1. Desactivar llaves foráneas para evitar conflictos en cascada durante la eliminación/restauración
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 2. Eliminar todas las tablas actuales para garantizar una sobreescritura 100% limpia
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }

    // 3. Ejecutar las sentencias del archivo .sql
    ejecutarScriptSQL($pdo, $sqlContent);

    // 4. Reactivar llaves foráneas
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 5. Garantizar que todas las tablas del sistema (incluyendo abastecimientos) existan tras restaurar
    asegurarEsquemaBD($pdo);

    echo json_encode([
        'success' => true,
        'message' => '✅ Base de datos restaurada correctamente. Los cambios ya están aplicados.'
    ]);
} catch (Exception $e) {
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch(Exception $ex) {}
    try { asegurarEsquemaBD($pdo); } catch(Exception $ex) {}
    echo json_encode([
        'success' => false,
        'message' => 'Error al restaurar la base de datos: ' . $e->getMessage()
    ]);
}
