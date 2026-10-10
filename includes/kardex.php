<?php
/**
 * Kardex de Inventario
 * ---------------------------------------------------------------
 * El Kardex es el historial cronologico de ENTRADAS y SALIDAS de cada
 * producto. `productos.stock` solo guarda el saldo actual; aqui queda
 * registrado COMO se llego a ese saldo.
 *
 * Este archivo centraliza la operacion para que todos los modulos
 * (compras, ventas, devoluciones, mermas, apartados) registren el
 * movimiento con las mismas reglas y no seOlviden ninguno.
 *
 * Uso tipico:
 *   aplicarMovimientoKardex($pdo, [
 *       'producto_id'   => 12,
 *       'cantidad'      => -3,            // positivo = ENTRADA, negativo = SALIDA
 *       'concepto'      => 'VENTA',
 *       'venta_id'      => 8,
 *       'observaciones' => 'Venta #8',
 *   ]);
 */

require_once __DIR__ . '/db.php';

/**
 * Conceptos de movimiento validos (deben coincidir con el ENUM de la tabla).
 */
function kardexConceptos(): array
{
    return [
        'COMPRA'                        => 'Compra a proveedor',
        'ABASTECIMIENTO'                => 'Abastecimiento',
        'AJUSTE_INVENTARIO'             => 'Ajuste de inventario',
        'VENTA'                         => 'Venta',
        'DEVOLUCION_VENTA'              => 'Devolucion de venta',
        'ANULACION_VENTA'               => 'Anulacion / cancelacion de venta',
        'DEVOLUCION_PROVEEDOR'          => 'Devolucion a proveedor',
        'ANULACION_DEVOLUCION_PROVEEDOR' => 'Anulacion de devolucion a proveedor',
        'MERMA'                         => 'Perdida',
        'ANULACION_MERMA'               => 'Anulacion de perdida',
    ];
}

/**
 * Aplica el movimiento de stock y deja el registro en el Kardex.
 *
 * @param PDO    $pdo   Conexion (normalmente dentro de una transaccion abierta).
 * @param array  $mov   Descripcion del movimiento:
 *   - producto_id  (int, obligatorio)  Producto afectado.
 *   - cantidad     (int, obligatorio)  Positivo = entrada, negativo = salida.
 *   - concepto     (string, obligatorio) Clave de kardexConceptos().
 *   - nombre_producto (string, opcional) Si falta, se toma del producto.
 *   - documento_tipo, documento_id, numero_referencia (opcionales) Referencia legible.
 *   - costo_unitario_usd (float, opcional) Si no se envia se usa productos.precio_compra.
 *   - compra_id, compra_detalle_id, venta_id, venta_detalle_id, merma_id,
 *     devolucion_id, devolucion_proveedor_id (opcionales) Trazabilidad.
 *   - usuario_id   (int, opcional) Si falta se usa la sesion activa.
 *   - observaciones (string, opcional)
 *   - estricto     (bool, opcional) Si es true y no hay stock suficiente lanza
 *                   excepcion en lugar de dejar el saldo en 0.
 *
 * @return int|null  Id del registro del Kardex, o null si el producto ya no existe.
 * @throws Exception  Si el movimiento es invalido o (con 'estricto') no hay stock.
 */
