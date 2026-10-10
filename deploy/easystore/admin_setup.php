<?php
session_start();
require 'includes/db.php';

// 1. Verificar si ya existe un administrador en la base de datos
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM administrador");
    $adminCount = $stmt->fetchColumn();

    if ($adminCount > 0) {
        // Ya existe un admin, bloquear acceso y redirigir al login
        header('Location: auth/login.php');
        exit;
    }
} catch (PDOException $e) {
    die("Error de base de datos. Por favor ejecuta setup.php primero.");
}

$error = '';

$preguntasSeguridad = listarPreguntasSeguridad($pdo);

$bancos_lista = [
    "0102 - Banco de Venezuela",
    "0104 - Banco Venezolano de Crédito",
    "0105 - Mercantil",
    "0108 - Provincial",
    "0114 - Bancaribe",
    "0115 - Banco Exterior",
    "0128 - Banco Caroní",
    "0134 - Banesco",
    "0137 - Banco Sofitasa",
    "0138 - Banco Plaza",
    "0151 - BFC Banco Fondo Común",
    "0156 - 100% Banco",
    "0157 - Bancosur",
    "0163 - Banco del Tesoro",
    "0166 - Banco Agrícola",
    "0168 - Bancrecer",
    "0169 - Mi Banco",
    "0171 - Banco Activo",
    "0172 - Bancamiga",
    "0174 - Banplus",
    "0175 - Banco Bicentenario",
    "0177 - Banfanb",
    "0191 - BNC",
    "Otro"
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recoger datos del formulario
    $usuario = trim($_POST['usuario'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $email_prefix = trim($_POST['email'] ?? '');
    $email = $email_prefix ? (str_contains($email_prefix, '@') ? $email_prefix : $email_prefix . '@gmail.com') : '';
    $nombre = trim($_POST['nombre'] ?? '');

    $seguridad = validarPreguntaSeguridad(
        $pdo,
        $_POST['pregunta_seguridad_id'] ?? 0,
        $_POST['respuesta_seguridad'] ?? ''
    );
    
    // Negocio
    $negocio_nombre = trim($_POST['negocio_nombre'] ?? '');
    $negocio_telefono_prefijo = trim($_POST['negocio_telefono_prefijo'] ?? '0412');
    $negocio_telefono_num = trim($_POST['negocio_telefono_num'] ?? '');
    $negocio_telefono = $negocio_telefono_num !== '' ? $negocio_telefono_prefijo . $negocio_telefono_num : '';
    
    $negocio_horario = trim($_POST['negocio_horario'] ?? '');
    $negocio_ubicacion = trim($_POST['negocio_ubicacion'] ?? '');

    // Pago Móvil
    $pm_banco = trim($_POST['pm_banco'] ?? '');
    $pm_telefono_prefijo = trim($_POST['pm_telefono_prefijo'] ?? '0412');
    $pm_telefono_num = trim($_POST['pm_telefono_num'] ?? '');
    $pm_telefono = $pm_telefono_num !== '' ? $pm_telefono_prefijo . $pm_telefono_num : '';

    $pm_cedula_num = trim($_POST['pm_cedula_num'] ?? '');
    $pm_cedula_limpia = preg_replace('/[^0-9]/', '', $pm_cedula_num);
    $pm_cedula = $pm_cedula_limpia !== '' ? 'V-' . $pm_cedula_limpia : '';

    // Transferencia
    $transf_banco = trim($_POST['transf_banco'] ?? '');
    $transf_cuenta = trim($_POST['transf_cuenta'] ?? '');
    $transf_titular = trim($_POST['transf_titular'] ?? '');
    $transf_cedula_num = trim($_POST['transf_cedula_num'] ?? '');
    $transf_cedula_limpia = preg_replace('/[^0-9]/', '', $transf_cedula_num);
    $transf_cedula = $transf_cedula_limpia !== '' ? 'V-' . $transf_cedula_limpia : '';

    if ($usuario && $password) {
        $usuarioValido = preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario) === 1;
        $passwordValida = errorPasswordInvalida($password) === '';
        $nombreValido = $nombre === '' || preg_match('/^[\pL\s.\-]{3,80}$/u', $nombre) === 1;
        $emailValido = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL);
        
        if (!$usuarioValido) {
            $error = 'El usuario solo puede tener letras, números, puntos, guiones y guiones bajos (3 a 30 caracteres).';
        } elseif (!$passwordValida) {
            $error = 'La contraseña debe tener al menos 8 caracteres e incluir letras y números.';
        } elseif (!$nombreValido) {
            $error = 'El nombre debe contener solo letras, espacios y puntos.';
        } elseif (!$emailValido) {
            $error = 'El correo electrónico no es válido.';
        } elseif ($seguridad['pregunta_id'] === 0) {
            $error = 'Debes elegir una pregunta de seguridad para poder recuperar tu clave.';
        } elseif ($seguridad['error'] !== '') {
            $error = $seguridad['error'];
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Crear el usuario administrador con datos del negocio y bancarios
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO administrador (
                        usuario, password, nombre_completo, email, telefono,
                        negocio_nombre, negocio_telefono, negocio_horario, negocio_ubicacion,
                        pm_banco, pm_telefono, pm_cedula,
                        transf_banco, transf_cuenta, transf_titular, transf_cedula,
                        pregunta_seguridad_id, respuesta_seguridad
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $usuario, $passHash, $nombre, $email, $negocio_telefono,
                    $negocio_nombre, $negocio_telefono, $negocio_horario, $negocio_ubicacion,
                    $pm_banco, $pm_telefono, $pm_cedula,
                    $transf_banco, $transf_cuenta, $transf_titular, $transf_cedula,
                    $seguridad['pregunta_id'], hashearRespuestaSeguridad($seguridad['respuesta'])
                ]);
                $admin_id = $pdo->lastInsertId();

                $pdo->commit();

                // Iniciar sesión automáticamente
                $_SESSION['usuario_id'] = $admin_id;
                $_SESSION['usuario_nombre'] = $nombre ?: $usuario;
                $_SESSION['rol'] = 'admin';

                header('Location: admin/index.php');
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Error al crear la cuenta de administrador: ' . $e->getMessage();
            }
        }
    } else {
        $error = 'El nombre de usuario y la contraseña son obligatorios.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración Inicial del Administrador | <?php echo SISTEMA_NOMBRE; ?></title>
    <link rel="stylesheet" href="assets/css/estilo.css?v=3.5">
    <style>
        body {
            background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 100%);
            min-height: 100vh;
            padding: 40px 20px;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        .setup-container {
            max-width: 850px;
            margin: 0 auto;
            background: white;
            border-radius: 28px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .setup-header {
            background: linear-gradient(135deg, var(--primary, #4361ee), var(--secondary, #3f37c9));
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .setup-header h1 { margin: 0; font-size: 2.2rem; font-weight: 800; }
        .setup-header p { margin-top: 10px; opacity: 0.95; font-size: 1.05rem; }
        
        .setup-body {
            padding: 40px;
        }
        
        .section-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.25rem;
            color: var(--primary, #4361ee);
            margin-bottom: 20px;
            padding-bottom: 8px;
            border-bottom: 2px solid rgba(67, 97, 238, 0.15);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .input-group { margin-bottom: 5px; display: flex; flex-direction: column; gap: 6px; }
        .input-group label { display: block; font-size: 0.88rem; font-weight: 700; color: #334155; }
        .input-group input, .input-group select {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 15px;
            border-radius: 12px;
            border: 1.5px solid #cbd5e1;
            background: white;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .input-group input:focus, .input-group select:focus {
            border-color: var(--primary, #4361ee);
            outline: none;
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }

        .phone-group, .cedula-group {
            display: flex;
            align-items: center;
            background: white;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
        }
        .phone-group select, .cedula-group select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #f1f5f9 !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 6px center;
            background-size: 11px;
            border: none;
            font-weight: 700;
            font-size: 0.95rem;
            color: #0f172a !important;
            outline: none;
            border-right: 1.5px solid #cbd5e1;
            cursor: pointer;
            box-sizing: border-box;
            flex-shrink: 0;
        }

        .phone-group select {
            width: 78px;
            min-width: 78px;
            padding: 12px 18px 12px 10px;
        }

        .cedula-group select {
            width: 62px;
            min-width: 62px;
            padding: 12px 18px 12px 10px;
        }

        .phone-group select option, .cedula-group select option {
            background: white;
            color: #0f172a;
            font-weight: 600;
            padding: 8px;
        }
        .phone-group input, .cedula-group input {
            border: none;
            flex: 1;
            min-width: 0;
            background: transparent;
            border-radius: 0;
            padding: 12px 15px;
        }
        .phone-group input:focus, .cedula-group input:focus {
            box-shadow: none;
        }

        .full-width { grid-column: 1 / -1; }

        .btn-submit {
            background: linear-gradient(135deg, var(--primary, #4361ee), var(--secondary, #3f37c9));
            color: white;
            border: none;
            padding: 18px 30px;
            font-size: 1.15rem;
            font-weight: 700;
            border-radius: 16px;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.25);
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(67, 97, 238, 0.35);
        }

        .error-box {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 600;
            font-size: 0.95rem;
        }

        @media (max-width: 650px) {
            .form-grid { grid-template-columns: 1fr; gap: 14px; }
            .setup-body { padding: 25px 20px; }
            .setup-header { padding: 30px 20px; }
        }
    </style>
</head>
<body>

<div class="setup-container">
    <div class="setup-header">
        <h1>👑 Cuenta de Administrador Master</h1>
        <p>Configura el acceso administrativo inicial y los datos principales de tu tienda.</p>
    </div>

    <div class="setup-body">
        <?php if ($error): ?>
            <div class="error-box" id="serverError">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div id="formError" class="error-box" style="display:none;"></div>

        <form method="POST" action="admin_setup.php" novalidate>

            <!-- SECCIÓN 1: CUENTA MASTER -->
            <div class="section-card">
                <div class="section-title">👤 1. Credenciales del Administrador</div>
                <div class="form-grid">
                    <div class="input-group">
                        <label for="usuario">Usuario (Para iniciar sesión)</label>
                        <input type="text" name="usuario" id="usuario" required placeholder="Ej: admin" value="<?php echo htmlspecialchars($usuario ?? ''); ?>">
                    </div>
                    
                    <div class="input-group">
                        <label for="password">Contraseña (Mín. 8 caracteres)</label>
                        <input type="password" name="password" id="password" required placeholder="••••••••" autocomplete="new-password">
                    </div>

                    <div class="input-group">
                        <label for="nombre">Nombre Completo</label>
                        <input type="text" name="nombre" id="nombre" placeholder="Ej: Carlos Mendoza" value="<?php echo htmlspecialchars($nombre ?? ''); ?>">
                    </div>

                    <div class="input-group">
                        <label for="email">Correo Electrónico</label>
                        <div style="display:flex; align-items:center; background:white; border:1.5px solid #cbd5e1; border-radius:12px; overflow:hidden;">
                            <?php 
                            $emailDisplay = $email ?? '';
                            if (str_ends_with($emailDisplay, '@gmail.com')) {
                                $emailDisplay = substr($emailDisplay, 0, -10);
                            }
                            ?>
                            <input type="text" name="email" id="email" placeholder="correo (solo usuario)" value="<?php echo htmlspecialchars($emailDisplay); ?>" style="border:none; flex:1; outline:none; padding:12px 15px; border-radius:0;">
                            <span style="color:#64748b; font-weight:600; padding-right:15px; background:white;">@gmail.com</span>
                        </div>
                    </div>

                    <div class="input-group full-width">
                        <label for="pregunta_seguridad_id">Pregunta de Seguridad (para recuperar tu clave)</label>
                        <select name="pregunta_seguridad_id" id="pregunta_seguridad_id" required>
                            <option value="">Selecciona una pregunta...</option>
                            <?php foreach ($preguntasSeguridad as $ps): ?>
                                <option value="<?php echo (int)$ps['id']; ?>" <?php echo ((int)($_POST['pregunta_seguridad_id'] ?? 0) === (int)$ps['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ps['pregunta']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="input-group full-width">
                        <label for="respuesta_seguridad">Respuesta de Seguridad</label>
                        <input type="text" name="respuesta_seguridad" id="respuesta_seguridad" required
                               maxlength="100" placeholder="Ej: Tecky"
                               value="<?php echo htmlspecialchars($_POST['respuesta_seguridad'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 2: DATOS DEL NEGOCIO -->
            <div class="section-card">
                <div class="section-title">🏪 2. Información General de la Tienda</div>
                <div class="form-grid">
                    <div class="input-group">
                        <label for="negocio_nombre">Nombre Comercial de la Tienda</label>
                        <input type="text" name="negocio_nombre" id="negocio_nombre" placeholder="Ej: Tecnology Store" value="<?php echo htmlspecialchars($negocio_nombre ?? ''); ?>">
                    </div>

                    <div class="input-group">
                        <label for="negocio_telefono_num">Teléfono Principal de Contacto</label>
                        <div class="phone-group">
                            <select name="negocio_telefono_prefijo">
                                <option value="0412" <?php echo ($negocio_telefono_prefijo ?? '') === '0412' ? 'selected' : ''; ?>>0412</option>
                                <option value="0414" <?php echo ($negocio_telefono_prefijo ?? '') === '0414' ? 'selected' : ''; ?>>0414</option>
                                <option value="0424" <?php echo ($negocio_telefono_prefijo ?? '') === '0424' ? 'selected' : ''; ?>>0424</option>
                                <option value="0416" <?php echo ($negocio_telefono_prefijo ?? '') === '0416' ? 'selected' : ''; ?>>0416</option>
                                <option value="0426" <?php echo ($negocio_telefono_prefijo ?? '') === '0426' ? 'selected' : ''; ?>>0426</option>
                                <option value="0422" <?php echo ($negocio_telefono_prefijo ?? '') === '0422' ? 'selected' : ''; ?>>0422</option>
                                <option value="0212" <?php echo ($negocio_telefono_prefijo ?? '') === '0212' ? 'selected' : ''; ?>>0212</option>
                            </select>
                            <input type="text" name="negocio_telefono_num" id="negocio_telefono_num" placeholder="1234567" inputmode="numeric" maxlength="7" value="<?php echo htmlspecialchars($negocio_telefono_num ?? ''); ?>">
                        </div>
                    </div>

                    <div class="input-group full-width">
                        <label for="negocio_ubicacion">Ubicación / Dirección Física (o enlace de Google Maps)</label>
                        <input type="text" name="negocio_ubicacion" id="negocio_ubicacion" placeholder="Ej: Av. Bolívar, C.C. Las Américas o enlace https://maps.google.com/..." value="<?php echo htmlspecialchars($negocio_ubicacion ?? ''); ?>">
                    </div>

                    <div class="input-group full-width">
                        <label for="negocio_horario">Horario de Atención</label>
                        <input type="text" name="negocio_horario" id="negocio_horario" placeholder="Ej: Lunes a Sábado de 8:00 AM a 6:00 PM" value="<?php echo htmlspecialchars($negocio_horario ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- SECCIÓN 3: MÉTODOS DE PAGO DE LA TIENDA -->
            <div class="section-card">
                <div class="section-title">💳 3. Métodos de Pago Receptor de Fondos</div>

                <!-- Pago Móvil -->
                <div style="background: white; border: 1.5px solid #e2e8f0; padding: 20px; border-radius: 16px; margin-bottom: 20px;">
                    <h4 style="margin-top:0; color:#1e293b; margin-bottom:15px; font-weight: 700;">📱 Datos de Pago Móvil</h4>
                    <div class="form-grid">
                        <div class="input-group">
                            <label for="pm_banco">Banco Emisor / Destino</label>
                            <select name="pm_banco" id="pm_banco">
                                <option value="">Seleccione Banco...</option>
                                <?php foreach ($bancos_lista as $b): ?>
                                    <option value="<?php echo $b; ?>" <?php echo ($pm_banco ?? '') === $b ? 'selected' : ''; ?>><?php echo $b; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="pm_telefono_num">Teléfono Pago Móvil</label>
                            <div class="phone-group">
                                <select name="pm_telefono_prefijo">
                                    <option value="0412" <?php echo ($pm_telefono_prefijo ?? '') === '0412' ? 'selected' : ''; ?>>0412</option>
                                    <option value="0414" <?php echo ($pm_telefono_prefijo ?? '') === '0414' ? 'selected' : ''; ?>>0414</option>
                                    <option value="0424" <?php echo ($pm_telefono_prefijo ?? '') === '0424' ? 'selected' : ''; ?>>0424</option>
                                    <option value="0416" <?php echo ($pm_telefono_prefijo ?? '') === '0416' ? 'selected' : ''; ?>>0416</option>
                                    <option value="0426" <?php echo ($pm_telefono_prefijo ?? '') === '0426' ? 'selected' : ''; ?>>0426</option>
                                    <option value="0422" <?php echo ($pm_telefono_prefijo ?? '') === '0422' ? 'selected' : ''; ?>>0422</option>
                                </select>
                                <input type="text" name="pm_telefono_num" id="pm_telefono_num" placeholder="1234567" inputmode="numeric" maxlength="7" value="<?php echo htmlspecialchars($pm_telefono_num ?? ''); ?>">
                            </div>
                        </div>
                        <div class="input-group full-width">
                            <label for="pm_cedula_num">Cédula del Titular Pago Móvil</label>
                            <div class="cedula-group">
                                <span style="background: #f1f5f9; padding: 12px 14px; font-weight: 700; font-size: 0.95rem; color: #0f172a; border-right: 1.5px solid #cbd5e1; user-select: none;">V-</span>
                                <input type="text" name="pm_cedula_num" id="pm_cedula_num" placeholder="12345678" inputmode="numeric" maxlength="12" value="<?php echo htmlspecialchars($pm_cedula_num ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transferencia Bancaria -->
                <div style="background: white; border: 1.5px solid #e2e8f0; padding: 20px; border-radius: 16px;">
                    <h4 style="margin-top:0; color:#1e293b; margin-bottom:15px; font-weight: 700;">🏦 Datos de Transferencia Bancaria</h4>
                    <div class="form-grid">
                        <div class="input-group">
                            <label for="transf_banco">Banco Receptores</label>
                            <select name="transf_banco" id="transf_banco">
                                <option value="">Seleccione Banco...</option>
                                <?php foreach ($bancos_lista as $b): ?>
                                    <option value="<?php echo $b; ?>" <?php echo ($transf_banco ?? '') === $b ? 'selected' : ''; ?>><?php echo $b; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label for="transf_cuenta">Número de Cuenta (20 Dígitos)</label>
                            <input type="text" name="transf_cuenta" id="transf_cuenta" placeholder="0105-0000-00-0000000000" inputmode="numeric" maxlength="23" value="<?php echo htmlspecialchars($transf_cuenta ?? ''); ?>">
                        </div>
                        <div class="input-group">
                            <label for="transf_titular">Nombre del Titular de la Cuenta</label>
                            <input type="text" name="transf_titular" id="transf_titular" placeholder="Ej: Inversiones Tecnology C.A." value="<?php echo htmlspecialchars($transf_titular ?? ''); ?>">
                        </div>
                        <div class="input-group">
                            <label for="transf_cedula_num">Cédula del Titular de la Cuenta</label>
                            <div class="cedula-group">
                                <span style="background: #f1f5f9; padding: 12px 14px; font-weight: 700; font-size: 0.95rem; color: #0f172a; border-right: 1.5px solid #cbd5e1; user-select: none;">V-</span>
                                <input type="text" name="transf_cedula_num" id="transf_cedula_num" placeholder="12345678" inputmode="numeric" maxlength="12" value="<?php echo htmlspecialchars($transf_cedula_num ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">🚀 Guardar Configuración y Entrar al Panel Admin</button>
        </form>
    </div>
</div>

<script src="assets/js/validaciones.js?v=1.0"></script>
<script src="assets/js/toggle-password.js?v=1.0"></script>
<script>
    // Validaciones del formulario antes de enviar
    const form = document.querySelector('form');
    const formError = document.getElementById('formError');

    const rules = {
        usuario: value => /^[A-Za-z0-9._-]{3,30}$/.test(value) ? '' : 'El usuario solo puede contener letras, números, puntos y guiones (3-30 caracteres).',
        password: value => value.length >= 8 && /[0-9]/.test(value) && /[\p{L}]/u.test(value) ? '' : 'La contraseña debe tener al menos 8 caracteres e incluir letras y números.',
        email: value => value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? '' : 'El correo electrónico no es válido.',
        nombre: value => value === '' || /^[\pL\s.\-]{3,80}$/u.test(value) ? '' : 'El nombre debe contener solo letras, espacios y puntos.',
        respuesta_seguridad: value => value.trim().length >= 3 ? '' : 'La respuesta de seguridad debe tener al menos 3 caracteres.'
    };

    const showError = message => {
        const serverError = document.getElementById('serverError');
        if (serverError) serverError.style.display = 'none';
        formError.textContent = '⚠️ ' + message;
        formError.style.display = 'block';
        window.scrollTo({ top: formError.offsetTop - 20, behavior: 'smooth' });
    };

    form.addEventListener('submit', event => {
        let formIsValid = true;
        
        Object.keys(rules).forEach(name => {
            const field = form.querySelector(`[name="${name}"]`);
            if (!field) return;
            const message = rules[name](field.value.trim());
            if (message && formIsValid) {
                showError(message);
                formIsValid = false;
                field.focus();
            }
        });

        if (!formIsValid) {
            event.preventDefault();
        }
    });
</script>
</body>
</html>
