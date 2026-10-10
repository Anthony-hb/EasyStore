<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $data = Sanitizer::readJsonInput();

    if (!$data || !isset($data['carrito']) || empty($data['carrito'])) {
        echo json_encode(['success' => false, 'message' => 'El carrito está vacío o los datos no son válidos.']);
        exit;
    }

    $carrito = $data['carrito'];
    if (!is_array($carrito) || count($carrito) === 0) {
        echo json_encode(['success' => false, 'message' => 'El carrito está vacío.']);
        exit;
    }

    // OJO: NO usar Sanitizer::int($x, 1) aqui. El 2do parametro es el MINIMO,
    // no el valor por defecto: Sanitizer::int(0, 1) devuelve 1, lo que hacia
    // que esta comprobacion nunca rechazara a un visitante anonimo y la compra
    // se registrara con usuario_id = 1.
    $usuarioId = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
    if ($usuarioId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Debes iniciar sesión para realizar la compra.']);
        exit;
    }

    // Obtener la tasa de cambio actual (con fallback seguro)
    $tasaBCV = 50.00;
    try {
        $stmtTasa = $pdo->query("SELECT tasa FROM tasa_dolar ORDER BY id DESC LIMIT 1");
        $tasaRow = $stmtTasa->fetch();
        if ($tasaRow && isset($tasaRow['tasa']) && floatval($tasaRow['tasa']) > 0) {
            $tasaBCV = (float)$tasaRow['tasa'];
        } else {
            $stmtOld = $pdo->query("SELECT valor FROM tasa_dolar WHERE clave = 'tasa_dolar' LIMIT 1");
            $oldRow = $stmtOld->fetch();
            if ($oldRow && isset($oldRow['valor']) && floatval($oldRow['valor']) > 0) {
                $tasaBCV = (float)$oldRow['valor'];
            }
        }
    } catch (Exception $e) {
        $tasaBCV = 50.00;
    }

    // Asegurar que la columna 'estado' soporte los valores nuevos
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'estado'");
        $col = $stmtCol->fetch();
        $type = $col['type'] ?? $col['Type'] ?? '';
        if ($type && !str_contains($type, 'Pago Enviado')) {
            $pdo->exec("ALTER TABLE ventas MODIFY COLUMN estado ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente') NOT NULL DEFAULT 'Pendiente Pago'");
        }
    } catch (Exception $e) {
        // Ignorar
    }

    // Iniciar transacción
    $pdo->beginTransaction();

    $totalUSD = 0;
    $totalBase = 0;
    $totalIva = 0;
    $productosParaRegistrar = [];

    // 1. Validar stock y calcular total
    foreach ($carrito as $item) {
        if (!is_array($item)) {
            throw new Exception('Hay un producto inválido en el carrito.');
        }

        // Igual que arriba: sin minimo, para que un id o cantidad 0 / negativa
        // se rechace en vez de convertirse silenciosamente en 1.
        $id = Sanitizer::int($item['id'] ?? 0);
        $cantidadPedida = Sanitizer::int($item['cantidad'] ?? 0);

        if ($id <= 0) {
            throw new Exception('El ID de uno de los productos del carrito no es válido.');
        }
        if ($cantidadPedida <= 0 || $cantidadPedida > 100) {
            throw new Exception('La cantidad de uno de los productos no es válida.');
        }

        // Obtener producto actual de la base de datos
        $stmt = $pdo->prepare("SELECT nombre, precio_usd, precio_compra, iva_alicuota, stock FROM productos WHERE id = ?");
        $stmt->execute([$id]);
        $productoBD = $stmt->fetch();

        if (!$productoBD) {
            throw new Exception("El producto ID {$id} no existe.");
        }

        if ($productoBD['stock'] < $cantidadPedida) {
            throw new Exception("Stock insuficiente para '{$productoBD['nombre']}'. Solo hay {$productoBD['stock']} disponibles.");
        }

        $precioUsd = (float)$productoBD['precio_usd'];
        $subtotal = $precioUsd * $cantidadPedida;
        $totalUSD += $subtotal;

        // El precio de catalogo es P.V.P. con IVA incluido: se desglosa la base
        // gravable y el IVA linea por linea. precio_unitario_usd y subtotal_usd
        // siguen siendo el bruto (lo que paga el cliente).
        $ivaAlicuota = (float)($productoBD['iva_alicuota'] ?? 16.00);
        $tasaIva = $ivaAlicuota / 100;
        $baseUnit = $tasaIva > 0 ? $precioUsd / (1 + $tasaIva) : $precioUsd;
        $baseLinea = round($baseUnit * $cantidadPedida, 2);
        $ivaLinea = round($subtotal - $baseLinea, 2);
        $totalBase += $baseLinea;
        $totalIva += $ivaLinea;

        $productosParaRegistrar[] = [
            'id' => $id,
            'nombre' => $productoBD['nombre'],
            'cantidad' => $cantidadPedida,
            'precio_unitario_usd' => $precioUsd,
            'base_usd' => $baseLinea,
            'iva_alicuota' => $ivaAlicuota,
            'iva_usd' => $ivaLinea,
            'costo_unitario_usd' => (float)$productoBD['precio_compra'],
            'subtotal_usd' => $subtotal
        ];
    }

    $totalUSD = round($totalUSD, 2);
    $totalBase = round($totalBase, 2);
    $totalIva = round($totalIva, 2);
    $totalBS = $totalUSD * $tasaBCV;

    $esApartado = !empty($data['es_apartado']) || ($data['tipo_compra'] ?? '') === 'apartado';

    // Reglas globales de apartados definidas por el administrador
    // (Historial de Clientes -> Configuración de Apartados).
    $cfgApartado = [];
    try {
        $stmtCfgAp = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
        $cfgApartado = $stmtCfgAp->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $cfgApartado = [];
    }
    $diasLimiteCfg = !empty($cfgApartado['apartado_dias_limite']) ? (int)$cfgApartado['apartado_dias_limite'] : 15;
    $cuotasApartado = !empty($cfgApartado['apartado_cuotas']) ? (int)$cfgApartado['apartado_cuotas'] : 3;
    if ($cuotasApartado < 1) $cuotasApartado = 3;

    $porcentajeApartado = isset($data['porcentaje_apartado']) ? max(10, min(90, (float)$data['porcentaje_apartado'])) : 30.00;
    $diasLimite = isset($data['dias_limite']) ? max(1, min(90, (int)$data['dias_limite'])) : $diasLimiteCfg;
    $montoMinimoInicialUSD = round(($totalUSD * $porcentajeApartado) / 100, 2);

    $estadoInicial = $esApartado ? 'Apartado' : 'Pendiente Pago';

    // 2. Registrar la venta
    $stmtVenta = $pdo->prepare("INSERT INTO ventas (total_usd, total_bs, base_gravada_usd, iva_usd, tasa_cambio, usuario_id, estado) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtVenta->execute([$totalUSD, $totalBS, $totalBase, $totalIva, $tasaBCV, $usuarioId, $estadoInicial]);
    $ventaId = $pdo->lastInsertId();

    // 3. Registrar detalles de la venta
    $stmtDetalle = $pdo->prepare("INSERT INTO venta_detalles (venta_id, producto_id, nombre_producto, cantidad, precio_unitario_usd, base_usd, iva_alicuota, iva_usd, costo_unitario_usd, subtotal_usd) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // NOTA: El stock se descuenta ÚNICAMENTE cuando el admin valide el pago (en validar_pago.php).
    // Para apartados, el stock se reserva lógicamente pero NO se modifica hasta la validación.
    foreach ($productosParaRegistrar as $prod) {
        $stmtDetalle->execute([
            $ventaId,
            $prod['id'],
            $prod['nombre'],
            $prod['cantidad'],
            $prod['precio_unitario_usd'],
            $prod['base_usd'],
            $prod['iva_alicuota'],
            $prod['iva_usd'],
            $prod['costo_unitario_usd'],
            $prod['subtotal_usd']
        ]);
    }

    $apartadoId = null;
    $fechaLimite = null;

    if ($esApartado) {
        $fechaLimite = date('Y-m-d', strtotime("+$diasLimite days"));
        
        // Obtener datos del cliente
        $stmtCli = $pdo->prepare("SELECT nombre_completo, cedula, telefono FROM clientes WHERE id = ?");
        $stmtCli->execute([$usuarioId]);
        $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);

        $stmtAp = $pdo->prepare("
            INSERT INTO apartados 
            (venta_id, usuario_id, cliente_nombre, cliente_cedula, cliente_telefono, total_usd, total_bs, tasa_cambio, monto_minimo_inicial_usd, porcentaje_inicial, monto_abonado_usd, monto_abonado_bs, monto_pendiente_usd, fecha_limite, dias_limite, cuotas, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, 'Activo')
        ");
        $stmtAp->execute([
            $ventaId,
            $usuarioId,
            $cli['nombre_completo'] ?? 'Cliente',
            $cli['cedula'] ?? '',
            $cli['telefono'] ?? '',
            $totalUSD,
            $totalBS,
            $tasaBCV,
            $montoMinimoInicialUSD,
            $porcentajeApartado,
            $totalUSD,
            $fechaLimite,
            $diasLimite,
            $cuotasApartado
        ]);
        $apartadoId = $pdo->lastInsertId();
    }

    // Confirmar transacción
    $pdo->commit();

    // 4. Obtener datos de métodos de pago para mostrar al cliente
    $pagoMovil = ['telefono' => '', 'banco' => '', 'cedula' => '', 'titular' => ''];
    $transferencia = ['banco' => '', 'cuenta' => '', 'titular' => '', 'cedula' => ''];
    $zelle = ['correo' => '', 'titular' => ''];
    $biopago = ['banco' => '', 'cedula' => '', 'titular' => ''];

    try {
        $stmtAdmin = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
        $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $pagoMovil = [
                'telefono' => $admin['pm_telefono'] ?? '',
                'banco' => $admin['pm_banco'] ?? '',
                'cedula' => $admin['pm_cedula'] ?? '',
                'titular' => $admin['nombre_completo'] ?? '',
            ];
            $transferencia = [
                'banco' => $admin['transf_banco'] ?? '',
                'cuenta' => $admin['transf_cuenta'] ?? '',
                'titular' => $admin['transf_titular'] ?? '',
                'cedula' => $admin['transf_cedula'] ?? '',
            ];
            $zelle = [
                'correo' => $admin['zelle_correo'] ?? '',
                'titular' => $admin['zelle_titular'] ?? '',
            ];
            $biopago = [
                'banco' => $admin['biopago_banco'] ?? '',
                'cedula' => $admin['biopago_cedula'] ?? '',
                'titular' => $admin['biopago_titular'] ?? '',
            ];
        }
    } catch (Exception $e) {
        // Fallback
        try {
            $stmtConfig = $pdo->query("SELECT clave, valor FROM tasa_dolar WHERE clave LIKE 'pm_%' OR clave LIKE 'transf_%'");
            $configRows = $stmtConfig->fetchAll();
            $config = [];
            foreach ($configRows as $row) {
                $config[$row['clave']] = $row['valor'];
            }
            $pagoMovil = [
                'telefono' => $config['pm_telefono'] ?? '',
                'banco' => $config['pm_banco'] ?? '',
                'cedula' => $config['pm_cedula'] ?? '',
                'titular' => $config['pm_titular'] ?? '',
            ];
            $transferencia = [
                'banco' => $config['transf_banco'] ?? '',
                'cuenta' => $config['transf_cuenta'] ?? '',
                'titular' => $config['transf_titular'] ?? '',
                'cedula' => $config['transf_cedula'] ?? '',
            ];
            $zelle = [
                'correo' => $config['zelle_correo'] ?? '',
                'titular' => $config['zelle_titular'] ?? '',
            ];
            $biopago = [
                'banco' => $config['biopago_banco'] ?? '',
                'cedula' => $config['biopago_cedula'] ?? '',
                'titular' => $config['biopago_titular'] ?? '',
            ];
        } catch (Exception $e2) {}
    }

    echo json_encode([
        'success' => true, 
        'message' => $esApartado 
            ? "¡Apartado registrado! Realiza el pago de al menos el {$porcentajeApartado}% ($" . number_format($montoMinimoInicialUSD, 2) . " USD) antes del {$fechaLimite}." 
            : 'Pedido registrado. Selecciona tu método de pago.', 
        'venta_id' => $ventaId,
        'es_apartado' => $esApartado,
        'apartado_id' => $apartadoId,
        'porcentaje_apartado' => $porcentajeApartado,
        'monto_minimo_inicial_usd' => $montoMinimoInicialUSD,
        'monto_minimo_inicial_bs' => round($montoMinimoInicialUSD * $tasaBCV, 2),
        'fecha_limite' => $fechaLimite,
        'dias_limite' => $diasLimite,
        'cuotas' => $cuotasApartado,
        'total_usd' => $totalUSD,
        'total_bs' => $totalBS,
        'pago_movil' => $pagoMovil,
        'transferencia' => $transferencia,
        'zelle' => $zelle,
        'biopago' => $biopago
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
