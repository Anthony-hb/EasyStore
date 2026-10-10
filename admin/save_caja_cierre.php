<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once '../includes/db.php';
require_once '../includes/schema.php';
require_once '../includes/caja.php';
// modulo de contabilidad retirado

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Garantiza que exista la columna reembolsos_efectivo_usd antes del UPDATE.
// Si el esquema ya esta bien, asegurarEsquemaBD no hace nada.
try {
    asegurarEsquemaBD($pdo);
} catch (Exception $e) {
    // Continuar con lo que ya exista
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos de cierre no recibidos.']);
    exit;
}

$turnoId = (int)($input['turno_id'] ?? 0);
$totalConteoUSD = floatval($input['total_conteo_usd'] ?? 0);
$observaciones = trim($input['observaciones'] ?? '');
$forzarCierre = !empty($input['forzar']); // fuerza cierre aunque queden pagos pendientes

// Conteos por método (nuevo arqueo completo)
$conteoEfectivoUSD      = floatval($input['conteo_efectivo_usd']      ?? $totalConteoUSD);
$conteoPagoMovilUSD     = floatval($input['conteo_pago_movil_usd']     ?? 0);
$conteoTransferenciaUSD = floatval($input['conteo_transferencia_usd'] ?? 0);
$conteoZelleUSD         = floatval($input['conteo_zelle_usd']         ?? 0);
$conteoBiopagoUSD       = floatval($input['conteo_biopago_usd']       ?? 0);
$conteoPuntoVentaUSD    = floatval($input['conteo_punto_venta_usd']    ?? 0);
$conteoOtrosUSD         = floatval($input['conteo_otros_usd']         ?? 0);

