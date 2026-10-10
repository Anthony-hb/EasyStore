<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    require '../includes/db.php';
} catch (Exception $e) {
    error_log("Error save_venta_presencial.php DB connect: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos.']);
    exit;
}

require 'check_session.php';
requerirAdmin();
require_once '../includes/kardex.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibieron datos de la venta.']);
    exit;
}

$cliente_id = isset($input['cliente_id']) && intval($input['cliente_id']) > 0 ? intval($input['cliente_id']) : null;
$cliente_nombre_manual = trim($input['cliente_nombre'] ?? '');
$metodo_pago = trim($input['metodo_pago'] ?? 'Efectivo USD');
$fecha_venta_input = trim($input['fecha_venta'] ?? '');
// IGTF informativo: solo se carga si el cobro fue en divisas (no se calcula solo).
$igtf_usd = round(floatval($input['igtf_usd'] ?? 0), 2);
if ($igtf_usd < 0) $igtf_usd = 0;

if ($fecha_venta_input !== '') {
    $fecha_venta = str_replace('T', ' ', $fecha_venta_input);
} else {
    $fecha_venta = date('Y-m-d H:i:s');
}

$detalles = is_array($input['detalles'] ?? null) ? $input['detalles'] : [];

if (empty($detalles)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Agrega al menos un producto a la venta.']);
    exit;
}

// Obtener tasa del dólar actual de la BD si no viene enviada
$tasa_cambio = floatval($input['tasa_cambio'] ?? 0);
if ($tasa_cambio <= 0) {
    try {
        $stmtTasa = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
        $tasaRow = $stmtTasa->fetch();
        if ($tasaRow && floatval($tasaRow['tasa']) > 0) {
            $tasa_cambio = floatval($tasaRow['tasa']);
        } else {
            $tasa_cambio = 50.00;
        }
    } catch (Exception $e) {
        $tasa_cambio = 50.00;
    }
}