function aplicarMovimientoKardex(PDO $pdo, array $mov): ?int
{
    $productoId = isset($mov['producto_id']) ? (int)$mov['producto_id'] : 0;
    if ($productoId <= 0) {
        throw new Exception('registrarKardex: producto_id invalido.');
    }

    $cantidad = isset($mov['cantidad']) ? (int)$mov['cantidad'] : 0;
    if ($cantidad === 0) {
        throw new Exception('registrarKardex: la cantidad del movimiento debe ser distinta de cero.');
    }

    $concepto = strtoupper(trim((string)($mov['concepto'] ?? '')));
    if (!array_key_exists($concepto, kardexConceptos())) {
        throw new Exception('registrarKardex: concepto de kardex no valido (' . $concepto . ').');
    }

    $estricto = !empty($mov['estricto']);

    // Bloquear el producto: garantiza que el saldo y el Kardex no se desincronicen.
    $stmt = $pdo->prepare("SELECT nombre, stock, precio_compra FROM productos WHERE id = ? FOR UPDATE");
    $stmt->execute([$productoId]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        return null;
    }

    $stockAnterior = (int)$producto['stock'];
    $unidades      = abs($cantidad);
    $esEntrada     = $cantidad > 0;

    if (!$esEntrada && $estricto && $stockAnterior < $unidades) {
        throw new Exception(
            "Stock insuficiente para '{$producto['nombre']}'. Disponible: {$stockAnterior}, requerido: {$unidades}."
        );
    }

    // Aplicar el movimiento sobre el stock.
    $aviso = '';
    if ($esEntrada) {
        $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?")->execute([$unidades, $productoId]);
        $stockNuevo = $stockAnterior + $unidades;
    } else {
        // Sin 'estricto' el saldo no puede quedar negativo: se descuenta lo que
        // haya en inventario. Si no alcanza, solo se registra la diferencia real
        // (de lo contrario el Kardex quedaria descuadrado) y se avisa por aqui.
        $solicitado = $unidades;
        $descontado = min($unidades, $stockAnterior);
        $pdo->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?")->execute([$descontado, $productoId]);
        $stockNuevo = $stockAnterior - $descontado;

        if ($descontado < $solicitado) {
            $unidades = $descontado;
            $aviso = "Ojo: se pidieron {$solicitado} unidades pero el inventario solo tenia {$stockAnterior}; "
                   . "se descontaron las que habia.";
        }
    }

    // Costo unitario: el informado, o el costo de compra del producto.
    $costoUnitario = isset($mov['costo_unitario_usd'])
        ? (float)$mov['costo_unitario_usd']
        : (float)($producto['precio_compra'] ?? 0);

    if ($costoUnitario < 0) {
        $costoUnitario = 0.0;
    }

    $referencias = [
        'compra_id'                => $mov['compra_id']                ?? null,
        'compra_detalle_id'        => $mov['compra_detalle_id']        ?? null,
        'venta_id'                 => $mov['venta_id']                 ?? null,
        'venta_detalle_id'         => $mov['venta_detalle_id']         ?? null,
        'merma_id'                 => $mov['merma_id']                 ?? null,
        'devolucion_id'            => $mov['devolucion_id']            ?? null,
        'devolucion_proveedor_id'  => $mov['devolucion_proveedor_id']  ?? null,
    ];
    foreach ($referencias as $clave => $valor) {
        $referencias[$clave] = ($valor === null || $valor === '') ? null : (int)$valor;
    }

    $nombreProducto = trim((string)($mov['nombre_producto'] ?? ''));
    if ($nombreProducto === '') {
        $nombreProducto = (string)$producto['nombre'];
    }

    $usuarioId = $mov['usuario_id'] ?? ($_SESSION['usuario_id'] ?? null);
    $usuarioId = ($usuarioId === null || $usuarioId === '') ? null : (int)$usuarioId;

    $observaciones = trim((string)($mov['observaciones'] ?? ''));
    if ($aviso !== '') {
        $observaciones = trim($observaciones . ($observaciones !== '' ? ' | ' : '') . $aviso);
    }

    $sqlKardex = "INSERT INTO kardex_inventario (
            producto_id, nombre_producto, tipo_movimiento, concepto,
            documento_tipo, documento_id, numero_referencia,
            cantidad_entrada, cantidad_salida, stock_anterior, stock_nuevo,
            costo_unitario_usd, costo_total_usd,
            compra_id, compra_detalle_id, venta_id, venta_detalle_id,
            merma_id, devolucion_id, devolucion_proveedor_id,
            usuario_id, observaciones
        ) VALUES (
            :producto_id, :nombre_producto, :tipo_movimiento, :concepto,
            :documento_tipo, :documento_id, :numero_referencia,
            :cantidad_entrada, :cantidad_salida, :stock_anterior, :stock_nuevo,
            :costo_unitario_usd, :costo_total_usd,
            :compra_id, :compra_detalle_id, :venta_id, :venta_detalle_id,
            :merma_id, :devolucion_id, :devolucion_proveedor_id,
            :usuario_id, :observaciones
        )";

    $stmtK = $pdo->prepare($sqlKardex);
    $stmtK->execute([
        ':producto_id'        => $productoId,
        ':nombre_producto'    => mb_substr($nombreProducto, 0, 255),
        ':tipo_movimiento'    => $esEntrada ? 'ENTRADA' : 'SALIDA',
        ':concepto'           => $concepto,
        ':documento_tipo'     => $mov['documento_tipo'] ?? null,
        ':documento_id'       => isset($mov['documento_id']) && $mov['documento_id'] !== '' ? (int)$mov['documento_id'] : null,
        ':numero_referencia'  => isset($mov['numero_referencia']) && $mov['numero_referencia'] !== ''
            ? mb_substr((string)$mov['numero_referencia'], 0, 100) : null,
        ':cantidad_entrada'   => $esEntrada ? $unidades : 0,
        ':cantidad_salida'    => $esEntrada ? 0 : $unidades,
        ':stock_anterior'     => $stockAnterior,
        ':stock_nuevo'        => $stockNuevo,
        ':costo_unitario_usd' => $costoUnitario,
        ':costo_total_usd'    => round($costoUnitario * $unidades, 2),
        ':compra_id'          => $referencias['compra_id'],
        ':compra_detalle_id'  => $referencias['compra_detalle_id'],
        ':venta_id'           => $referencias['venta_id'],
        ':venta_detalle_id'   => $referencias['venta_detalle_id'],
        ':merma_id'           => $referencias['merma_id'],
        ':devolucion_id'      => $referencias['devolucion_id'],
        ':devolucion_proveedor_id' => $referencias['devolucion_proveedor_id'],
        ':usuario_id'         => $usuarioId,
        ':observaciones'      => $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Registra UN ajuste manual de inventario desde el modulo de Kardex.
 * Es la unica via para corregir saldos que no vienen de una operacion real.
 */
function registrarAjusteKardex(PDO $pdo, int $productoId, int $cantidad, string $motivo): ?int
{
    if (trim($motivo) === '') {
        throw new Exception('Un ajuste de inventario necesita un motivo.');
    }

    return aplicarMovimientoKardex($pdo, [
        'producto_id'   => $productoId,
        'cantidad'      => $cantidad,
        'concepto'      => 'AJUSTE_INVENTARIO',
        'documento_tipo' => 'AJUSTE',
        'observaciones' => $motivo,
    ]);
}

/**
 * Total de entradas, salidas y valor del inventario en un rango de fechas.
 * $filtros acepta: producto_id, desde, hasta (YYYY-MM-DD).
 */
function kardexResumen(PDO $pdo, array $filtros = []): array
{
    $where  = [];
    $params = [];

    $productoId = isset($filtros['producto_id']) ? (int)$filtros['producto_id'] : 0;
    if ($productoId > 0) {
        $where[] = 'k.producto_id = :producto_id';
        $params[':producto_id'] = $productoId;
    }

    $desde = trim((string)($filtros['desde'] ?? ''));
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = 'DATE(k.fecha_movimiento) >= :desde';
        $params[':desde'] = $desde;
    }

    $hasta = trim((string)($filtros['hasta'] ?? ''));
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = 'DATE(k.fecha_movimiento) <= :hasta';
        $params[':hasta'] = $hasta;
    }

    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(k.cantidad_entrada), 0) AS entradas,
            COALESCE(SUM(k.cantidad_salida), 0)  AS salidas,
            COALESCE(SUM(k.costo_total_usd), 0)  AS valor_movimientos,
            COUNT(*) AS movimientos
        FROM kardex_inventario k
        $sqlWhere");
    $stmt->execute($params);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $resumen['entradas']          = (int)($resumen['entradas'] ?? 0);
    $resumen['salidas']           = (int)($resumen['salidas'] ?? 0);
    $resumen['valor_movimientos'] = round((float)($resumen['valor_movimientos'] ?? 0), 2);
    $resumen['movimientos']       = (int)($resumen['movimientos'] ?? 0);

    // Valorizacion del inventario actual (stock x costo de compra).
    $sqlStock = "SELECT COALESCE(SUM(stock * COALESCE(precio_compra, 0)), 0) AS valor_inventario,
                        COALESCE(SUM(stock), 0) AS unidades
                 FROM productos";
    if ($productoId > 0) {
        $sqlStock .= " WHERE id = " . $productoId;
    }
    $fila = $pdo->query($sqlStock)->fetch(PDO::FETCH_ASSOC) ?: [];
    $resumen['valor_inventario'] = round((float)($fila['valor_inventario'] ?? 0), 2);
    $resumen['unidades']         = (int)($fila['unidades'] ?? 0);

    return $resumen;
}