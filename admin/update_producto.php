<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de producto requerido']);
    exit;
}

try {
    $imagenes_json = isset($data['imagenes']) ? json_encode($data['imagenes']) : null;

    $idProducto = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    if ($idProducto === false || $idProducto <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El ID del producto debe ser un número entero válido.']);
        exit;
    }

    $precioCompra = $data['precio_compra'] ?? 0;
    $precioUsd = $data['precio_usd'] ?? 0;
    $stock = $data['stock'] ?? 0;
    $stockMinimo = $data['stock_minimo'] ?? 0;
    $ivaAlicuota = Sanitizer::float($data['iva_alicuota'] ?? 16, 0, 100, 16);

    if (!is_numeric($precioCompra) || !is_numeric($precioUsd) || !is_numeric($stock) || !is_numeric($stockMinimo)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Los campos de precio y stock deben ser numéricos.']);
        exit;
    }

    $precioCompra = (float)$precioCompra;
    $precioUsd = (float)$precioUsd;
    $stock = (int)$stock;
    $stockMinimo = (int)$stockMinimo;

    if ($precioCompra < 0 || $precioUsd < 0 || $stock < 0 || $stockMinimo < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Los valores de precio y stock no pueden ser negativos.']);
        exit;
    }
    
    // 1. Obtener stock anterior para detectar si hay abastecimiento
    $stmtOld = $pdo->prepare("SELECT stock FROM productos WHERE id = ?");
    $stmtOld->execute([$idProducto]);
    $oldStock = $stmtOld->fetchColumn();

    $params = [
        ':id'           => $idProducto,
        ':nombre'       => $data['nombre'] ?? 'Sin nombre',
        ':descripcion'  => $data['descripcion'] ?? '',
        ':precio_compra'=> $precioCompra,
        ':precio_usd'   => $precioUsd,
        ':iva_alicuota' => $ivaAlicuota,
        ':stock'        => $stock,
        ':stock_minimo' => $stockMinimo,
        ':categoria'    => $data['categoria'] ?? 'General',
        ':subcategoria' => $data['subcategoria'] ?? null,
        ':marca'        => Sanitizer::string($data['marca'] ?? '', 100) ?: null,
        ':imagen'       => $data['imagen'] ?? '',
        ':imagenes'     => $imagenes_json,
        ':ficha_tecnica'=> $data['ficha_tecnica'] ?? null
    ];

    $sql = "UPDATE productos 
            SET nombre = :nombre, 
                descripcion = :descripcion, 
                precio_compra = :precio_compra,
                precio_usd = :precio_usd, 
                iva_alicuota = :iva_alicuota,
                stock = :stock, 
                stock_minimo = :stock_minimo, 
                categoria = :categoria, 
                subcategoria = :subcategoria, 
                marca = :marca,
                imagen = :imagen, 
                imagenes = :imagenes, 
                ficha_tecnica = :ficha_tecnica,
                publicado = 1
            WHERE id = :id";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // 2. Si el stock aumentó, registrar en abastecimientos
    if ($params[':stock'] > $oldStock) {
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

        $cantidadNueva = $params[':stock'] - $oldStock;
        $sqlAbast = "INSERT INTO abastecimientos (producto_id, nombre_producto, cantidad, precio_compra_unitario, precio_venta_unitario) 
                     VALUES (?, ?, ?, ?, ?)";
        $stmtAbast = $pdo->prepare($sqlAbast);
        $stmtAbast->execute([
            $params[':id'],
            $params[':nombre'],
            $cantidadNueva,
            $params[':precio_compra'],
            $params[':precio_usd']
        ]);
    }

    echo json_encode(['success' => true, 'mensaje' => 'Producto actualizado y abastecimiento registrado si hubo aumento']);
} catch (PDOException $e) {
    file_put_contents('../error_log.txt', "[" . date('Y-m-d H:i:s') . "] Error en update_producto: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Error en base de datos', 'detalle' => $e->getMessage()]);
}
?>
