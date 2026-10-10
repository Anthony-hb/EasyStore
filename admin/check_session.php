<?php
// Ya no iniciamos sesión aquí porque lo hacemos en cada script API para mayor claridad
// o se puede dejar pero asegurando que no haya doble session_start

function esAdmin() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
}

function requerirAdmin() {
    if (!esAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado. Se requieren permisos de administrador.']);
        exit;
    }
}
?>
