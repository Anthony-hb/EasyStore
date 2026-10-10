<?php
require_once 'includes/db.php';
require_once 'includes/schema.php';
try {
    asegurarEsquemaBD($pdo);
    // Forzar creación de tablas auth si faltan
    $pdo->exec("CREATE TABLE IF NOT EXISTS administrador (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        nombre_completo VARCHAR(100),
        email VARCHAR(100),
        telefono VARCHAR(20),
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        negocio_nombre VARCHAR(255),
        negocio_telefono VARCHAR(50),
        negocio_horario VARCHAR(255),
        negocio_ubicacion VARCHAR(255),
        pm_banco VARCHAR(100),
        pm_telefono VARCHAR(50),
        pm_cedula VARCHAR(50),
        transf_banco VARCHAR(100),
        transf_cuenta VARCHAR(100),
        transf_titular VARCHAR(150),
        transf_cedula VARCHAR(50)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        nombre_completo VARCHAR(100),
        email VARCHAR(100),
        telefono VARCHAR(20),
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK";
} catch (Exception $e) {
    echo "ERR:" . $e->getMessage();
}