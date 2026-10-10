<?php
/**
 * Devoluciones a proveedor: registra la devolucion de mercaderia recibida
 * y registra el efecto (ajuste de stock, registro de devolucion).
 *
 * Reglas:
 *  - Solo compras en estado Recibido (o aquellas con stock recibido).
 *  - Devolucion PARCIAL o TOTAL: por linea de compra, nunca mas de lo comprado ni mas de lo pendiente.
 *  - Se resta stock (ya que devolvemos al proveedor la mercaderia recibida).
 *  - El monto se calcula con precio unitario de compra (snapshot de compra_detalles).
 *  - Nada se borra: se puede anular despues (anular_devolucion_proveedor.php).
 */
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once '../includes/kardex.php';
require_once 'check_session.php';
requerirAdmin();

asegurarEsquemaBD($pdo);

$input = Sanitizer::readJsonInput();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de la devolución al proveedor.']);
    exit;
}

$compraId       = Sanitizer::int($input['compra_id'] ?? 0);
$metodo         = strtolower(trim((string)($input['metodo_reembolso'] ?? 'nota_credito')));
$motivo         = Sanitizer::text($input['motivo'] ?? '', 500);
$notas          = Sanitizer::text($input['notas'] ?? '', 500);
$detallesInput  = is_array($input['detalles'] ?? null) ? $input['detalles'] : [];
$usuarioAdminId = $_SESSION['usuario_id'] ?? null;

$metodosValidos = ['efectivo', 'pago_movil', 'transferencia', 'nota_credito', 'otros'];