try {
    $pdo->beginTransaction();

    $stmtProd = $pdo->prepare("SELECT id, nombre, precio_usd, precio_compra, iva_alicuota, stock FROM productos WHERE id = ?");

    $total_usd = 0;
    $total_base = 0;
    $total_iva = 0;
    $detalles_procesados = [];

    foreach ($detalles as $det) {
        $pId = intval($det['producto_id'] ?? 0);
        $cant = intval($det['cantidad'] ?? 0);
        $precio_unit = isset($det['precio_unitario']) ? floatval($det['precio_unitario']) : -1;

        if ($pId <= 0 || $cant <= 0) {
            continue;
        }

        $stmtProd->execute([$pId]);
        $prod = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("El producto con ID #$pId ya no existe en el catálogo.");
        }

        if ($prod['stock'] < $cant) {
            throw new Exception("Stock insuficiente para el producto '{$prod['nombre']}'. Disponible: {$prod['stock']}, Solicitado: {$cant}.");
        }

        if ($precio_unit < 0) {
            $precio_unit = floatval($prod['precio_usd']);
        }

        $costo_unit = floatval($prod['precio_compra'] ?? 0);
        $subtotal = round($cant * $precio_unit, 2);
        $total_usd += $subtotal;

        // El precio es P.V.P. con IVA incluido: se desglosa base y IVA.
        $ivaAlicuota = floatval($prod['iva_alicuota'] ?? 16.00);
        $tasaIva = $ivaAlicuota / 100;
        $baseUnit = $tasaIva > 0 ? $precio_unit / (1 + $tasaIva) : $precio_unit;
        $base_linea = round($baseUnit * $cant, 2);
        $iva_linea = round($subtotal - $base_linea, 2);
        $total_base += $base_linea;
        $total_iva += $iva_linea;

        $detalles_procesados[] = [
            'producto_id' => $prod['id'],
            'nombre_producto' => $prod['nombre'],
            'cantidad' => $cant,
            'precio_unitario_usd' => $precio_unit,
            'base_usd' => $base_linea,
            'iva_alicuota' => $ivaAlicuota,
            'iva_usd' => $iva_linea,
            'costo_unitario_usd' => $costo_unit,
            'subtotal_usd' => $subtotal
        ];
    }

    if (empty($detalles_procesados)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No hay productos válidos en el detalle de la venta.']);
        exit;
    }

    $es_apartado = !empty($input['es_apartado']);
    $total_usd = round($total_usd, 2);
    $total_base = round($total_base, 2);
    $total_iva = round($total_iva, 2);
    $total_bs = round($total_usd * $tasa_cambio, 2);
    $estado = $es_apartado ? 'Apartado' : 'Entregado'; // Venta directa se entrega de inmediato; apartado se queda reservado

    // Insertar encabezado de la venta
    $sqlVenta = "INSERT INTO ventas (total_usd, total_bs, base_gravada_usd, iva_usd, igtf_usd, tasa_cambio, usuario_id, fecha_venta, estado, metodo_pago) 
                 VALUES (:total_usd, :total_bs, :base_gravada_usd, :iva_usd, :igtf_usd, :tasa_cambio, :usuario_id, :fecha_venta, :estado, :metodo_pago)";
    $stmtVenta = $pdo->prepare($sqlVenta);
    $stmtVenta->execute([
        ':total_usd' => $total_usd,
        ':total_bs' => $total_bs,
        ':base_gravada_usd' => $total_base,
        ':iva_usd' => $total_iva,
        ':igtf_usd' => $igtf_usd,
        ':tasa_cambio' => $tasa_cambio,
        ':usuario_id' => $cliente_id,
        ':fecha_venta' => $fecha_venta,
        ':estado' => $estado,
        ':metodo_pago' => $metodo_pago
    ]);

    $venta_id = $pdo->lastInsertId();

    // Insertar detalles y descontar stock (queda reservado/apartado)
    $stmtDetalle = $pdo->prepare("INSERT INTO venta_detalles (venta_id, producto_id, nombre_producto, cantidad, precio_unitario_usd, base_usd, iva_alicuota, iva_usd, costo_unitario_usd, subtotal_usd) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($detalles_procesados as $det) {
        $stmtDetalle->execute([
            $venta_id,
            $det['producto_id'],
            $det['nombre_producto'],
            $det['cantidad'],
            $det['precio_unitario_usd'],
            $det['base_usd'],
            $det['iva_alicuota'],
            $det['iva_usd'],
            $det['costo_unitario_usd'],
            $det['subtotal_usd']
        ]);
        $ventaDetalleId = (int)$pdo->lastInsertId();

        // Descontar stock y asentar el movimiento en el Kardex (la venta/apartado
        // reserva la mercaderia: sale del inventario disponible).
        aplicarMovimientoKardex($pdo, [
            'producto_id'        => $det['producto_id'],
            'nombre_producto'    => $det['nombre_producto'],
            'cantidad'           => -$det['cantidad'],
            'concepto'           => 'VENTA',
            'documento_tipo'     => 'VENTA',
            'documento_id'       => $venta_id,
            'numero_referencia'  => 'Venta #' . $venta_id,
            'costo_unitario_usd' => $det['costo_unitario_usd'],
            'venta_id'           => $venta_id,
            'venta_detalle_id'   => $ventaDetalleId,
            'estricto'           => true,
            'observaciones'      => ($es_apartado ? 'Apartado' : 'Venta presencial') . ' #' . $venta_id,
        ]);
    }

    if ($es_apartado) {
        $monto_inicial_usd = floatval($input['monto_abono_inicial_usd'] ?? ($total_usd * 0.30));
        if ($monto_inicial_usd <= 0) {
            $monto_inicial_usd = round($total_usd * 0.30, 2);
        }
        $monto_inicial_usd = min($total_usd, round($monto_inicial_usd, 2));
        $monto_inicial_bs = round($monto_inicial_usd * $tasa_cambio, 2);
        $monto_pendiente_usd = round(max(0, $total_usd - $monto_inicial_usd), 2);

        // Reglas globales de apartados definidas por el administrador
        // (Historial de Clientes -> Configuración de Apartados).
        $cfgApartado = [];
        try {
            $stmtCfgAp = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
            $cfgApartado = $stmtCfgAp->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $eCfg) {
            $cfgApartado = [];
        }
        $diasCfg = !empty($cfgApartado['apartado_dias_limite']) ? (int)$cfgApartado['apartado_dias_limite'] : 15;
        $cuotasApartado = !empty($cfgApartado['apartado_cuotas']) ? (int)$cfgApartado['apartado_cuotas'] : 3;
        if ($cuotasApartado < 1) $cuotasApartado = 3;

        $dias_limite = intval($input['dias_limite'] ?? $diasCfg);
        if ($dias_limite <= 0) $dias_limite = $diasCfg;
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_limite days"));
        $estado_apartado = ($monto_pendiente_usd <= 0) ? 'Completado' : 'Activo';
        $ref_apartado = trim($input['apartado_referencia'] ?? 'Abono Inicial Presencial');
        $usuario_admin_id = $_SESSION['usuario_id'] ?? null;

        $stmtInsAp = $pdo->prepare("
            INSERT INTO apartados 
            (venta_id, usuario_id, cliente_nombre, total_usd, total_bs, tasa_cambio, monto_minimo_inicial_usd, porcentaje_inicial, monto_abonado_usd, monto_abonado_bs, monto_pendiente_usd, fecha_limite, dias_limite, cuotas, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsAp->execute([
            $venta_id,
            $cliente_id,
            $cliente_nombre_manual ?: 'Cliente Presencial',
            $total_usd,
            $total_bs,
            $tasa_cambio,
            round($total_usd * 0.30, 2),
            30,
            $monto_inicial_usd,
            $monto_inicial_bs,
            $monto_pendiente_usd,
            $fecha_limite,
            $dias_limite,
            $cuotasApartado,
            $estado_apartado
        ]);

        $apartado_id = $pdo->lastInsertId();

        // Registrar abono inicial
        if ($monto_inicial_usd > 0) {
            $stmtInsAb = $pdo->prepare("
                INSERT INTO apartado_abonos 
                (apartado_id, monto_usd, monto_bs, tasa_cambio, metodo_pago, referencia, usuario_admin_id, notas)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Abono inicial en tienda física')
            ");
            $stmtInsAb->execute([
                $apartado_id,
                $monto_inicial_usd,
                $monto_inicial_bs,
                $tasa_cambio,
                $metodo_pago,
                $ref_apartado,
                $usuario_admin_id
            ]);
        }
    } else {
        // Venta presencial normal: si tuvo método electrónico se puede registrar en pagos
        $metodo_pago_enum = strtolower($metodo_pago);
        if (strpos($metodo_pago_enum, 'pago_movil') !== false || strpos($metodo_pago_enum, 'transferencia') !== false
            || strpos($metodo_pago_enum, 'zelle') !== false || strpos($metodo_pago_enum, 'biopago') !== false
            || strpos($metodo_pago_enum, 'punto') !== false || strpos($metodo_pago_enum, 'tarjeta') !== false) {
            if (strpos($metodo_pago_enum, 'pago_movil') !== false) {
                $tipo_metodo = 'pago_movil';
            } elseif (strpos($metodo_pago_enum, 'zelle') !== false) {
                $tipo_metodo = 'zelle';
            } elseif (strpos($metodo_pago_enum, 'biopago') !== false) {
                $tipo_metodo = 'biopago';
            } elseif (strpos($metodo_pago_enum, 'transf') !== false) {
                $tipo_metodo = 'transferencia';
            } elseif (strpos($metodo_pago_enum, 'punto') !== false || strpos($metodo_pago_enum, 'tarjeta') !== false || strpos($metodo_pago_enum, 'pos') !== false) {
                $tipo_metodo = 'punto_venta';
            } else {
                $tipo_metodo = 'punto_venta';
            }
            $stmtPago = $pdo->prepare("INSERT INTO pagos (venta_id, metodo_pago, banco_origen, referencia, monto, fecha_pago, estado) 
                                       VALUES (?, ?, 'Venta Presencial', 'POS-DIRECTO', ?, ?, 'validado')");
            $stmtPago->execute([$venta_id, $tipo_metodo, $total_bs, $fecha_venta]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'venta_id' => $venta_id, 
        'total_usd' => $total_usd, 
        'total_bs' => $total_bs,
        'es_apartado' => $es_apartado,
        'message' => $es_apartado 
            ? "🏷️ Apartado #$venta_id registrado con éxito. Stock reservado por $dias_limite días." 
            : "✅ Venta Presencial #$venta_id registrada con éxito."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error save_venta_presencial.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
