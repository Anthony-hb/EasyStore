<?php
// Script de migración para actualizar la tabla 'administrador' y la tabla 'tasa_dolar'
require_once 'includes/db.php';

echo "<h2>🛠️ Migración de Base de Datos</h2>";

try {
    // 1. Agregar columnas a la tabla 'administrador'
    $columnasAdmin = [
        'negocio_nombre' => 'VARCHAR(255)',
        'negocio_telefono' => 'VARCHAR(50)',
        'negocio_horario' => 'VARCHAR(255)',
        'negocio_ubicacion' => 'VARCHAR(255)',
        'pm_banco' => 'VARCHAR(100)',
        'pm_telefono' => 'VARCHAR(50)',
        'pm_cedula' => 'VARCHAR(50)',
        'transf_banco' => 'VARCHAR(100)',
        'transf_cuenta' => 'VARCHAR(100)',
        'transf_titular' => 'VARCHAR(150)',
        'transf_cedula' => 'VARCHAR(50)'
    ];

    $stmtCols = $pdo->query("SHOW COLUMNS FROM administrador");
    $existingCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

    foreach ($columnasAdmin as $col => $def) {
        if (!in_array($col, $existingCols)) {
            $pdo->exec("ALTER TABLE administrador ADD COLUMN $col $def DEFAULT NULL");
            echo "<p style='color:green;'>✅ Columna '$col' agregada a la tabla 'administrador'.</p>";
        }
    }

    // 2. Verificar la estructura de 'tasa_dolar'
    $stmtTasaCols = $pdo->query("SHOW COLUMNS FROM tasa_dolar");
    $tasaCols = $stmtTasaCols->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('clave', $tasaCols)) {
        echo "<p>🔄 Migrando datos desde la antigua estructura de 'tasa_dolar' a 'administrador'...</p>";

        // Leer configuraciones antiguas
        $stmtOld = $pdo->query("SELECT clave, valor FROM tasa_dolar");
        $oldConfig = $stmtOld->fetchAll(PDO::FETCH_KEY_PAIR);

        $tasaInicial = isset($oldConfig['tasa_dolar']) ? floatval($oldConfig['tasa_dolar']) : 50.00;

        // Migrar valores a administrador (si existe al menos un admin)
        $stmtAdmin = $pdo->query("SELECT id FROM administrador ORDER BY id ASC LIMIT 1");
        $adminId = $stmtAdmin->fetchColumn();

        if ($adminId) {
            $stmtMigrate = $pdo->prepare("
                UPDATE administrador SET 
                    negocio_nombre = COALESCE(negocio_nombre, :negocio_nombre),
                    negocio_telefono = COALESCE(negocio_telefono, :negocio_telefono),
                    negocio_horario = COALESCE(negocio_horario, :negocio_horario),
                    negocio_ubicacion = COALESCE(negocio_ubicacion, :negocio_ubicacion),
                    pm_banco = COALESCE(pm_banco, :pm_banco),
                    pm_telefono = COALESCE(pm_telefono, :pm_telefono),
                    pm_cedula = COALESCE(pm_cedula, :pm_cedula),
                    transf_banco = COALESCE(transf_banco, :transf_banco),
                    transf_cuenta = COALESCE(transf_cuenta, :transf_cuenta),
                    transf_titular = COALESCE(transf_titular, :transf_titular),
                    transf_cedula = COALESCE(transf_cedula, :transf_cedula)
                WHERE id = :id
            ");

            $stmtMigrate->execute([
                ':negocio_nombre' => $oldConfig['negocio_nombre'] ?? null,
                ':negocio_telefono' => $oldConfig['negocio_telefono'] ?? null,
                ':negocio_horario' => $oldConfig['negocio_horario'] ?? null,
                ':negocio_ubicacion' => $oldConfig['negocio_ubicacion'] ?? null,
                ':pm_banco' => $oldConfig['pm_banco'] ?? null,
                ':pm_telefono' => $oldConfig['pm_telefono'] ?? null,
                ':pm_cedula' => $oldConfig['pm_cedula'] ?? null,
                ':transf_banco' => $oldConfig['transf_banco'] ?? null,
                ':transf_cuenta' => $oldConfig['transf_cuenta'] ?? null,
                ':transf_titular' => $oldConfig['transf_titular'] ?? null,
                ':transf_cedula' => $oldConfig['transf_cedula'] ?? null,
                ':id' => $adminId
            ]);
            echo "<p style='color:green;'>✅ Datos de negocio y bancos migrados exitosamente a la tabla 'administrador'.</p>";
        }

        // Reemplazar tabla 'tasa_dolar' con nueva estructura
        $pdo->exec("DROP TABLE tasa_dolar");
        $pdo->exec("CREATE TABLE tasa_dolar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tasa DECIMAL(10, 2) NOT NULL,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmtIns = $pdo->prepare("INSERT INTO tasa_dolar (tasa) VALUES (?)");
        $stmtIns->execute([$tasaInicial > 0 ? $tasaInicial : 50.00]);

        echo "<p style='color:green;'>✅ Tabla 'tasa_dolar' recreada con soporte para historial de tasa diaria.</p>";
    } else {
        echo "<p style='color:blue;'>ℹ️ La tabla 'tasa_dolar' ya cuenta con el formato correcto de historial.</p>";
    }

    echo "<h3 style='color:green;'>🎉 Migración completada sin errores.</h3>";

} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error durante la migración: " . $e->getMessage() . "</p>";
}
?>
