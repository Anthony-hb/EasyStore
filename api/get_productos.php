<?php
header('Content-Type: application/json; charset=utf-8');

// Habilitar CORS para pruebas locales si fuera necesario
header('Access-Control-Allow-Origin: *');

require '../includes/db.php';

try {
    $stmt = $pdo->query("SELECT * FROM productos WHERE publicado = 1 ORDER BY id DESC");
    $productos = $stmt->fetchAll();

    // Procesar campos para que el Frontend los reciba igual que antes
    foreach ($productos as &$p) {
        $p['id'] = (int)$p['id'];
        $p['precio_usd'] = (float)$p['precio_usd'];
        $p['iva_alicuota'] = (float)($p['iva_alicuota'] ?? 16.00);
        $p['precio_compra'] = (float)($p['precio_compra'] ?? 0);
        $p['stock'] = (int)$p['stock'];
        $p['stock_minimo'] = (int)$p['stock_minimo'];

        // Decodificar JSON de imagenes adicionales si existe
        if ($p['imagenes']) {
            $decodedImages = json_decode($p['imagenes'], true);
            if (is_array($decodedImages)) {
                $p['imagenes'] = $decodedImages;
            } elseif (is_string($decodedImages) && $decodedImages !== '') {
                $p['imagenes'] = [$decodedImages];
            } else {
                $p['imagenes'] = [$p['imagen']];
            }
        } else {
            $p['imagenes'] = [$p['imagen']];
        }
    }

    echo json_encode($productos);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al obtener productos', 'detalle' => $e->getMessage()]);
}
?>
