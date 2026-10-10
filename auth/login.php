<?php
session_start();
require '../includes/db.php';

$error = '';

// Verificar si hay algún administrador creado en el sistema
try {
    $stmtAdmin = $pdo->query("SELECT COUNT(*) FROM administrador");
    $adminCount = $stmtAdmin->fetchColumn();

    if ($adminCount == 0) {
        // No hay administrador, redirigir al setup
        header('Location: ../admin_setup.php');
        exit;
    }
} catch (PDOException $e) {
    // Si la tabla no existe, setup.php no se ha corrido o algo similar
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = Sanitizer::string($_POST['usuario'] ?? '', 80);
    $password = (string)($_POST['password'] ?? '');

    if ($usuario !== '' && $password !== '' && strlen($usuario) >= 3 && strlen($usuario) <= 80 && preg_match('/^[A-Za-z0-9._@-]{3,80}$/', $usuario)) {
        $stmt = $pdo->prepare(
            "SELECT * FROM (
                SELECT id, usuario, password, nombre_completo, email, telefono, 'admin' AS rol
                FROM administrador
                WHERE usuario = :usuario OR email = :usuario
             UNION ALL
                SELECT id, usuario, password, nombre_completo, email, telefono, 'cliente' AS rol
                FROM clientes
                WHERE usuario = :usuario OR email = :usuario
            ) AS u
            LIMIT 1"
        );
        $stmt->execute([':usuario' => $usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nombre'] = $user['nombre_completo'] ?? $user['usuario'];
            $_SESSION['rol'] = $user['rol'];

            if ($user['rol'] === 'admin') {
                header('Location: ../admin/index.php');
            } else {
                header('Location: ../index.html');
            }
            exit;
        } else {
            $error = 'Credenciales incorrectas';
        }
    } else {
        $error = 'Por favor rellena todos los campos correctamente';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SISTEMA_NOMBRE; ?> | Iniciar Sesión</title>
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
        .login-container {
            width: 100%;
            max-width: 450px;
        }
        .login-card {
            background: white;
            padding: 50px;
            border-radius: 40px;
            box-shadow: 0 40px 100px rgba(0,0,0,0.1);
            text-align: center;
        }
        .login-header h2 {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 800;
        }
        .error-msg {
            background: #fff5f5;
            color: #c53030;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            border: 1px solid #fed7d7;
        }
        .input-group { text-align: left; margin-bottom: 20px; }
        .input-group label { font-size: 0.85rem; font-weight: 700; color: var(--text); margin-left: 5px; }
        .input-group input {
            width: 100%;
            padding: 16px 20px;
            border-radius: 18px;
            border: 1.5px solid #edf2f7;
            background: #f8fafc;
            font-size: 1rem;
            margin-top: 5px;
        }
        .input-group input:focus {
            border-color: var(--primary);
            background: white;
            outline: none;
            box-shadow: 0 0 0 5px rgba(67, 97, 238, 0.05);
        }
        .btn-login {
            width: 100%;
            padding: 18px;
            border-radius: 18px;
            border: none;
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 10px;
        }
        .btn-login:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(67, 97, 238, 0.3); }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div style="font-size: 0.95rem; font-weight: 800; letter-spacing: 3px; color: var(--secondary); margin-bottom: 12px; text-transform: uppercase;"><?php echo SISTEMA_NOMBRE; ?></div>
                <div style="font-size: 3rem; margin-bottom: 15px;">🔑</div>
                <h2>Bienvenido</h2>
                <p style="color: var(--text-light); margin-bottom: 30px;">Ingresa a tu cuenta para comprar</p>
            </div>

            <?php if ($error): ?>
                <div class="error-msg"><?php echo Sanitizer::escapeHtml($error); ?></div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="input-group">
                    <label for="usuario">Usuario o Email</label>
                    <input type="text" name="usuario" id="usuario" required placeholder="Tu usuario" autofocus>
                </div>
                <div class="input-group">
                    <label for="password">Contraseña</label>
                    <div style="position:relative; display:flex; align-items:center;">
                        <input type="password" name="password" id="password" required placeholder="••••••••" autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn-login">Iniciar Sesión</button>
            </form>
            
            <div style="margin-top: 15px; font-size: 0.9rem; color: var(--text-light);">
                ¿Olvidaste tu clave? <a href="recuperar_clave.php" style="color: var(--primary); font-weight: 700; text-decoration: none;">Recupérarla aquí</a>
            </div>

            <div style="margin-top: 25px; font-size: 0.9rem; color: var(--text-light);">
                ¿No tienes cuenta? <a href="register.php" style="color: var(--primary); font-weight: 700; text-decoration: none;">Regístrate aquí</a>
            </div>

            <div style="margin-top: 20px;">
                <a href="../index.html" style="color: var(--text-light); text-decoration: none; font-size: 0.8rem;">← Volver a la tienda</a>
            </div>
        </div>
    </div>
    <script src="../assets/js/validaciones.js?v=1.0"></script>
    <script src="../assets/js/toggle-password.js?v=1.0"></script>
</body>
</html>
