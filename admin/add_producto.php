<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

// Leer datos JSON del cuerpo de la petición
$data = Sanitizer::readJsonInput();

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibieron datos validos']);
    exit;
}

try {
    $precioCompra = Sanitizer::float($data['precio_compra'] ?? 0, 0);
    $precioUsd = Sanitizer::float($data['precio_usd'] ?? 0, 0);
    $ivaAlicuota = Sanitizer::float($data['iva_alicuota'] ?? 16, 0, 100, 16);
    $stock = 0; // El stock inicial no se puede modificar al publicar; se gestiona por Compras/Abastecimiento
    $stockMinimo = Sanitizer::int($data['stock_minimo'] ?? 0, 0);

    $nombre = Sanitizer::string($data['nombre'] ?? '', 150);
    if (empty($nombre)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El nombre del producto es obligatorio.']);
        exit;
    }

    $descripcion = Sanitizer::text($data['descripcion'] ?? '');
    $marca = Sanitizer::string($data['marca'] ?? '', 100);
    $categoria = Sanitizer::string($data['categoria'] ?? 'General', 100);
    $subcategoria = !empty($data['subcategoria']) ? Sanitizer::string($data['subcategoria'], 100) : null;
    $imagen = Sanitizer::string($data['imagen'] ?? '', 255);
    $ficha_tecnica = !empty($data['ficha_tecnica']) ? Sanitizer::text($data['ficha_tecnica']) : null;
    $destacado = Sanitizer::int($data['destacado'] ?? 0, 0, 1);

    // Sanitizar array de imagenes si existe
    $imagenesArr = [];
    if (isset($data['imagenes']) && is_array($data['imagenes'])) {
        foreach ($data['imagenes'] as $img) {
            $cleanImg = Sanitizer::string($img, 255);
            if ($cleanImg !== '') {
                $imagenesArr[] = $cleanImg;
            }
        }
    }
    $imagenes_json = !empty($imagenesArr) ? json_encode($imagenesArr) : null;
    
    // Valores con limpieza de datos
    $params = [
        ':nombre'       => $nombre,
        ':descripcion'  => $descripcion,
        ':precio_compra'=> $precioCompra,
        ':precio_usd'   => $precioUsd,
        ':iva_alicuota' => $ivaAlicuota,
        ':stock'        => $stock,
        ':stock_minimo' => $stockMinimo,
        ':categoria'    => $categoria,
        ':subcategoria' => $subcategoria,
        ':marca'        => $marca !== '' ? $marca : null,
        ':imagen'       => $imagen,
        ':imagenes'     => $imagenes_json,
        ':ficha_tecnica'=> $ficha_tecnica,
        ':publicado'    => 1,
        ':destacado'    => $destacado
    ];

    // Validar producto duplicado por nombre
    $stmtCheckProd = $pdo->prepare("SELECT id FROM productos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?))");
    $stmtCheckProd->execute([$params[':nombre']]);
    if ($stmtCheckProd->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Ya existe un producto registrado con el nombre '{$params[':nombre']}'."]);
        exit;
    }

    $sql = "INSERT INTO productos 
            (nombre, descripcion, precio_compra, precio_usd, iva_alicuota, stock, stock_minimo, destacado, publicado, categoria, subcategoria, marca, imagen, imagenes, ficha_tecnica)
            VALUES 
            (:nombre, :descripcion, :precio_compra, :precio_usd, :iva_alicuota, :stock, :stock_minimo, :destacado, :publicado, :categoria, :subcategoria, :marca, :imagen, :imagenes, :ficha_tecnica)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $id = $pdo->lastInsertId();

    // REGISTRO AUTOMÁTICO DE ABASTECIMIENTO
    if ($params[':stock'] > 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS abastecimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT,
            nombre_producto VARCHAR(255) NOT NULL,
            cantidad INT NOT NULL,
            precio_compra_unitario DECIMAL(10, 2) NOT NULL,
            precio_venta_unitario DECIMAL(10, 2) NOT NULL,
            fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $sqlAbast = "INSERT INTO abastecimientos (producto_id, nombre_producto, cantidad, precio_compra_unitario, precio_venta_unitario) 
                     VALUES (?, ?, ?, ?, ?)";
        $stmtAbast = $pdo->prepare($sqlAbast);
        $stmtAbast->execute([
            $id,
            $params[':nombre'],
            $params[':stock'],
            $params[':precio_compra'],
            $params[':precio_usd']
        ]);
    }

    echo json_encode(['success' => true, 'id' => $id, 'mensaje' => 'Producto creado y abastecimiento registrado']);
} catch (PDOException $e) {
    file_put_contents('../error_log.txt', "[" . date('Y-m-d H:i:s') . "] Error en add_producto: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Error en base de datos', 'detalle' => $e->getMessage()]);
}
?>
