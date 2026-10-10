<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol'])) {
    echo json_encode(['success' => false, 'message' => 'No has iniciado sesión']);
    exit;
}

require '../includes/db.php';

$userId = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];

// Leer datos enviados (soporta JSON o POST tradicional)
$inputJSON = Sanitizer::readJsonInput();
$data = $inputJSON ? $inputJSON : $_POST;

$type = Sanitizer::string($data['type'] ?? 'perfil', 20);

try {
    if ($type === 'negocio' && $rol === 'admin') {
        $stmtUpdate = $pdo->prepare("
            UPDATE administrador SET 
                negocio_nombre = :negocio_nombre,
                negocio_telefono = :negocio_telefono,
                negocio_horario = :negocio_horario,
                negocio_ubicacion = :negocio_ubicacion,
                negocio_rif = :negocio_rif,
                direccion_fiscal = :direccion_fiscal,
                iva_general_pct = :iva_general_pct,
                igtf_pct = :igtf_pct,
                es_agente_retencion = :es_agente_retencion,
                retencion_iva_pct = :retencion_iva_pct
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':negocio_nombre' => Sanitizer::string($data['negocio_nombre'] ?? '', 100),
            ':negocio_telefono' => Sanitizer::string($data['negocio_telefono'] ?? '', 50),
            ':negocio_horario' => Sanitizer::string($data['negocio_horario'] ?? '', 100),
            ':negocio_ubicacion' => Sanitizer::string($data['negocio_ubicacion'] ?? '', 255),
            ':negocio_rif' => Sanitizer::string($data['negocio_rif'] ?? '', 20),
            ':direccion_fiscal' => Sanitizer::string($data['direccion_fiscal'] ?? '', 255),
            ':iva_general_pct' => max(0, min(100, (float)($data['iva_general_pct'] ?? 16))),
            ':igtf_pct' => max(0, min(100, (float)($data['igtf_pct'] ?? 3))),
            ':es_agente_retencion' => !empty($data['es_agente_retencion']) ? 1 : 0,
            ':retencion_iva_pct' => max(0, min(100, (float)($data['retencion_iva_pct'] ?? 75))),
            ':id' => $userId
        ]);

        echo json_encode(['success' => true, 'message' => 'Información del negocio actualizada correctamente.']);
        exit;
    }

    if ($type === 'bancos' && $rol === 'admin') {
        $stmtUpdate = $pdo->prepare("
            UPDATE administrador SET 
                pm_banco = :pm_banco,
                pm_telefono = :pm_telefono,
                pm_cedula = :pm_cedula,
                transf_banco = :transf_banco,
                transf_cuenta = :transf_cuenta,
                transf_titular = :transf_titular,
                transf_cedula = :transf_cedula,
                zelle_correo = :zelle_correo,
                zelle_titular = :zelle_titular,
                biopago_banco = :biopago_banco,
                biopago_cedula = :biopago_cedula,
                biopago_titular = :biopago_titular
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':pm_banco' => Sanitizer::string($data['pm_banco'] ?? '', 50),
            ':pm_telefono' => Sanitizer::phone($data['pm_telefono'] ?? ''),
            ':pm_cedula' => Sanitizer::string($data['pm_cedula'] ?? '', 30),
            ':transf_banco' => Sanitizer::string($data['transf_banco'] ?? '', 50),
            ':transf_cuenta' => Sanitizer::string($data['transf_cuenta'] ?? '', 50),
            ':transf_titular' => Sanitizer::string($data['transf_titular'] ?? '', 100),
            ':transf_cedula' => Sanitizer::string($data['transf_cedula'] ?? '', 30),
            ':zelle_correo' => Sanitizer::string($data['zelle_correo'] ?? '', 120),
            ':zelle_titular' => Sanitizer::string($data['zelle_titular'] ?? '', 100),
            ':biopago_banco' => Sanitizer::string($data['biopago_banco'] ?? '', 50),
            ':biopago_cedula' => Sanitizer::string($data['biopago_cedula'] ?? '', 30),
            ':biopago_titular' => Sanitizer::string($data['biopago_titular'] ?? '', 100),
            ':id' => $userId
        ]);

        echo json_encode(['success' => true, 'message' => 'Datos bancarios actualizados correctamente.']);
        exit;
    }

    // Configuración global de apartados: cuotas a pagar y días límite de pago.
    if ($type === 'apartado' && $rol === 'admin') {
        $cuotas = max(1, min(12, (int)($data['apartado_cuotas'] ?? 3)));
        $diasLimite = max(1, min(90, (int)($data['apartado_dias_limite'] ?? 15)));

        $stmtUpdate = $pdo->prepare("
            UPDATE administrador SET 
                apartado_cuotas = :apartado_cuotas,
                apartado_dias_limite = :apartado_dias_limite
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':apartado_cuotas' => $cuotas,
            ':apartado_dias_limite' => $diasLimite,
            ':id' => $userId
        ]);

        echo json_encode([
            'success' => true,
            'message' => "Configuración de apartados guardada: {$cuotas} cuotas con plazo de {$diasLimite} días.",
            'apartado' => ['cuotas' => $cuotas, 'dias_limite' => $diasLimite]
        ]);
        exit;
    }

    // Configuracion de la pregunta de seguridad (para recuperar la clave).
    if ($type === 'seguridad') {
        $seguridad = validarPreguntaSeguridad(
            $pdo,
            $data['pregunta_seguridad_id'] ?? 0,
            $data['respuesta_seguridad'] ?? ''
        );

        if ($seguridad['pregunta_id'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Selecciona una pregunta de seguridad.']);
            exit;
        }
        if ($seguridad['error'] !== '') {
            echo json_encode(['success' => false, 'message' => $seguridad['error']]);
            exit;
        }

        $passwordActual = (string)($data['password_actual'] ?? '');
        if ($passwordActual === '') {
            echo json_encode(['success' => false, 'message' => 'Debes ingresar tu contraseña actual para cambiar la pregunta de seguridad.']);
            exit;
        }

        $tabla = ($rol === 'admin') ? 'administrador' : 'clientes';
        $stmtPwd = $pdo->prepare("SELECT password FROM $tabla WHERE id = :id LIMIT 1");
        $stmtPwd->execute([':id' => $userId]);
        $registro = $stmtPwd->fetch();

        if (!$registro || !password_verify($passwordActual, $registro['password'])) {
            echo json_encode(['success' => false, 'message' => 'La contraseña actual ingresada es incorrecta.']);
            exit;
        }

        $stmtUpd = $pdo->prepare("
            UPDATE $tabla
            SET pregunta_seguridad_id = :pregunta, respuesta_seguridad = :respuesta
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':pregunta' => $seguridad['pregunta_id'],
            ':respuesta'=> hashearRespuestaSeguridad($seguridad['respuesta']),
            ':id'      => $userId
        ]);

        echo json_encode(['success' => true, 'message' => 'Pregunta de seguridad actualizada.']);
        exit;
    }

    // Actualización de datos personales y/o contraseña de usuario
    $nombreCompleto = Sanitizer::string($data['nombre_completo'] ?? '', 100);
    $email = Sanitizer::email($data['email'] ?? '');
    $telefono = Sanitizer::phone($data['telefono'] ?? '');
    $passwordActual = (string)($data['password_actual'] ?? '');
    $nuevaPassword = (string)($data['nueva_password'] ?? '');
    $confirmarPassword = (string)($data['confirmar_password'] ?? '');

    if (empty($nombreCompleto)) {
        echo json_encode(['success' => false, 'message' => 'El nombre completo es obligatorio.']);
        exit;
    }

    if (empty($email) || !Sanitizer::isValidEmail($email)) {
        echo json_encode(['success' => false, 'message' => 'Por favor, ingresa un correo electrónico válido.']);
        exit;
    }

    $tabla = ($rol === 'admin') ? 'administrador' : 'clientes';

    // Verificar que el correo no pertenezca a otro usuario
    $stmtCheck = $pdo->prepare("
        SELECT id FROM administrador WHERE email = :email AND !(id = :userId AND '$rol' = 'admin')
        UNION ALL
        SELECT id FROM clientes WHERE email = :email AND !(id = :userId AND '$rol' = 'cliente')
        LIMIT 1
    ");
    $stmtCheck->execute([':email' => $email, ':userId' => $userId]);
    if ($stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está registrado por otro usuario.']);
        exit;
    }

    // Obtener contraseña actual almacenada
    $stmtUser = $pdo->prepare("SELECT password FROM $tabla WHERE id = :id LIMIT 1");
    $stmtUser->execute([':id' => $userId]);
    $currentRecord = $stmtUser->fetch();

    if (!$currentRecord) {
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
        exit;
    }

    $updatePassword = false;
    $hashedPassword = null;

    if (!empty($nuevaPassword)) {
        if (empty($passwordActual)) {
            echo json_encode(['success' => false, 'message' => 'Debes ingresar tu contraseña actual para establecer una nueva.']);
            exit;
        }

        if (!password_verify($passwordActual, $currentRecord['password'])) {
            echo json_encode(['success' => false, 'message' => 'La contraseña actual ingresada es incorrecta.']);
            exit;
        }

        $errorPassword = errorPasswordInvalida($nuevaPassword);
        if ($errorPassword !== '') {
            echo json_encode(['success' => false, 'message' => $errorPassword]);
            exit;
        }

        if ($nuevaPassword !== $confirmarPassword) {
            echo json_encode(['success' => false, 'message' => 'La nueva contraseña y su confirmación no coinciden.']);
            exit;
        }

        $hashedPassword = password_hash($nuevaPassword, PASSWORD_DEFAULT);
        $updatePassword = true;
    }

    if ($updatePassword) {
        $stmtUpdate = $pdo->prepare("
            UPDATE $tabla 
            SET nombre_completo = :nombre, email = :email, telefono = :telefono, password = :password 
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':nombre' => $nombreCompleto,
            ':email' => $email,
            ':telefono' => $telefono,
            ':password' => $hashedPassword,
            ':id' => $userId
        ]);
    } else {
        $stmtUpdate = $pdo->prepare("
            UPDATE $tabla 
            SET nombre_completo = :nombre, email = :email, telefono = :telefono 
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':nombre' => $nombreCompleto,
            ':email' => $email,
            ':telefono' => $telefono,
            ':id' => $userId
        ]);
    }

    // Actualizar nombre de sesión
    $_SESSION['usuario_nombre'] = $nombreCompleto;

    echo json_encode([
        'success' => true, 
        'message' => 'Perfil actualizado correctamente.',
        'user' => [
            'nombre_completo' => $nombreCompleto,
            'email' => $email,
            'telefono' => $telefono
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error al actualizar el perfil: ' . $e->getMessage()]);
}
?>
