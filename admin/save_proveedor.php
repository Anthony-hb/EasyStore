<?php
// Endpoint de escritura: exige sesion de administrador.
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();
header('Content-Type: application/json; charset=utf-8');

$data = Sanitizer::readJsonInput();

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos.']);
    exit;
}

// OJO: sin minimo. Sanitizer::int($x, 1) convierte un id 0 en 1, y eso
    // actualizaria el proveedor #1 en vez de crear uno nuevo.
    $id = isset($data['id']) && is_numeric($data['id']) ? Sanitizer::int($data['id']) : 0;
    if ($id <= 0) {
        $id = null;
    }
$nombre_negocio = Sanitizer::string($data['nombre_negocio'] ?? '', 100);
$nombre = Sanitizer::string($data['nombre'] ?? '', 80);
$apellido = Sanitizer::string($data['apellido'] ?? '', 80);
$documento_identidad = Sanitizer::string($data['documento_identidad'] ?? '', 30);
$email = Sanitizer::email($data['email'] ?? '');
$telefono = Sanitizer::phone($data['telefono'] ?? '');
$direccion = Sanitizer::text($data['direccion'] ?? '', 255);
$banco = Sanitizer::string($data['banco'] ?? '', 50);
$cuenta_bancaria = Sanitizer::string($data['cuenta_bancaria'] ?? '', 50);
$telefono_pago_movil = Sanitizer::phone($data['telefono_pago_movil'] ?? '');
$tipo_producto = Sanitizer::string($data['tipo_producto'] ?? '', 100);

/**
 * Normaliza la lista de marcas del proveedor.
 * Acepta "SanDisk, Kingston, HP" (o con saltos de linea) y devuelve
 * "SanDisk, Kingston, HP" sin duplicados ni marcas vacias, para que el
 * desplegable de marcas de la compra siempre muestre la lista limpia.
 */
function normalizarMarcasProveedor($marcas): string {
    if (is_array($marcas)) {
        $partes = $marcas;
    } else {
        $partes = preg_split('/[,\r\n;]+/', (string)$marcas);
    }

    $vistas = [];
    $limpias = [];
    foreach ($partes as $parte) {
        $marca = Sanitizer::string($parte, 60);
        if ($marca === '') continue;
        // La clave de comparacion va en minuscula y sin espacios sobrantes.
        $clave = mb_strtolower($marca);
        if (isset($vistas[$clave])) continue;
        $vistas[$clave] = true;
        $limpias[] = $marca;
    }

    return implode(', ', $limpias);
}

$marcas = normalizarMarcasProveedor($data['marca_producto'] ?? '');

if (empty($nombre_negocio)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El nombre del negocio es obligatorio.']);
    exit;
}

try {
    // Validar duplicados (Nombre de negocio, Documento o Email)
    $checkConditions = [];
    $checkParams = [];

    if (!empty($nombre_negocio)) {
        $checkConditions[] = "LOWER(TRIM(nombre_negocio)) = LOWER(TRIM(?))";
        $checkParams[] = $nombre_negocio;
    }
    if (!empty($documento_identidad)) {
        $checkConditions[] = "(documento_identidad IS NOT NULL AND documento_identidad != '' AND documento_identidad = ?)";
        $checkParams[] = $documento_identidad;
    }
    if (!empty($email)) {
        $checkConditions[] = "(email IS NOT NULL AND email != '' AND LOWER(TRIM(email)) = LOWER(TRIM(?)))";
        $checkParams[] = $email;
    }

    if (!empty($checkConditions)) {
        $sqlCheck = "SELECT id, nombre_negocio, documento_identidad, email FROM proveedores WHERE (" . implode(" OR ", $checkConditions) . ")";
        if ($id) {
            $sqlCheck .= " AND id != ?";
            $checkParams[] = $id;
        }

        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute($checkParams);
        $existente = $stmtCheck->fetch();

        if ($existente) {
            $msgError = 'Ya existe un proveedor registrado con ';
            if (!empty($nombre_negocio) && strtolower(trim($existente['nombre_negocio'])) === strtolower(trim($nombre_negocio))) {
                $msgError .= 'el mismo nombre de negocio ("' . $nombre_negocio . '").';
            } elseif (!empty($documento_identidad) && $existente['documento_identidad'] === $documento_identidad) {
                $msgError .= 'el mismo documento de identidad/RIF (' . $documento_identidad . ').';
            } else {
                $msgError .= 'el mismo correo electrónico (' . $email . ').';
            }
            echo json_encode(['success' => false, 'error' => $msgError]);
            exit;
        }
    }

    if ($id) {
        // Update existing provider: primero confirmar que exista
        $chk = $pdo->prepare("SELECT id FROM proveedores WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'El proveedor indicado no existe.']);
            exit;
        }

        $sql = "UPDATE proveedores SET 
                nombre_negocio = ?, 
                nombre = ?, 
                apellido = ?, 
                documento_identidad = ?, 
                email = ?, 
                telefono = ?, 
                direccion = ?, 
                banco = ?, 
                cuenta_bancaria = ?, 
                telefono_pago_movil = ?,
                tipo_producto = ?,
                marca_producto = ?
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $nombre_negocio, $nombre, $apellido, $documento_identidad, 
            $email, $telefono, $direccion, $banco, $cuenta_bancaria, $telefono_pago_movil,
            $tipo_producto, $marcas, $id
        ]);
        echo json_encode(['success' => true, 'message' => 'Proveedor actualizado correctamente.']);
    } else {
        // Insert new provider
        $sql = "INSERT INTO proveedores (
                    nombre_negocio, nombre, apellido, documento_identidad, 
                    email, telefono, direccion, banco, cuenta_bancaria, telefono_pago_movil,
                    tipo_producto, marca_producto
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $nombre_negocio, $nombre, $apellido, $documento_identidad, 
            $email, $telefono, $direccion, $banco, $cuenta_bancaria, $telefono_pago_movil,
            $tipo_producto, $marcas
        ]);
        echo json_encode(['success' => true, 'message' => 'Proveedor creado correctamente.', 'id' => $pdo->lastInsertId()]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
