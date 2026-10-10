<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../includes/db.php';
$stmtNeg = $pdo->query("SELECT negocio_nombre FROM administrador ORDER BY id ASC LIMIT 1");
$rowNeg = $stmtNeg ? $stmtNeg->fetch(PDO::FETCH_ASSOC) : null;
$nombreTienda = !empty($rowNeg['negocio_nombre']) ? htmlspecialchars($rowNeg['negocio_nombre']) : 'Tecnology';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Cuenta | <?php echo $nombreTienda; ?></title>
    <script>
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark-theme');
        }
    </script>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=3.6">
    <style>
        body {
            background-color: var(--bg, #f8f9fa);
            color: var(--text, #212529);
            min-height: 100vh;
        }
        .account-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .account-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .account-card {
            background: var(--card-bg, #ffffff);
            color: var(--text, #212529);
            padding: 30px;
            border-radius: 24px;
            box-shadow: var(--shadow, 0 10px 30px rgba(0,0,0,0.05));
            margin-bottom: 30px;
        }
        .account-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary, #4361ee);
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text);
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 14px;
            border: 1.5px solid rgba(150, 150, 150, 0.2);
            background: var(--bg);
            color: var(--text);
            font-size: 0.95rem;
            outline: none;
            transition: var(--transition);
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }
        .form-control[readonly] {
            opacity: 0.7;
            cursor: not-allowed;
            background: rgba(150,150,150,0.1);
        }
        .phone-input-group {
            display: flex;
            align-items: center;
            border: 1.5px solid rgba(150, 150, 150, 0.2);
            border-radius: 14px;
            overflow: hidden;
            background: var(--bg);
        }
        .phone-input-group select {
            padding: 12px;
            border: none;
            background: transparent;
            color: var(--text);
            font-weight: 600;
            outline: none;
            cursor: pointer;
            border-right: 1.5px solid rgba(150, 150, 150, 0.2);
        }
        .phone-input-group input {
            border: none;
            border-radius: 0;
            flex: 1;
            background: transparent;
        }
        .btn-save {
            background: var(--primary, #4361ee);
            color: white;
            padding: 14px 28px;
            border-radius: 14px;
            border: none;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3);
        }
        .alert-msg {
            padding: 14px 20px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            font-weight: 600;
            display: none;
        }
        .alert-msg.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-msg.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .nav-link-btn {
            text-decoration: none;
            color: var(--primary);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 12px;
            background: rgba(67, 97, 238, 0.08);
            transition: var(--transition);
        }
        .nav-link-btn:hover {
            background: rgba(67, 97, 238, 0.18);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <a href="../index.html" style="text-decoration: none; color: inherit;">
                    <h1>🛒 <?php echo $nombreTienda; ?></h1>
                </a>
            </div>
            <div class="header-actions">
                <button type="button" class="help-btn-header" onclick="abrirModalAyuda('cliente')" title="Centro de Ayuda / Manual de Usuario">
                    ❓ Ayuda
                </button>
                <a href="mis_pedidos.php" class="nav-link-btn">📦 Mis Pedidos</a>
                <a href="../index.html" class="nav-link-btn">🏠 Tienda</a>
                <a href="../auth/logout.php" class="nav-link-btn" style="color: var(--danger, #e63946); background: rgba(230, 57, 70, 0.08);">🚪 Salir</a>
            </div>
        </div>
    </header>

    <div class="account-container">
        <div class="account-header">
            <div>
                <h1 style="margin:0; font-size: 1.8rem;">⚙️ Configuración de Cuenta</h1>
                <p style="color: var(--text-light, #6c757d); margin-top: 5px;">Administra tu información personal y seguridad</p>
            </div>
        </div>

        <div id="alertBox" class="alert-msg"></div>

        <div class="account-card">
            <h2>👤 Información Personal</h2>
            <form id="perfilForm" onsubmit="guardarPerfil(event)">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="usuario">Nombre de Usuario</label>
                        <input type="text" id="usuario" class="form-control" readonly autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="nombre_completo">Nombre Completo</label>
                        <input type="text" id="nombre_completo" class="form-control" required placeholder="Tu Nombre Completo">
                    </div>

                    <div class="form-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" id="email" class="form-control" required placeholder="ejemplo@correo.com">
                    </div>

                    <div class="form-group">
                        <label>Teléfono de Contacto</label>
                        <div class="phone-input-group">
                            <select id="prefijoTelefono">
                                <option value="0412">0412</option>
                                <option value="0422">0422</option>
                                <option value="0414">0414</option>
                                <option value="0424">0424</option>
                                <option value="0416">0416</option>
                                <option value="0426">0426</option>
                            </select>
                            <input type="text" id="telefonoNumero" class="form-control" placeholder="1234567" maxlength="7" inputmode="numeric">
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn-save">
                        💾 Guardar Información
                    </button>
                </div>
            </form>
        </div>

        <div class="account-card">
            <h2>🛟 Pregunta de Seguridad</h2>
            <p style="color: var(--text-light); margin-top:-10px; margin-bottom:20px; font-size:0.92rem;">
                Con esto podrás recuperar tu contraseña sin venir a la tienda.
            </p>
            <form id="seguridadForm" onsubmit="guardarSeguridad(event)">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="pregunta_seguridad">Pregunta</label>
                        <select id="pregunta_seguridad" class="form-control" required></select>
                    </div>

                    <div class="form-group">
                        <label for="respuesta_seguridad">Respuesta</label>
                        <input type="text" id="respuesta_seguridad" class="form-control" placeholder="Tu respuesta" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="password_actual_seguridad">Contraseña Actual</label>
                        <input type="password" id="password_actual_seguridad" class="form-control" placeholder="••••••••" autocomplete="current-password">
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn-save">
                        🛡️ Guardar Pregunta
                    </button>
                </div>
            </form>
        </div>

        <div class="account-card">
            <h2>🔒 Cambiar Contraseña</h2>
            <form id="passwordForm" onsubmit="guardarPassword(event)">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="password_actual">Contraseña Actual</label>
                        <input type="password" id="password_actual" class="form-control" placeholder="••••••••" autocomplete="current-password">
                    </div>

                    <div class="form-group">
                        <label for="nueva_password">Nueva Contraseña</label>
                        <input type="password" id="nueva_password" class="form-control"
                               placeholder="Mínimo 8 caracteres" autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label for="confirmar_password">Confirmar Nueva Contraseña</label>
                        <input type="password" id="confirmar_password" class="form-control"
                               placeholder="Repite la contraseña" autocomplete="new-password">
                        <div id="hintConfirmar" style="font-size:0.8rem; color:var(--text-light); margin-top:5px;">
                            Las dos contraseñas deben coincidir.
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn-save">
                        🔑 Actualizar Contraseña
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAlert(message, type = 'success') {
            const alertBox = document.getElementById('alertBox');
            alertBox.textContent = message;
            alertBox.className = `alert-msg ${type}`;
            alertBox.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            setTimeout(() => {
                alertBox.style.display = 'none';
            }, 5000);
        }

        function cargarPerfil() {
            fetch('../api/get_perfil.php')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.profile) {
                        const p = data.profile;

                        const selSeguridad = document.getElementById('pregunta_seguridad');
                        if (selSeguridad) {
                            selSeguridad.innerHTML = '<option value="">Selecciona una pregunta...</option>';
                            (p.preguntas_seguridad || []).forEach(item => {
                                const opt = document.createElement('option');
                                opt.value = item.id;
                                opt.textContent = item.pregunta;
                                selSeguridad.appendChild(opt);
                            });

                            if (!p.tiene_pregunta_seguridad) {
                                document.getElementById('seguridadForm')
                                    .insertAdjacentHTML('afterbegin',
                                        '<div style="background:#fff4e5; color:#9a3412; border:1px solid #fed7aa; padding:14px 20px; border-radius:14px; margin-bottom:20px; font-size:0.92rem; font-weight:600;">\u26A0\uFE0F A\u00FAn no tienes pregunta de seguridad. Sin ella no podr\u00E1s recuperar tu clave si la olvidas.</div>');
                            }
                        }

                        document.getElementById('usuario').value = p.usuario || '';
                        document.getElementById('nombre_completo').value = p.nombre_completo || '';
                        document.getElementById('email').value = p.email || '';

                        if (p.telefono && p.telefono.length >= 10) {
                            const pref = p.telefono.substring(0, 4);
                            const num = p.telefono.substring(4);
                            const sel = document.getElementById('prefijoTelefono');
                            if ([...sel.options].some(o => o.value === pref)) {
                                sel.value = pref;
                                document.getElementById('telefonoNumero').value = num;
                            } else {
                                document.getElementById('telefonoNumero').value = p.telefono;
                            }
                        } else {
                            document.getElementById('telefonoNumero').value = p.telefono || '';
                        }
                    } else {
                        showAlert(data.message || 'Error al cargar perfil', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showAlert('Error al conectar con el servidor', 'error');
                });
        }

        function guardarPerfil(e) {
            e.preventDefault();
            const nombre = document.getElementById('nombre_completo').value.trim();
            const email = document.getElementById('email').value.trim();
            const prefijo = document.getElementById('prefijoTelefono').value;
            const numTel = document.getElementById('telefonoNumero').value.trim();

            const telefono = numTel ? (prefijo + numTel) : '';

            const payload = {
                nombre_completo: nombre,
                email: email,
                telefono: telefono
            };

            fetch('../api/update_perfil.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('Error al actualizar el perfil', 'error');
            });
        }

        function guardarSeguridad(e) {
            e.preventDefault();
            const pregunta = document.getElementById('pregunta_seguridad').value;
            const respuesta = document.getElementById('respuesta_seguridad').value.trim();
            const actual = document.getElementById('password_actual_seguridad').value;

            if (!pregunta) {
                showAlert('Selecciona una pregunta de seguridad', 'error');
                return;
            }
            if (respuesta.length < 3) {
                showAlert('La respuesta debe tener al menos 3 caracteres', 'error');
                return;
            }
            if (!actual) {
                showAlert('Ingresa tu contraseña actual para confirmar el cambio', 'error');
                return;
            }

            fetch('../api/update_perfil.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: 'seguridad',
                    pregunta_seguridad_id: pregunta,
                    respuesta_seguridad: respuesta,
                    password_actual: actual
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    document.getElementById('respuesta_seguridad').value = '';
                    document.getElementById('password_actual_seguridad').value = '';
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('Error al guardar la pregunta de seguridad', 'error');
            });
        }

        function guardarPassword(e) {
            e.preventDefault();
            const actual = document.getElementById('password_actual').value;
            const nueva = document.getElementById('nueva_password').value;
            const confirmar = document.getElementById('confirmar_password').value;

            if (!actual) {
                showAlert('Ingresa tu contraseña actual', 'error');
                return;
            }
            if (!nueva || nueva.length < 8) {
                showAlert('La nueva contraseña debe tener al menos 8 caracteres', 'error');
                return;
            }
            if (!/[0-9]/.test(nueva) || !/[a-zA-Z]/.test(nueva)) {
                showAlert('La nueva contraseña debe incluir letras y números', 'error');
                return;
            }
            if (nueva !== confirmar) {
                showAlert('La nueva contraseña y su confirmación no coinciden', 'error');
                return;
            }

            // Limpia los campos de clave al guardar.
            ['password_actual', 'nueva_password', 'confirmar_password'].forEach(id => {
                const campo = document.getElementById(id);
                if (!campo) return;
                campo.value = '';
                if (campo.type === 'text') campo.type = 'password';
            });
            document.getElementById('password_actual_seguridad').value = '';
            document.getElementById('respuesta_seguridad').value = '';

            const nombre = document.getElementById('nombre_completo').value.trim();
            const email = document.getElementById('email').value.trim();
            const prefijo = document.getElementById('prefijoTelefono').value;
            const numTel = document.getElementById('telefonoNumero').value.trim();
            const telefono = numTel ? (prefijo + numTel) : '';

            const payload = {
                nombre_completo: nombre,
                email: email,
                telefono: telefono,
                password_actual: actual,
                nueva_password: nueva,
                confirmar_password: confirmar
            };

            fetch('../api/update_perfil.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    document.getElementById('passwordForm').reset();
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('Error al actualizar la contraseña', 'error');
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            cargarPerfil();

            // Aviso en vivo cuando las dos contraseñas no coinciden.
            const campoClave = document.getElementById('nueva_password');
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
    <script src="../assets/js/toggle-password.js?v=1.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="../assets/js/manual.js?v=1.0"></script>
</body>
</html>
