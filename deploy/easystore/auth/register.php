<?php
session_start();
if (isset($_SESSION['usuario_id']) && $_SESSION['rol'] === 'cliente') {
    header('Location: ../index.html');
    exit;
}
require '../includes/db.php';

// Asegurar que la columna cedula exista en la tabla clientes
try {
    $stmtCheckCol = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'cedula'");
    if (!$stmtCheckCol->fetch()) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN cedula VARCHAR(50) NULL AFTER nombre_completo");
    }
} catch (Exception $e) {
    // Silencioso si la columna ya existe
}

$error = '';
$success = '';

$preguntasSeguridad = listarPreguntasSeguridad($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = Sanitizer::string($_POST['nombre_completo'] ?? '', 100);
    $prefijo_telefono = Sanitizer::string($_POST['prefijo_telefono'] ?? '', 10);
    $numero_telefono = Sanitizer::phone($_POST['numero_telefono'] ?? '');
    $telefono = $numero_telefono !== '' ? $prefijo_telefono . $numero_telefono : '';

    $numero_cedula = Sanitizer::phone($_POST['numero_cedula'] ?? '');
    $cedula = $numero_cedula !== '' ? 'V-' . $numero_cedula : '';

    $raw_email = Sanitizer::string($_POST['email'] ?? '', 255);
    $email = '';
    if ($raw_email !== '') {
        $email = str_contains($raw_email, '@') ? Sanitizer::email($raw_email) : Sanitizer::email($raw_email . '@gmail.com');
    }

    $usuario = Sanitizer::username($_POST['usuario'] ?? '', 30);
    $password = (string)($_POST['password'] ?? '');

    $seguridad = validarPreguntaSeguridad(
        $pdo,
        $_POST['pregunta_seguridad_id'] ?? 0,
        $_POST['respuesta_seguridad'] ?? ''
    );

    if ($nombre !== '' && $usuario !== '' && $password !== '' && $telefono !== '' && $cedula !== '') {
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario)) {
            $error = 'El usuario solo puede tener letras, números, puntos, guiones y guiones bajos.';
        } elseif (!preg_match('/^[\pL\s.\-]{3,80}$/u', $nombre)) {
            $error = 'El nombre debe contener solo letras y espacios.';
        } elseif ($email !== '' && !Sanitizer::isValidEmail($email)) {
            $error = 'El correo electrónico no es válido.';
        } elseif (($e = errorPasswordInvalida($password)) !== '') {
            $error = $e;
        } elseif ($seguridad['pregunta_id'] === 0) {
            $error = 'Debes elegir una pregunta de seguridad para poder recuperar tu clave.';
        } elseif ($seguridad['error'] !== '') {
            $error = $seguridad['error'];
        } elseif (!preg_match('/^\d{4,20}$/', $telefono)) {
            $error = 'El teléfono solo debe contener números.';
        } else {
            // Verificar si el usuario o email ya existen
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
                $error = 'El nombre de usuario o el email ya están registrados.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO clientes (usuario, password, nombre_completo, cedula, email, telefono, pregunta_seguridad_id, respuesta_seguridad)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute([
                    $usuario, $hashed_password, $nombre, $cedula, $email ?: null, $telefono,
                    $seguridad['pregunta_id'],
                    hashearRespuestaSeguridad($seguridad['respuesta'])
                ])) {
                    $success = 'Registro exitoso. ¡Ya puedes iniciar sesión!';
                } else {
                    $error = 'Error al registrar el usuario.';
                }
            }
        }
    } else {
        $error = 'Por favor rellena todos los campos obligatorios.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SISTEMA_NOMBRE; ?> | Crear Cuenta</title>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=3.4">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
        }
        .register-container {
            width: 100%;
            max-width: 520px;
        }
        .register-card {
            background: white;
            padding: 40px;
            border-radius: 30px;
            box-shadow: 0 40px 100px rgba(0,0,0,0.08);
            width: 100%;
            box-sizing: border-box;
        }
        .register-header { text-align: center; margin-bottom: 30px; }
        .register-header h2 {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .msg {
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: center;
        }
        .error-msg { background: #fff5f5; color: #c53030; border: 1px solid #fed7d7; }
        .success-msg { background: #f0fff4; color: #276749; border: 1px solid #c6f6d5; }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .full-width { grid-column: span 2; }
        
        .input-group { display: flex; flex-direction: column; gap: 5px; }
        .input-group label { font-size: 0.85rem; font-weight: 700; color: var(--text); }
        .input-group input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 15px;
            border-radius: 12px;
            border: 1.5px solid #edf2f7;
            background: #f8fafc;
            color: #1e293b;
            font-size: 0.95rem;
        }
        .input-group input:focus {
            border-color: var(--primary);
            outline: none;
            background: white;
        }
        .input-prefix-select {
            border: none;
            padding: 12px 4px 12px 8px;
            background: #edf2f7 !important;
            color: #1e293b !important;
            font-size: 0.92rem;
            font-weight: 700;
            outline: none;
            border-right: 1.5px solid #cbd5e1;
            cursor: pointer;
            width: 86px;
            min-width: 86px;
            flex-shrink: 0;
            box-sizing: border-box;
        }
        .input-prefix-select option {
            background-color: #ffffff !important;
            color: #1e293b !important;
            font-weight: 600;
        }
        .btn-register {
            width: 100%;
            padding: 15px;
            border-radius: 15px;
            border: none;
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 20px;
            transition: var(--transition);
        }
        .btn-register:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(67, 97, 238, 0.2); }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
            .register-card { padding: 25px; }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div style="font-size: 3rem; margin-bottom: 10px;">👋</div>
                <h2>Crea tu cuenta</h2>
                <p style="color: var(--text-light);">Únete a Tecnology y gestiona tus pedidos</p>
            </div>

            <?php if ($error): ?> <div class="msg error-msg"><?php echo Sanitizer::escapeHtml($error); ?></div> <?php endif; ?>
            <?php if ($success): ?> <div class="msg success-msg"><?php echo Sanitizer::escapeHtml($success); ?></div> <?php endif; ?>

            <form action="register.php" method="POST">
                <div class="form-grid">
                    <div class="input-group full-width">
                        <label for="nombre_completo">Nombre y Apellido</label>
                        <input type="text" name="nombre_completo" id="nombre_completo" required placeholder="Ej: Juan Pérez" value="<?php echo htmlspecialchars($_POST['nombre_completo'] ?? ''); ?>">
                    </div>

                    <div class="input-group">
                        <label>Teléfono</label>
                        <div style="display:flex; align-items:center; background:#f8fafc; border:1.5px solid #edf2f7; border-radius:12px; overflow:hidden;">
                            <select name="prefijo_telefono" id="prefijo_telefono" class="input-prefix-select">
                                <option value="0412" <?php echo (($_POST['prefijo_telefono'] ?? '0412') === '0412') ? 'selected' : ''; ?>>0412</option>
                                <option value="0422" <?php echo (($_POST['prefijo_telefono'] ?? '') === '0422') ? 'selected' : ''; ?>>0422</option>
                                <option value="0414" <?php echo (($_POST['prefijo_telefono'] ?? '') === '0414') ? 'selected' : ''; ?>>0414</option>
                                <option value="0424" <?php echo (($_POST['prefijo_telefono'] ?? '') === '0424') ? 'selected' : ''; ?>>0424</option>
                                <option value="0416" <?php echo (($_POST['prefijo_telefono'] ?? '') === '0416') ? 'selected' : ''; ?>>0416</option>
                                <option value="0426" <?php echo (($_POST['prefijo_telefono'] ?? '') === '0426') ? 'selected' : ''; ?>>0426</option>
                            </select>
                            <input type="text" name="numero_telefono" id="telefono" required placeholder="1234567" style="border:none; flex:1; min-width: 0; background:transparent; border-radius:0; padding:12px 15px; color:#1e293b;" inputmode="numeric" maxlength="7" value="<?php echo htmlspecialchars($_POST['numero_telefono'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="cedula">Cédula</label>
                        <div style="display:flex; align-items:center; background:#f8fafc; border:1.5px solid #edf2f7; border-radius:12px; overflow:hidden;">
                            <span style="padding:12px 14px; background:#edf2f7; color:#1e293b; font-weight:700; font-size:0.92rem; border-right:1.5px solid #cbd5e1; user-select:none;">V-</span>
                            <input type="text" name="numero_cedula" id="cedula" required placeholder="12345678" style="border:none; flex:1; min-width: 0; background:transparent; border-radius:0; padding:12px 15px; color:#1e293b;" inputmode="numeric" maxlength="12" value="<?php echo htmlspecialchars($_POST['numero_cedula'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="input-group full-width">
                        <label for="email">Gmail (Opcional)</label>
                        <div style="display:flex; align-items:center; background:#f8fafc; border:1.5px solid #edf2f7; border-radius:12px; padding-right:15px;">
                            <input type="text" name="email" id="email" placeholder="antho20005 (Opcional)" style="border:none; flex:1; background:transparent; color:#1e293b;" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            <span style="color:var(--text-light); font-weight:600;">@gmail.com</span>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="usuario">Usuario</label>
                        <input type="text" name="usuario" id="usuario" required placeholder="juanperez123" value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>">
                    </div>

                    <div class="input-group">
                        <label for="password">Contraseña (Mín. 8 caracteres)</label>
                        <input type="password" name="password" id="password" required minlength="8"
                               placeholder="••••••••" autocomplete="new-password" style="color:#1e293b;">
                    </div>

                    <div class="input-group full-width">
                        <label for="pregunta_seguridad_id">Pregunta de seguridad (para recuperar tu clave)</label>
                        <select name="pregunta_seguridad_id" id="pregunta_seguridad_id" required
                                style="width:100%; box-sizing:border-box; padding:12px 15px; border-radius:12px; border:1.5px solid #edf2f7; background:#f8fafc; color:#1e293b; font-size:0.95rem;">
                            <option value="">Selecciona una pregunta...</option>
                            <?php foreach ($preguntasSeguridad as $ps): ?>
                                <option value="<?php echo (int)$ps['id']; ?>" <?php echo ((int)($_POST['pregunta_seguridad_id'] ?? 0) === (int)$ps['id']) ? 'selected' : ''; ?>>
                                    <?php echo Sanitizer::escapeHtml($ps['pregunta']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="input-group full-width">
                        <label for="respuesta_seguridad">Respuesta de seguridad</label>
                        <input type="text" name="respuesta_seguridad" id="respuesta_seguridad" required
                               maxlength="100" placeholder="Ej: Tecky"
                               value="<?php echo Sanitizer::escapeHtml($_POST['respuesta_seguridad'] ?? ''); ?>">
                        <div style="font-size:0.78rem; color:var(--text-light); margin-top:6px;">
                            Recuerda bien esta respuesta: es tu única forma de recuperar la clave sin venir a la tienda.
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-register">Registrarme</button>
            </form>

            <div style="margin-top: 25px; text-align: center; font-size: 0.9rem; color: var(--text-light);">
                ¿Ya tienes cuenta? <a href="login.php" style="color: var(--primary); font-weight: 700; text-decoration: none;">Inicia sesión aquí</a>
            </div>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="../index.html" style="color: var(--text-light); text-decoration: none; font-size: 0.8rem;">← Volver a la tienda</a>
            </div>
        </div>
    </div>
    <script src="../assets/js/validaciones.js?v=1.0"></script>
    <script src="../assets/js/toggle-password.js?v=1.0"></script>
</body>
</html>
