<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    require '../includes/db.php';
} catch (Exception $e) {
    error_log("Error save_cliente.php DB connect: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos.']);
    exit;
}

require 'check_session.php';
requerirAdmin();

// Asegurar que la columna cedula exista en la tabla clientes
try {
    $stmtCheckCol = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'cedula'");
    if (!$stmtCheckCol->fetch()) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN cedula VARCHAR(50) NULL AFTER nombre_completo");
    }
} catch (Exception $e) {
    // Silencioso si la columna ya existe
}

$input = Sanitizer::readJsonInput();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos del cliente.']);
    exit;
}

$nombre = Sanitizer::string($input['nombre_completo'] ?? '', 100);
$usuario = Sanitizer::username($input['usuario'] ?? '', 30);
$password = (string)($input['password'] ?? '');

$prefijo_telefono = Sanitizer::string($input['prefijo_telefono'] ?? '', 10);
$numero_telefono = Sanitizer::phone($input['numero_telefono'] ?? '');
$telefono = $numero_telefono !== '' ? $prefijo_telefono . $numero_telefono : '';

$numero_cedula = Sanitizer::phone($input['numero_cedula'] ?? '');
$cedula = $numero_cedula !== '' ? 'V-' . $numero_cedula : '';

$raw_email = Sanitizer::string($input['email'] ?? '', 255);
$email = '';
if ($raw_email !== '') {
    $email = str_contains($raw_email, '@') ? Sanitizer::email($raw_email) : Sanitizer::email($raw_email . '@gmail.com');
}

if ($nombre === '' || $usuario === '' || $password === '' || $telefono === '' || $cedula === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Por favor completa todos los campos obligatorios.']);
    exit;
}

if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El usuario solo puede tener letras, números, puntos y guiones.']);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El correo electrónico no es válido.']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.']);
    exit;
}

try {
    // Verificar duplicado por usuario o correo (si email no está vacío)
    if ($email !== '') {
        $stmt = $pdo->prepare(
            "SELECT id FROM administrador WHERE usuario = :usuario OR (email = :email AND email != '') 
             UNION ALL 
             SELECT id FROM clientes WHERE usuario = :usuario OR (email = :email AND email != '')"
        );
        $stmt->execute([':usuario' => $usuario, ':email' => $email]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id FROM administrador WHERE usuario = ? 
             UNION ALL 
             SELECT id FROM clientes WHERE usuario = ?"
        );
        $stmt->execute([$usuario, $usuario]);
    }

    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El nombre de usuario o email ya está registrado.']);
        exit;
    }

    $seguridad = validarPreguntaSeguridad(
        $pdo,
        $input['pregunta_seguridad_id'] ?? 0,
        $input['respuesta_seguridad'] ?? ''
    );

    if ($seguridad['pregunta_id'] === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Debes elegir una pregunta de seguridad para el cliente.']);
        exit;
    }

    if ($seguridad['error'] !== '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $seguridad['error']]);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO clientes (usuario, password, nombre_completo, cedula, email, telefono, pregunta_seguridad_id, respuesta_seguridad)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtInsert = $pdo->prepare($sql);
    $stmtInsert->execute([
        $usuario, $hashed_password, $nombre, $cedula, $email ?: null, $telefono,
        $seguridad['pregunta_id'], hashearRespuestaSeguridad($seguridad['respuesta'])
    ]);

    $cliente_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => "Cliente '$nombre' registrado con éxito.",
        'cliente' => [
            'id' => $cliente_id,
            'nombre_completo' => $nombre,
            'cedula' => $cedula,
            'usuario' => $usuario,
            'email' => $email,
            'telefono' => $telefono
        ]
    ]);

} catch (Exception $e) {
    error_log("Error save_cliente.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en el servidor al guardar el cliente: ' . $e->getMessage()]);
}
?>
