<?php
/**
 * Utilidades de seguridad de cuentas: preguntas de recuperacion de clave
 * y validacion de contrasenas.
 *
 * Las respuestas nunca se guardan en texto plano: se normalizan y luego
 * se hashean con password_hash, igual que las contrasenas.
 */

/**
 * Normaliza una respuesta para que las mayusculas, los acentos y los espacios
 * de sobra no hagan fallar una comparison valida.
 */
function normalizarRespuestaSeguridad(string $respuesta): string {
    $r = trim($respuesta);
    $r = mb_strtolower($r, 'UTF-8');

    // Quitar acentos con un mapa explicito: "Mamá" y "mama" deben ser la
    // misma respuesta. iconv no sirve aqui porque su //TRANSLIT de XAMPP
    // devuelve caracteres extra ("m'i") que rompen la comparacion.
    $r = strtr($r, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ü' => 'u', 'ñ' => 'n',
    ]);

    $r = preg_replace('/[^a-z0-9]+/', ' ', $r);
    return trim((string)$r);
}

/**
 * Hashea una respuesta de seguridad para almacenarla.
 */
function hashearRespuestaSeguridad(string $respuesta): string {
    return password_hash(normalizarRespuestaSeguridad($respuesta), PASSWORD_DEFAULT);
}

/**
 * Verifica una respuesta contra su hash. Compara en tiempo constante
 * cuando el formato del hash lo permite.
 */
function verificarRespuestaSeguridad(string $respuesta, ?string $hash): bool {
    if ($hash === null || $hash === '') return false;
    return password_verify(normalizarRespuestaSeguridad($respuesta), $hash);
}

/**
 * Devuelve el catalogo de preguntas de seguridad disponibles.
 */
function listarPreguntasSeguridad($pdo): array {
    $stmt = $pdo->query("SELECT id, pregunta FROM preguntas_seguridad ORDER BY id ASC");
    return $stmt->fetchAll();
}

/**
 * Devuelve el texto de una pregunta por su id (o cadena vacia).
 */
function textoPreguntaSeguridad($pdo, ?int $id): string {
    if (!$id) return '';
    $stmt = $pdo->prepare("SELECT pregunta FROM preguntas_seguridad WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row['pregunta'] ?? '';
}

/**
 * Devuelve el mensaje de error de una contrasena invalida, o '' si es correcta.
 * La regla es la misma que se usa en el registro: minimo 8, con letras y numeros.
 */
function errorPasswordInvalida(string $password): string {
    if (strlen($password) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'La contraseña debe incluir al menos un número.';
    }
    if (!preg_match('/[\p{L}]/u', $password)) {
        return 'La contraseña debe incluir al menos una letra.';
    }
    return '';
}

/**
 * Sanea el par pregunta/respuesta de un formulario.
 *
 * Devuelve ['pregunta_id' => int, 'respuesta' => string, 'error' => string].
 * El error es '' cuando el par es utilizable.
 */
function validarPreguntaSeguridad($pdo, $preguntaId, $respuesta): array {
    $preguntaId = (int)$preguntaId;
    $respuestaLimpia = trim((string)$respuesta);

    if ($preguntaId <= 0 || $respuestaLimpia === '') {
        return ['pregunta_id' => 0, 'respuesta' => '', 'error' => ''];
    }

    $stmt = $pdo->prepare("SELECT id FROM preguntas_seguridad WHERE id = ? LIMIT 1");
    $stmt->execute([$preguntaId]);
    if (!$stmt->fetch()) {
        return ['pregunta_id' => 0, 'respuesta' => '', 'error' => 'La pregunta de seguridad seleccionada no es válida.'];
    }

    if (mb_strlen($respuestaLimpia, 'UTF-8') < 3) {
        return ['pregunta_id' => 0, 'respuesta' => '', 'error' => 'La respuesta de seguridad debe tener al menos 3 caracteres.'];
    }

    if (mb_strlen($respuestaLimpia, 'UTF-8') > 100) {
        return ['pregunta_id' => 0, 'respuesta' => '', 'error' => 'La respuesta de seguridad es demasiado larga.'];
    }

    // Una respuesta normalizada no puede quedar vacia (ej: solo signos).
    if (normalizarRespuestaSeguridad($respuestaLimpia) === '') {
        return ['pregunta_id' => 0, 'respuesta' => '', 'error' => 'La respuesta de seguridad debe contener letras o números.'];
    }

    return ['pregunta_id' => $preguntaId, 'respuesta' => $respuestaLimpia, 'error' => ''];
}

/**
 * Limita los intentos de recuperacion por sesion para frenar fuerza bruta.
 *
 * Devuelve el numero de segundos que faltan para poder volver a intentar,
 * o 0 si el intento esta permitido.
 */
function segundosEsperaRecuperacion(array &$estado, int $maxIntentos = 5, int $esperaSegundos = 300): int {
    $intentos = (int)($estado['intentos'] ?? 0);
    $ultimo = (int)($estado['ultimo'] ?? 0);

    if ($intentos < $maxIntentos) return 0;

    $transcurrido = time() - $ultimo;
    if ($transcurrido >= $esperaSegundos) {
        $estado['intentos'] = 0;
        return 0;
    }

    return $esperaSegundos - $transcurrido;
}

/**
 * Registra un intento fallido de recuperacion.
 */
function registrarIntentoRecuperacion(array &$estado): void {
    $estado['intentos'] = (int)($estado['intentos'] ?? 0) + 1;
    $estado['ultimo'] = time();
}

/**
 * Limpia el contador de intentos tras un acierto.
 */
function limpiarIntentosRecuperacion(array &$estado): void {
    $estado['intentos'] = 0;
    $estado['ultimo'] = 0;
}