<?php
/**
 * Recuperacion de clave por preguntas de seguridad.
 *
 * Flujo en 3 pasos dentro de la misma pagina:
 *   1) El usuario indica su usuario o correo.
 *   2) Responde su pregunta de seguridad.
 *   3) Define la nueva clave.
 *
 * Sin correo ni SMTP: la verificacion es 100% local.
 */
session_start();
require '../includes/db.php';

$error = '';
$success = '';

// El estado del paso 2 y 3 vive en la sesion. El paso 3 exige que el paso 2
// haya pasado y que no haya expirado.
if (!isset($_SESSION['recupera'])) {
    $_SESSION['recupera'] = [
        'paso'      => 1,
        'rol'       => null,
        'usuario_id'=> null,
        'expira'    => 0,
        'intentos'  => 0,
        'ultimo'    => 0,
    ];
}

$TOLERANCIA = 300; // 5 minutos para completar el paso 3.

function pasoActual(): int {
    return (int)($_SESSION['recupera']['paso'] ?? 1);
}

function reiniciarRecuperacion(): void {
    $_SESSION['recupera'] = [
        'paso' => 1, 'rol' => null, 'usuario_id' => null,
        'expira' => 0, 'intentos' => 0, 'ultimo' => 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paso = (int)($_POST['paso'] ?? 1);

    // El paso 3 caduca: vuelve al inicio para no dejar una sesion a medias.
    if (pasoActual() === 3 && time() > (int)$_SESSION['recupera']['expira']) {
        reiniciarRecuperacion();
        $error = 'El tiempo para cambiar la contraseña se agotó. Vuelve a intentarlo.';
        $paso = 1;
    }

    // Bloqueo por intentos fallidos (frena fuerza bruta sobre las respuestas).
    $espera = segundosEsperaRecuperacion($_SESSION['recupera']);
    if ($espera > 0) {
        $minutos = (int)ceil($espera / 60);
        $error = "Demasiados intentos fallidos. Espera $minutos minuto(s) antes de volver a intentarlo.";
    } elseif ($paso === 1) {
        // ---- PASO 1: localizar la cuenta ----
        $identificador = Sanitizer::string($_POST['identificador'] ?? '', 80);

        if ($identificador === '' || !preg_match('/^[A-Za-z0-9._@-]{3,80}$/', $identificador)) {
            $error = 'Escribe un usuario o correo válido.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT * FROM (
                    SELECT id, usuario, email, nombre_completo, telefono,
                           'admin' AS rol, pregunta_seguridad_id, respuesta_seguridad
                    FROM administrador
                    WHERE usuario = :x OR email = :x
                 UNION ALL
                    SELECT id, usuario, email, nombre_completo, telefono,
                           'cliente' AS rol, pregunta_seguridad_id, respuesta_seguridad
                    FROM clientes
                    WHERE usuario = :x OR email = :x
                ) AS u LIMIT 1"
            );
            $stmt->execute([':x' => $identificador]);
            $cuenta = $stmt->fetch();

            if (!$cuenta) {
                // No se revela si el usuario existe o no.
                $error = 'No encontramos una cuenta con ese usuario o correo.';
                registrarIntentoRecuperacion($_SESSION['recupera']);
            } elseif (empty($cuenta['pregunta_seguridad_id']) || empty($cuenta['respuesta_seguridad'])) {
                // Cuentas creadas antes de esta funcionalidad.
                $error = 'Esta cuenta no tiene preguntas de seguridad configuradas. '
                       . 'Pide al administrador de la tienda que restablezca tu clave.';
                registrarIntentoRecuperacion($_SESSION['recupera']);
            } else {
                $_SESSION['recupera']['paso'] = 2;
                $_SESSION['recupera']['rol'] = $cuenta['rol'];
                $_SESSION['recupera']['usuario_id'] = (int)$cuenta['id'];
                $_SESSION['recupera']['pregunta_id'] = (int)$cuenta['pregunta_seguridad_id'];
                $_SESSION['recupera']['respuesta_hash'] = $cuenta['respuesta_seguridad'];
            }
        }
    } elseif ($paso === 2) {
        // ---- PASO 2: validar la respuesta ----
        $respuesta = (string)($_POST['respuesta'] ?? '');

        if ($respuesta === '') {
            $error = 'Escribe la respuesta de seguridad.';
        } elseif (!verificarRespuestaSeguridad($respuesta, $_SESSION['recupera']['respuesta_hash'] ?? null)) {
            $error = 'La respuesta no es correcta.';
            registrarIntentoRecuperacion($_SESSION['recupera']);
        } else {
            limpiarIntentosRecuperacion($_SESSION['recupera']);
            $_SESSION['recupera']['paso'] = 3;
            $_SESSION['recupera']['expira'] = time() + $TOLERANCIA;
            unset($_SESSION['recupera']['respuesta_hash']);
        }
    } elseif ($paso === 3) {
        // ---- PASO 3: guardar la nueva clave ----
        $nueva = (string)($_POST['password'] ?? '');
        $confirmar = (string)($_POST['confirmar_password'] ?? '');

        $errorPassword = errorPasswordInvalida($nueva);

        if ($errorPassword !== '') {
            $error = $errorPassword;
        } elseif ($nueva !== $confirmar) {
            $error = 'La contraseña y su confirmación no coinciden.';
        } else {
            $tabla = ($_SESSION['recupera']['rol'] === 'admin') ? 'administrador' : 'clientes';
            $usuarioId = (int)$_SESSION['recupera']['usuario_id'];

            $stmt = $pdo->prepare("UPDATE $tabla SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);

            if ($stmt->rowCount() > 0) {
                $success = 'Tu contraseña fue actualizada. Ya puedes iniciar sesión.';
                reiniciarRecuperacion();
            } else {
                $error = 'No se pudo actualizar la contraseña. Intenta de nuevo.';
            }
        }
    }
}

