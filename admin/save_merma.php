<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once '../includes/kardex.php';
// modulo de contabilidad retirado: no se carga includes/contabilidad.php
require_once 'check_session.php';
requerirAdmin();

// Asegurar que las tablas existan
asegurarEsquemaBD($pdo);

$input = Sanitizer::readJsonInput();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de la salida/pérdida.']);
    exit;
}

// OJO: el 2do parámetro de Sanitizer::int() es el MÍNIMO, no el valor por defecto.
// Pasarlo con valor 1 convertía silenciosamente un 0/null/texto inválido en 1,
// haciendo que los guards de validación de abajo nunca se activaran.
$productoId = Sanitizer::int($input['producto_id'] ?? 0);
$cantidad   = Sanitizer::int($input['cantidad'] ?? 0);
$tipoSalidaRaw = strtoupper(Sanitizer::string($input['tipo_salida'] ?? 'DAÑADO', 30));
$motivo = Sanitizer::text($input['motivo'] ?? '', 500);
$usuarioId = $_SESSION['usuario_id'] ?? null;

// Validar tipos permitidos
$tiposPermitidos = ['DAÑADO', 'ROBADO', 'EXTRAVIADO', 'VENCIDO', 'USO_INTERNO'];
$tipoSalida = in_array($tipoSalidaRaw, $tiposPermitidos, true) ? $tipoSalidaRaw : null;

if ($productoId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes seleccionar un producto válido.']);
    exit;
}

if ($cantidad <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La cantidad debe ser un número entero mayor a cero.']);
    exit;
}

if ($tipoSalida === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El tipo de salida seleccionado no es válido.']);
    exit;
}

if ($motivo === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes indicar el motivo de la pérdida.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Obtener y bloquear el producto para actualización
    $stmtP = $pdo->prepare("SELECT id, nombre, stock, precio_compra, precio_usd FROM productos WHERE id = ? FOR UPDATE");
    $stmtP->execute([$productoId]);
    $producto = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'El producto seleccionado no existe.']);
        exit;
    }

    $stockActual = (int)$producto['stock'];
    if ($stockActual < $cantidad) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => "Stock insuficiente. El producto '{$producto['nombre']}' solo tiene $stockActual unidad(es) en inventario.",
            'stock_actual' => $stockActual
        ]);
        exit;
    }

    $costoUnitario = (float)($producto['precio_compra'] ?? 0);
    if ($costoUnitario <= 0) {
        $costoUnitario = (float)($producto['precio_usd'] ?? 0);
    }
    $costoTotalUSD = round($cantidad * $costoUnitario, 2);

    $nuevoStock = $stockActual - $cantidad;

    // 2. Registrar en la tabla mermas_perdidas
    $stmtM = $pdo->prepare("INSERT INTO mermas_perdidas 
        (producto_id, cantidad, tipo_salida, costo_unitario_usd, costo_total_usd, motivo, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtM->execute([
        $productoId,
        $cantidad,
        $tipoSalida,
        $costoUnitario,
        $costoTotalUSD,
        $motivo,
        $usuarioId
    ]);
    $mermaId = $pdo->lastInsertId();

    // 3. Descontar el stock y asentar la salida en el Kardex.
    //    El modulo de contabilidad fue retirado, pero el inventario si se controla.
    $advertencias = [];

    if (aplicarMovimientoKardex($pdo, [
        'producto_id'      => $productoId,
        'nombre_producto'  => $producto['nombre'],
        'cantidad'         => -$cantidad,
        'concepto'         => 'MERMA',
        'documento_tipo'   => 'MERMA',
        'documento_id'     => (int)$mermaId,
        'numero_referencia' => 'Pérdida #' . $mermaId,
        'costo_unitario_usd' => $costoUnitario,
        'merma_id'         => (int)$mermaId,
        'usuario_id'       => $usuarioId,
        'observaciones'    => $tipoSalida . ': ' . $motivo,
    ]) === null) {
        $advertencias[] = "El producto ya no existe en el catálogo; la pérdida se registró sin ajuste de inventario.";
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Salida no operativa registrada correctamente. Stock actualizado a $nuevoStock unidades.",
        'nuevo_stock' => $nuevoStock,
        'costo_total_usd' => $costoTotalUSD,
        'advertencias' => $advertencias
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
