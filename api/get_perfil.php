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

try {
    if ($rol === 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM administrador WHERE id = :id LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT id, usuario, nombre_completo, email, telefono, fecha_registro, pregunta_seguridad_id FROM clientes WHERE id = :id LIMIT 1");
    }
    
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user['rol'] = $rol;
        $user['tiene_pregunta_seguridad'] = !empty($user['pregunta_seguridad_id']);
        $user['preguntas_seguridad'] = listarPreguntasSeguridad($pdo);

        if ($rol === 'admin') {
            $user['negocio'] = [
                'nombre' => $user['negocio_nombre'] ?? '',
                'telefono' => $user['negocio_telefono'] ?? '',
                'horario' => $user['negocio_horario'] ?? '',
                'ubicacion' => $user['negocio_ubicacion'] ?? ''
            ];

            $user['fiscal'] = [
                'rif' => $user['negocio_rif'] ?? '',
                'direccion_fiscal' => $user['direccion_fiscal'] ?? '',
                'iva_general_pct' => $user['iva_general_pct'] ?? 16,
                'igtf_pct' => $user['igtf_pct'] ?? 3,
                'es_agente_retencion' => (int)($user['es_agente_retencion'] ?? 0),
                'retencion_iva_pct' => $user['retencion_iva_pct'] ?? 75
            ];

            $user['bancos'] = [
                'pm_banco' => $user['pm_banco'] ?? '',
                'pm_telefono' => $user['pm_telefono'] ?? '',
                'pm_cedula' => $user['pm_cedula'] ?? '',
                'transf_banco' => $user['transf_banco'] ?? '',
                'transf_cuenta' => $user['transf_cuenta'] ?? '',
                'transf_titular' => $user['transf_titular'] ?? '',
                'transf_cedula' => $user['transf_cedula'] ?? '',
                'zelle_correo' => $user['zelle_correo'] ?? '',
                'zelle_titular' => $user['zelle_titular'] ?? '',
                'biopago_banco' => $user['biopago_banco'] ?? '',
                'biopago_cedula' => $user['biopago_cedula'] ?? '',
                'biopago_titular' => $user['biopago_titular'] ?? ''
            ];
        }

        echo json_encode(['success' => true, 'profile' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>
