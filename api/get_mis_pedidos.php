<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require '../includes/db.php';

$usuarioId = $_SESSION['usuario_id'] ?? null;
if (!$usuarioId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

try {
    // Obtenemos las ventas del usuario actual con sus productos y el último pago asociado
    $sql = "SELECT v.*,
            (SELECT GROUP_CONCAT(CONCAT(cantidad, 'x ', nombre_producto) SEPARATOR ', ')
             FROM venta_detalles WHERE venta_id = v.id) as productos,
            (SELECT p.estado FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as pago_estado,
            (SELECT p.correccion_mensaje FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as correccion_mensaje,
            (SELECT p.receipt_path FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as receipt_path,
            (SELECT p.banco_origen FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as banco_origen,
            (SELECT p.telefono_origen FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as telefono_origen,
            (SELECT p.referencia FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as referencia,
            (SELECT p.metodo_pago FROM pagos p WHERE p.venta_id = v.id ORDER BY p.id DESC LIMIT 1) as metodo_pago
            FROM ventas v
            WHERE v.usuario_id = ?
            ORDER BY v.fecha_venta DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$usuarioId]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Adjuntar datos de apartados y abonos si existen
    foreach ($pedidos as &$p) {
        $stmtAp = $pdo->prepare("
            SELECT 
                a.*, 
                DATEDIFF(a.fecha_limite, CURDATE()) as dias_restantes
            FROM apartados a 
            WHERE a.venta_id = ?
            LIMIT 1
        ");
        $stmtAp->execute([$p['id']]);
        $apartado = $stmtAp->fetch(PDO::FETCH_ASSOC);

        if ($apartado) {
            $apartado['id'] = (int)$apartado['id'];
            $apartado['total_usd'] = (float)$apartado['total_usd'];
            $apartado['monto_abonado_usd'] = (float)$apartado['monto_abonado_usd'];
            $apartado['monto_pendiente_usd'] = (float)$apartado['monto_pendiente_usd'];
            $apartado['monto_minimo_inicial_usd'] = (float)$apartado['monto_minimo_inicial_usd'];
            $apartado['dias_restantes'] = (int)$apartado['dias_restantes'];
            $apartado['porcentaje_pagado'] = $apartado['total_usd'] > 0 ? round(($apartado['monto_abonado_usd'] / $apartado['total_usd']) * 100, 1) : 0;

            // Historial de abonos
            $stmtAb = $pdo->prepare("
                SELECT id, monto_usd, monto_bs, tasa_cambio, metodo_pago, referencia, comprobante_img, DATE_FORMAT(fecha_abono, '%Y-%m-%d %H:%i') as fecha_abono
                FROM apartado_abonos
                WHERE apartado_id = ?
                ORDER BY fecha_abono DESC
            ");
            $stmtAb->execute([$apartado['id']]);
            $apartado['abonos'] = $stmtAb->fetchAll(PDO::FETCH_ASSOC);

            $p['apartado'] = $apartado;
            $p['es_apartado'] = true;
        } else {
            $p['es_apartado'] = ($p['estado'] === 'Apartado');
            $p['apartado'] = null;
        }
    }

    // Reglas globales de apartados definidas por el administrador (cuotas y plazo)
    $cfgApartadoCliente = ['cuotas' => 3, 'dias_limite' => 15];
    try {
        $stmtCfgCl = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
        $rowCfgCl = $stmtCfgCl->fetch(PDO::FETCH_ASSOC);
        if ($rowCfgCl) {
            $cfgApartadoCliente['cuotas'] = !empty($rowCfgCl['apartado_cuotas']) ? (int)$rowCfgCl['apartado_cuotas'] : 3;
            $cfgApartadoCliente['dias_limite'] = !empty($rowCfgCl['apartado_dias_limite']) ? (int)$rowCfgCl['apartado_dias_limite'] : 15;
            if ($cfgApartadoCliente['cuotas'] < 1) $cfgApartadoCliente['cuotas'] = 3;
            if ($cfgApartadoCliente['dias_limite'] < 1) $cfgApartadoCliente['dias_limite'] = 15;
        }
    } catch (Exception $eCfg) {}

    echo json_encode(['success' => true, 'data' => $pedidos, 'config_apartado' => $cfgApartadoCliente]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