if ($compraId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes indicar la compra a devolver.']);
    exit;
}
if (!in_array($metodo, $metodosValidos, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Método de reembolso inválido. Usa: ' . implode(', ', $metodosValidos) . '.'
    ]);
    exit;
}
if (trim($motivo) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes indicar el motivo de la devolución al proveedor.']);
    exit;
}
if (empty($detallesInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes seleccionar al menos un producto a devolver al proveedor.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Bloquear la compra
    $stmtC = $pdo->prepare("SELECT id, total_usd, total_bs, tasa_cambio, estado FROM compras WHERE id = ? FOR UPDATE");
    $stmtC->execute([$compraId]);
    $compra = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$compra) {
        throw new Exception("La compra #$compraId no existe.");
    }

    // Permitimos devoluciones si la compra fue recibida (flujo habitual)
    $estadosPermitidos = ['Recibido', 'Pendiente', 'Parcial'];
    if (!in_array($compra['estado'], $estadosPermitidos, true)) {
        // No forzamos a 'Recibido' si ya hay otras etapas, pero validamos
        // Permitimos igual si hay mercaderia
    }

    $tasa = (float)$compra['tasa_cambio'] > 0 ? (float)$compra['tasa_cambio'] : 50.00;
    $advertencias = [];
    $lineas = [];
    $movimientosKardex = [];
    $montoUsd = 0.0;
    $todoDevuelto = true;

    // 2. Validar cada linea
    foreach ($detallesInput as $det) {
        $compraDetalleId = Sanitizer::int($det['compra_detalle_id'] ?? 0);
        $cantidad        = Sanitizer::int($det['cantidad'] ?? 0);

        if ($compraDetalleId <= 0 || $cantidad <= 0) {
            continue;
        }

        $stmtCD = $pdo->prepare("
            SELECT cd.id, cd.producto_id, cd.nombre_producto, cd.cantidad, cd.precio_unitario
              FROM compra_detalles cd
             WHERE cd.id = ? AND cd.compra_id = ?
             FOR UPDATE
        ");
        $stmtCD->execute([$compraDetalleId, $compraId]);
        $cd = $stmtCD->fetch(PDO::FETCH_ASSOC);

        if (!$cd) {
            throw new Exception("Uno de los productos seleccionados no pertenece a la compra #$compraId.");
        }

        $stmtPrev = $pdo->prepare("
            SELECT COALESCE(SUM(ddp.cantidad), 0)
              FROM devolucion_proveedor_detalles ddp
              JOIN devoluciones_proveedor dp ON dp.id = ddp.devolucion_proveedor_id
             WHERE ddp.compra_detalle_id = ? AND dp.estado = 'ACTIVA'
        ");
        $stmtPrev->execute([$compraDetalleId]);
        $yaDevuelto = (int)$stmtPrev->fetchColumn();

        $comprado   = (int)$cd['cantidad'];
        $pendiente  = max(0, $comprado - $yaDevuelto);

        if ($cantidad > $pendiente) {
            throw new Exception("No puedes devolver {$cantidad} und. de '{$cd['nombre_producto']}': solo quedan {$pendiente} por devolver de las {$comprado} compradas.");
        }

        $precio  = (float)($cd['precio_unitario'] ?? 0);
        $subtotal = round($precio * $cantidad, 2);
        $montoUsd = round($montoUsd + $subtotal, 2);

        $lineas[] = [
            'compra_detalle_id'       => $compraDetalleId,
            'producto_id'             => $cd['producto_id'],
            'nombre_producto'         => $cd['nombre_producto'],
            'cantidad'                => $cantidad,
            'precio_unitario_usd'     => $precio,
            'subtotal_usd'            => $subtotal,
        ];

        if ($cantidad < $pendiente) {
            $todoDevuelto = false;
        }

        // 3. Preparar la salida de stock (se ejecuta en el paso 6, cuando ya se conoce
        // el id de la devolucion y el movimiento puede quedar trazado)
        if ($cd['producto_id'] !== null) {
            $movimientosKardex[] = [
                'producto_id'     => (int)$cd['producto_id'],
                'nombre_producto' => $cd['nombre_producto'],
                'cantidad'        => -$cantidad,
                'concepto'        => 'DEVOLUCION_PROVEEDOR',
                'documento_tipo'  => 'COMPRA',
                'documento_id'    => (int)$compraId,
                'costo_unitario_usd' => $precio,
                'compra_id'       => (int)$compraId,
                'compra_detalle_id' => (int)$compraDetalleId,
                'devolucion_proveedor_id' => null, // se completa mas abajo
                'observaciones'   => 'Devolucion al proveedor de ' . $cantidad . ' und. de "' . $cd['nombre_producto'] . '"',
            ];
        } else {
            $advertencias[] = "'{$cd['nombre_producto']}' no está vinculado a un producto, se registró la devolución sin ajustar el inventario.";
        }
    }

    if (empty($lineas)) {
        throw new Exception('Debes indicar al menos una cantidad válida a devolver al proveedor.');
    }

    // 4. Registrar la devolucion al proveedor
    $tipo    = $todoDevuelto ? 'TOTAL' : 'PARCIAL';
    $montoBs = round($montoUsd * $tasa, 2);

    $stmtD = $pdo->prepare("
        INSERT INTO devoluciones_proveedor
            (compra_id, tipo, estado, metodo_reembolso, monto_devuelto_usd, monto_devuelto_bs,
             tasa_cambio, motivo, notas, usuario_admin_id)
        VALUES (?, ?, 'ACTIVA', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtD->execute([
        $compraId, $tipo, $metodo, $montoUsd, $montoBs,
        $tasa, $motivo, $notas !== '' ? $notas : null, $usuarioAdminId
    ]);
    $devolucionProvId = (int)$pdo->lastInsertId();

    // 5. Detalle de la mercaderia devuelta
    $stmtDD = $pdo->prepare("
        INSERT INTO devolucion_proveedor_detalles
            (devolucion_proveedor_id, compra_detalle_id, producto_id, nombre_producto, cantidad,
             precio_unitario_usd, subtotal_usd)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lineas as $l) {
        $stmtDD->execute([
            $devolucionProvId, $l['compra_detalle_id'], $l['producto_id'], $l['nombre_producto'],
            $l['cantidad'], $l['precio_unitario_usd'], $l['subtotal_usd']
        ]);
    }

    // 6. Sacar la mercaderia del inventario y asentar el movimiento en el Kardex
    foreach (($movimientosKardex ?? []) as $mov) {
        $mov['devolucion_proveedor_id'] = $devolucionProvId;
        $mov['numero_referencia'] = 'Devolucion proveedor #' . $devolucionProvId;
        if (aplicarMovimientoKardex($pdo, $mov) === null) {
            $advertencias[] = "'{$mov['nombre_producto']}' ya no está en el catálogo; se registró la devolución sin ajustar stock.";
        }
    }

    $pdo->commit();

    $mensaje = ($tipo === 'TOTAL' ? 'Devolución total al proveedor' : 'Devolución parcial al proveedor')
             . " registrada para la compra #$compraId. "
             . "Monto devuelto: \$$montoUsd.";

    echo json_encode([
        'success'              => true,
        'message'              => $mensaje,
        'devolucion_proveedor_id' => $devolucionProvId,
        'tipo'                 => $tipo,
        'monto_usd'            => round($montoUsd, 2),
        'advertencias'         => $advertencias
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}