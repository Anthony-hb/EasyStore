<?php
/**
 * Devoluciones: registra la devolucion de mercaderia y el reembolso.
 *
 * Reglas:
 *  - Solo ventas con pago validado, en preparacion o entregadas.
 *  - Devolucion PARCIAL o TOTAL: se devuelve a nivel de linea de venta, nunca
 *    mas unidades de las vendidas ni mas de las que ya estan devueltas.
 *  - El monto devuelto se calcula con el precio unitario de la VENTA (snapshot
 *    de venta_detalles), no con el precio actual del producto.
 *  - El reembolso se registra como efectivo / pago_movil / transferencia /
 *    credito. Solo el efectivo sale fisicamente de la caja, por eso es el unico
 *    metodo que el cierre de caja resta del saldo esperado.
 *  - Nada se borra: la devolucion se puede anular despues (anular_devolucion.php)
 *    y queda el registro con su evidencia.
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
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de la devolución.']);
    exit;
}

$ventaId        = Sanitizer::int($input['venta_id'] ?? 0);
$metodo         = strtolower(trim((string)($input['metodo_reembolso'] ?? 'efectivo')));
$motivo         = Sanitizer::text($input['motivo'] ?? '', 500);
$notas          = Sanitizer::text($input['notas'] ?? '', 500);
$detallesInput  = is_array($input['detalles'] ?? null) ? $input['detalles'] : [];
$usuarioAdminId = $_SESSION['usuario_id'] ?? null;

$metodosValidos = ['efectivo', 'pago_movil', 'transferencia', 'credito'];

if ($ventaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes indicar la venta a devolver.']);
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
    echo json_encode(['success' => false, 'error' => 'Debes indicar el motivo de la devolución.']);
    exit;
}
if (empty($detallesInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Debes seleccionar al menos un producto a devolver.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Bloquear la venta y verificar que sea elegible
    $stmtV = $pdo->prepare("SELECT id, total_usd, total_bs, tasa_cambio, estado FROM ventas WHERE id = ? FOR UPDATE");
    $stmtV->execute([$ventaId]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        throw new Exception("La venta #$ventaId no existe.");
    }
    if (!in_array($venta['estado'], ['Pago Validado', 'Preparando', 'Entregado'], true)) {
        throw new Exception("La venta #$ventaId está en estado '{$venta['estado']}'. Solo se pueden devolver ventas con pago validado, en preparación o entregadas.");
    }

    $tasa = (float)$venta['tasa_cambio'] > 0 ? (float)$venta['tasa_cambio'] : 50.00;
    $advertencias = [];
    $lineas = [];
    $movimientosKardex = [];
    $montoUsd = 0.0;
    $todoDevuelto = true;

    // 2. Validar cada linea contra lo pendiente real
    foreach ($detallesInput as $det) {
        $ventaDetalleId = Sanitizer::int($det['venta_detalle_id'] ?? 0);
        $cantidad      = Sanitizer::int($det['cantidad'] ?? 0);

        if ($ventaDetalleId <= 0 || $cantidad <= 0) {
            continue;
        }

        $stmtVD = $pdo->prepare("
            SELECT vd.id, vd.producto_id, vd.nombre_producto, vd.cantidad, vd.precio_unitario_usd
              FROM venta_detalles vd
             WHERE vd.id = ? AND vd.venta_id = ?
             FOR UPDATE
        ");
        $stmtVD->execute([$ventaDetalleId, $ventaId]);
        $vd = $stmtVD->fetch(PDO::FETCH_ASSOC);

        if (!$vd) {
            throw new Exception("Uno de los productos seleccionados no pertenece a la venta #$ventaId.");
        }

        // Lo ya devuelto en devoluciones ACTIVAS. No hay que excluir nada:
        // la devolucion que estamos por crear todavia no existe.
        $stmtPrev = $pdo->prepare("
            SELECT COALESCE(SUM(dd.cantidad), 0)
              FROM devolucion_detalles dd
              JOIN devoluciones d ON d.id = dd.devolucion_id
             WHERE dd.venta_detalle_id = ? AND d.estado = 'ACTIVA'
        ");
        $stmtPrev->execute([$ventaDetalleId]);
        $yaDevuelto = (int)$stmtPrev->fetchColumn();

        $vendido     = (int)$vd['cantidad'];
        $pendiente   = max(0, $vendido - $yaDevuelto);

        if ($cantidad > $pendiente) {
            throw new Exception("No puedes devolver {$cantidad} und. de '{$vd['nombre_producto']}': solo quedan {$pendiente} por devolver de las {$vendido} vendidas.");
        }

        $precio  = (float)$vd['precio_unitario_usd'];
        $subtotal = round($precio * $cantidad, 2);
        $montoUsd = round($montoUsd + $subtotal, 2);

        $lineas[] = [
            'venta_detalle_id'       => $ventaDetalleId,
            'producto_id'            => $vd['producto_id'],
            'nombre_producto'        => $vd['nombre_producto'],
            'cantidad'               => $cantidad,
            'precio_unitario_usd'    => $precio,
            'subtotal_usd'           => $subtotal,
        ];

        if ($cantidad < $pendiente) {
            $todoDevuelto = false;
        }

        // 3. Preparar el reingreso de stock (se ejecuta en el paso 6, cuando ya
        // se conoce el id de la devolucion y el movimento puede quedar trazado)
        if ($vd['producto_id'] !== null) {
            $movimientosKardex[] = [
                'producto_id'     => (int)$vd['producto_id'],
                'nombre_producto' => $vd['nombre_producto'],
                'cantidad'        => $cantidad,
                'concepto'        => 'DEVOLUCION_VENTA',
                'documento_tipo'  => 'DEVOLUCION',
                'documento_id'    => (int)$ventaId,
                'costo_unitario_usd' => $precio,
                'venta_id'        => (int)$ventaId,
                'venta_detalle_id' => (int)$ventaDetalleId,
                'devolucion_id'   => null, // se completa mas abajo
                'observaciones'   => 'Devolucion de ' . $cantidad . ' und. de "' . $vd['nombre_producto'] . '"',
            ];
        } else {
            $advertencias[] = "'{$vd['nombre_producto']}' ya no está vinculado a un producto, se devolvieron {$cantidad} und. sin reingresar al inventario.";
        }
    }

    if (empty($lineas)) {
        throw new Exception('Debes indicar al menos una cantidad válida a devolver.');
    }

    // 4. Registrar la devolucion
    $tipo         = $todoDevuelto ? 'TOTAL' : 'PARCIAL';
    $montoBs      = round($montoUsd * $tasa, 2);

    $stmtD = $pdo->prepare("
        INSERT INTO devoluciones
            (venta_id, tipo, estado, metodo_reembolso, monto_devuelto_usd, monto_devuelto_bs,
             tasa_cambio, motivo, notas, usuario_admin_id)
        VALUES (?, ?, 'ACTIVA', ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtD->execute([
        $ventaId, $tipo, $metodo, $montoUsd, $montoBs,
        $tasa, $motivo, $notas !== '' ? $notas : null, $usuarioAdminId
    ]);
    $devolucionId = (int)$pdo->lastInsertId();

    // 5. Detalle de la mercaderia devuelta
    $stmtDD = $pdo->prepare("
        INSERT INTO devolucion_detalles
            (devolucion_id, venta_detalle_id, producto_id, nombre_producto, cantidad,
             precio_unitario_usd, subtotal_usd)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lineas as $l) {
        $stmtDD->execute([
            $devolucionId, $l['venta_detalle_id'], $l['producto_id'], $l['nombre_producto'],
            $l['cantidad'], $l['precio_unitario_usd'], $l['subtotal_usd']
        ]);
    }

    // 6. Devolver la mercaderia al inventario y asentar el movimiento en el Kardex
    foreach (($movimientosKardex ?? []) as $mov) {
        $mov['devolucion_id'] = $devolucionId;
        $mov['numero_referencia'] = 'Devolucion #' . $devolucionId;
        if (aplicarMovimientoKardex($pdo, $mov) === null) {
            $advertencias[] = "'{$mov['nombre_producto']}' ya no está en el catálogo; se registró la devolución sin reingresar stock.";
        }
    }

    $pdo->commit();

    $mensaje = ($tipo === 'TOTAL' ? 'Devolución total' : 'Devolución parcial')
             . " registrada para la venta #$ventaId. "
             . ($metodo === 'credito'
                 ? "Se dejó crédito por \$$montoUsd, no sale dinero de la caja."
                 : "Se devolvieron \$$montoUsd por $metodo.");

    echo json_encode([
        'success'        => true,
        'message'        => $mensaje,
        'devolucion_id'  => $devolucionId,
        'tipo'           => $tipo,
        'monto_usd'      => $montoUsd,
        'monto_bs'       => $montoBs,
        'unidades'       => array_sum(array_column($lineas, 'cantidad')),
        'advertencias'   => $advertencias
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