$preguntas = listarPreguntasSeguridad($pdo);
$preguntaActual = '';
if (pasoActual() === 2) {
    $preguntaActual = textoPreguntaSeguridad($pdo, (int)($_SESSION['recupera']['pregunta_id'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SISTEMA_NOMBRE; ?> | Recuperar Clave</title>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=3.4">
    <style>
        body {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        .recupera-container { width: 100%; max-width: 480px; }
        .recupera-card {
            background: white; padding: 45px; border-radius: 40px;
            box-shadow: 0 40px 100px rgba(0,0,0,0.1); text-align: center;
        }
        .recupera-card h2 {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            font-size: 1.8rem; margin-bottom: 10px; font-weight: 800;
        }
        .error-msg {
            background: #fff5f5; color: #c53030; padding: 12px;
            border-radius: 12px; margin-bottom: 20px; font-size: 0.9rem;
            border: 1px solid #fed7d7;
        }
        .success-msg {
            background: #f0fff4; color: #276749; padding: 12px;
            border-radius: 12px; margin-bottom: 20px; font-size: 0.9rem;
            border: 1px solid #c6f6d5;
        }
        .input-group { text-align: left; margin-bottom: 20px; }
        .input-group label { font-size: 0.85rem; font-weight: 700; color: var(--text); margin-left: 5px; }
        .input-group input {
            width: 100%; box-sizing: border-box; padding: 16px 20px;
            border-radius: 18px; border: 1.5px solid #edf2f7;
            background: #f8fafc; font-size: 1rem; margin-top: 5px;
        }
        .input-group input:focus {
            border-color: var(--primary); background: white; outline: none;
            box-shadow: 0 0 0 5px rgba(67, 97, 238, 0.05);
        }
        .btn-enviar {
            width: 100%; padding: 18px; border-radius: 18px; border: none;
            background: var(--primary); color: white; font-weight: 700;
            font-size: 1.05rem; cursor: pointer; transition: var(--transition);
        }
        .btn-enviar:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(67, 97, 238, 0.3); }
        .pregunta-box {
            background: #f8fafc; border: 1.5px solid #edf2f7; border-radius: 18px;
            padding: 18px; margin-bottom: 20px; text-align: left;
        }
        .pregunta-box strong { color: var(--primary); font-size: 0.95rem; }
        .pasos { display: flex; gap: 8px; justify-content: center; margin-bottom: 25px; }
        .paso-dot {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; font-weight: 700;
            background: #edf2f7; color: var(--text-light);
        }
        .paso-dot.activo { background: var(--primary); color: white; }
        .paso-dot.hecho { background: #d4edda; color: #155724; }
        .hint { font-size: 0.8rem; color: var(--text-light); margin-top: 10px; }
    </style>
</head>
<body>
    <div class="recupera-container">
        <div class="recupera-card">
            <div style="font-size: 3rem; margin-bottom: 15px;">🛟</div>
            <h2>Recuperar clave</h2>

            <?php if (pasoActual() < 3): ?>
                <div class="pasos">
                    <div class="paso-dot <?php echo pasoActual() >= 1 ? (pasoActual() === 1 ? 'activo' : 'hecho') : ''; ?>">1</div>
                    <div class="paso-dot <?php echo pasoActual() === 2 ? 'activo' : ''; ?>">2</div>
                    <div class="paso-dot">3</div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-msg"><?php echo Sanitizer::escapeHtml($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-msg"><?php echo Sanitizer::escapeHtml($success); ?></div>
            <?php endif; ?>

            <?php if (pasoActual() === 1): ?>
                <p style="color: var(--text-light); margin-bottom: 30px;">Escribe tu usuario o correo para comenzar</p>
                <form action="recuperar_clave.php" method="POST">
                    <input type="hidden" name="paso" value="1">
                    <div class="input-group">
                        <label for="identificador">Usuario o Email</label>
                        <input type="text" name="identificador" id="identificador" required
                               placeholder="Tu usuario o correo" autofocus>
                    </div>
                    <button type="submit" class="btn-enviar">Continuar</button>
                </form>

            <?php elseif (pasoActual() === 2): ?>
                <div class="pregunta-box">
                    <strong><?php echo Sanitizer::escapeHtml($preguntaActual); ?></strong>
                </div>
                <form action="recuperar_clave.php" method="POST">
                    <input type="hidden" name="paso" value="2">
                    <div class="input-group">
                        <label for="respuesta">Tu respuesta</label>
                        <input type="text" name="respuesta" id="respuesta" required
                               placeholder="Escribe tu respuesta" autofocus>
                        <div class="hint">No importa si usas mayúsculas o agregas espacios.</div>
                    </div>
                    <button type="submit" class="btn-enviar">Verificar respuesta</button>
                </form>

            <?php else: ?>
                <p style="color: var(--text-light); margin-bottom: 30px;">Elige tu nueva contraseña</p>
                <form action="recuperar_clave.php" method="POST">
                    <input type="hidden" name="paso" value="3">
                    <div class="input-group">
                        <label for="password">Nueva contraseña</label>
                        <input type="password" name="password" id="password" required
                               minlength="8" placeholder="Mínimo 8 caracteres"
                               autocomplete="new-password" autofocus>
                        <div class="hint">Mínimo 8 caracteres, con letras y números.</div>
                    </div>
                    <div class="input-group">
                        <label for="confirmar_password">Repetir contraseña</label>
                        <input type="password" name="confirmar_password" id="confirmar_password" required
                               minlength="8" placeholder="Repite la contraseña"
                               autocomplete="new-password">
                        <div class="hint" id="hintConfirmar">Las dos contraseñas deben coincidir.</div>
                    </div>

                    <button type="submit" class="btn-enviar">Guardar nueva contraseña</button>
                </form>
            <?php endif; ?>

            <div style="margin-top: 30px; font-size: 0.9rem; color: var(--text-light);">
                ¿Recordaste tu clave? <a href="login.php" style="color: var(--primary); font-weight: 700; text-decoration: none;">Inicia sesión</a>
            </div>
            <div style="margin-top: 20px;">
                <a href="../index.html" style="color: var(--text-light); text-decoration: none; font-size: 0.8rem;">← Volver a la tienda</a>
            </div>
        </div>
    </div>

    <script src="../assets/js/toggle-password.js?v=1.0"></script>
    <script>
        // Aviso en vivo cuando las dos contraseñas no coinciden.
        document.addEventListener('DOMContentLoaded', function () {
            const campoClave = document.getElementById('password');
            const campoRepetir = document.getElementById('confirmar_password');
            const avisoRepetir = document.getElementById('hintConfirmar');
            if (!campoClave || !campoRepetir || !avisoRepetir) return;

            function revisarCoincidencia() {
                if (!campoRepetir.value) {
                    avisoRepetir.textContent = 'Las dos contraseñas deben coincidir.';
                    avisoRepetir.style.color = 'var(--text-light)';
                    avisoRepetir.style.fontWeight = 'normal';
                    return;
                }
                const coinciden = campoClave.value === campoRepetir.value;
                avisoRepetir.textContent = coinciden
                    ? '✓ Las contraseñas coinciden.'
                    : '✗ Las contraseñas no coinciden.';
                avisoRepetir.style.color = coinciden ? '#276749' : '#c53030';
                avisoRepetir.style.fontWeight = '700';
            }

            campoRepetir.addEventListener('input', revisarCoincidencia);
            campoClave.addEventListener('input', revisarCoincidencia);
        });
    </script>
</body>
</html>