try {
    $pdo->beginTransaction();

    // Obtener turno activo
    $stmtT = $pdo->prepare("SELECT * FROM cajas_turnos WHERE id = ? AND estado = 'ABIERTA'");
    $stmtT->execute([$turnoId]);
    $turno = $stmtT->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        throw new Exception("No se encontró una caja abierta con el ID especificado.");
    }

    $fechaApertura = $turno['fecha_apertura'];

    // Calcular acumulación del turno con la misma lógica que el panel
    $resumen = cajaResumenTurno($pdo, $turno, (float)$turno['tasa_cambio']);
    $pendientes = $resumen['pagos_pendientes'];

    if ($pendientes['cantidad'] > 0 && !$forzarCierre) {
        $listado = array_map(function ($p) {
            $met = cajaNormalizarMetodo($p['metodo_pago']);
            $def = cajaMetodosDef()[$met] ?? cajaMetodosDef()['otros'];
            return [
                'venta_id'     => (int)$p['venta_id'],
                'metodo_pago'  => $def['etiqueta'],
                'monto_bs'     => (float)$p['monto'],
                'fecha_pago'   => $p['fecha_pago'],
                'estado'       => $p['estado'],
                'referencia'   => $p['referencia'],
            ];
        }, $pendientes['items']);
        $pdo->rollBack();
        ob_clean();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => "Quedan {$pendientes['cantidad']} pago(s) sin revisar (pendiente / en verificación) por Bs " . number_format($pendientes['monto_bs'], 2, ',', '.'),
            'pagos_pendientes' => $pendientes,
            'items'   => $listado,
            'requiere_confirmacion' => true,
        ]);
        exit;
    }

    $egresosCajaUSD = $resumen['egresos_caja_usd'];
    $reembolsosEfectivoUSD = $resumen['reembolsos_efectivo_usd'];
    $ventasEfectivoUSD = $resumen['ventas_efectivo_usd'];
    $ventasPagoMovilUSD = $resumen['ventas_pago_movil_usd'];
    $ventasTransfUSD = $resumen['ventas_transferencia_usd'];
    $ventasZelleUSD = $resumen['ventas_zelle_usd'];
    $ventasBiopagoUSD = $resumen['ventas_biopago_usd'];

    $totalEsperadoUSD = $resumen['total_esperado_efectivo_usd'];
    $diferenciaUSD = round($totalConteoUSD - $totalEsperadoUSD, 2);

    $ventasEfectivoBs = $resumen['ventas_efectivo_bs'];

    // Diferencias por método
    $difPagoMovilUSD     = round($conteoPagoMovilUSD     - $resumen['ventas_pago_movil_usd'], 2);
    $difTransferenciaUSD = round($conteoTransferenciaUSD - $resumen['ventas_transferencia_usd'], 2);
    $difZelleUSD         = round($conteoZelleUSD         - $resumen['ventas_zelle_usd'], 2);
    $difBiopagoUSD       = round($conteoBiopagoUSD       - $resumen['ventas_biopago_usd'], 2);
    $difPuntoVentaUSD    = round($conteoPuntoVentaUSD    - $resumen['ventas_punto_venta_usd'], 2);
    $difOtrosUSD         = round($conteoOtrosUSD         - $resumen['ventas_otros_usd'], 2);
    $conteoEfectivoFinal = $conteoEfectivoUSD > 0 ? $conteoEfectivoUSD : $totalConteoUSD;

    // Actualizar registro del turno a CERRADA
    $stmtUp = $pdo->prepare("
        UPDATE cajas_turnos SET 
            fecha_cierre = CURRENT_TIMESTAMP,
            ventas_efectivo_usd = ?,
            ventas_efectivo_bs = ?,
            ventas_pago_movil_usd = ?,
            ventas_transferencia_usd = ?,
            ventas_zelle_usd = ?,
            ventas_biopago_usd = ?,
            egresos_caja_usd = ?,
            reembolsos_efectivo_usd = ?,
            total_esperado_usd = ?,
            total_conteo_usd = ?,
            diferencia_usd = ?,
            conteo_efectivo_usd = ?,
            conteo_pago_movil_usd = ?,
            conteo_transferencia_usd = ?,
            conteo_zelle_usd = ?,
            conteo_biopago_usd = ?,
            conteo_punto_venta_usd = ?,
            conteo_otros_usd = ?,
            dif_pago_movil_usd = ?,
            dif_transferencia_usd = ?,
            dif_zelle_usd = ?,
            dif_biopago_usd = ?,
            dif_punto_venta_usd = ?,
            dif_otros_usd = ?,
            estado = 'CERRADA',
            observaciones = ?
        WHERE id = ?
    ");
    $stmtUp->execute([
        $ventasEfectivoUSD,
        $ventasEfectivoBs,
        $ventasPagoMovilUSD,
        $ventasTransfUSD,
        $ventasZelleUSD,
        $ventasBiopagoUSD,
        $egresosCajaUSD,
        $reembolsosEfectivoUSD,
        $totalEsperadoUSD,
        $conteoEfectivoFinal,
        $diferenciaUSD,
        $conteoEfectivoFinal,
        $conteoPagoMovilUSD,
        $conteoTransferenciaUSD,
        $conteoZelleUSD,
        $conteoBiopagoUSD,
        $conteoPuntoVentaUSD,
        $conteoOtrosUSD,
        $difPagoMovilUSD,
        $difTransferenciaUSD,
        $difZelleUSD,
        $difBiopagoUSD,
        $difPuntoVentaUSD,
        $difOtrosUSD,
        $observaciones,
        $turnoId
    ]);

    // La generacion de asientos contables por faltante/sobrante fue retirada con
    // el modulo de contabilidad. La diferencia se sigue registrando en cajas_turnos.
    $pdo->commit();

    $mensajeDiferencia = $diferenciaUSD == 0 ? 'Sin diferencia (Caja cuadrada).' : 
                         ($diferenciaUSD < 0 ? "Faltante en caja: -$" . abs($diferenciaUSD) : "Sobrante en caja: +$" . $diferenciaUSD);

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Cierre de caja completado con éxito. $mensajeDiferencia",
        'turno_id' => $turnoId,
        'reembolsos_efectivo_usd' => $reembolsosEfectivoUSD,
        'total_esperado_usd' => round($totalEsperadoUSD, 2),
        'diferencia_usd' => $diferenciaUSD,
        'pagos_pendientes' => $pendientes
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
