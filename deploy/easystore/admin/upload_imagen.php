<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require '../includes/db.php';
require 'check_session.php';
requerirAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['file']['error'] ?? 'Sin archivo';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibió ningún archivo válido o hubo un error al subirlo.', 'detalle_error' => $errorCode]);
    exit;
}

$file = $_FILES['file'];

// Validar tamaño (máximo 10 MB)
$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El archivo supera el tamaño máximo permitido de 10 MB.']);
    exit;
}

// Validar extensiones permitidas
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
if (!in_array($ext, $allowedExts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de imagen no permitido. Usa JPG, PNG, WEBP, GIF o SVG.']);
    exit;
}

// Validar MIME type real de la imagen
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
if (!in_array($mimeType, $allowedMimes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El archivo no es una imagen válida.']);
    exit;
}

// Asegurar directorio destino
$uploadDir = __DIR__ . '/../uploads/productos/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo crear la carpeta destino para las imágenes.']);
        exit;
    }
}

// Generar nombre de archivo único
try {
    $randomHex = bin2hex(random_bytes(6));
} catch (Exception $e) {
    $randomHex = uniqid();
}

$filename = 'prod_' . time() . '_' . $randomHex . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $relativePath = 'uploads/productos/' . $filename;
    echo json_encode([
        'success' => true,
        'url' => $relativePath,
        'filename' => $filename,
        'mensaje' => 'Imagen subida exitosamente'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar la imagen en el servidor.']);
}
?>
