<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../includes/db.php';
$stmtNeg = $pdo->query("SELECT negocio_nombre FROM administrador ORDER BY id ASC LIMIT 1");
$rowNeg = $stmtNeg ? $stmtNeg->fetch(PDO::FETCH_ASSOC) : null;
$nombreTienda = !empty($rowNeg['negocio_nombre']) ? htmlspecialchars($rowNeg['negocio_nombre']) : 'Tecnology';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Pedidos | <?php echo $nombreTienda; ?></title>
    <script>
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark-theme');
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=3.4">
    <style>
        body { background-color: var(--bg); color: var(--text); }
        .orders-container {
            max-width: 1000px;
            margin: 50px auto;
            padding: 0 20px;
        }
        .orders-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .order-card {
            background: var(--card-bg);
            color: var(--text);
            padding: 25px;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            transition: var(--transition);
        }
        .order-card:hover { transform: translateY(-5px); }
        .order-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding-bottom: 15px;
            margin-bottom: 15px;
            gap: 15px;
            flex-wrap: wrap;
        }
        .order-id { font-weight: 800; color: var(--primary); }
        .order-date { color: var(--text-light); font-size: 0.9rem; }
        .order-products { font-size: 0.95rem; color: var(--text); line-height: 1.6; }
        .order-total {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: flex-end;
            gap: 20px;
        }
        .total-item { display: flex; flex-direction: column; align-items: flex-end; }
        .total-label { font-size: 0.8rem; color: var(--text-light); font-weight: 600; }
        .total-value { font-size: 1.2rem; font-weight: 800; }
        .back-btn {
            text-decoration: none;
            color: var(--primary);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pendiente {
            background-color: rgba(255, 159, 28, 0.15);
            color: #ff9f1c;
        }
        .status-verificacion {
            background-color: rgba(46, 152, 255, 0.15);
            color: #2d6cdf;
        }
        .status-correccion {
            background-color: rgba(255, 205, 86, 0.15);
            color: #d39e00;
        }
        .status-validado {
            background-color: rgba(46, 204, 113, 0.15);
            color: #27ae60;
        }
        .status-cancelado {
            background-color: rgba(255, 99, 71, 0.15);
            color: #c0392b;
        }
        .status-preparando {
            background-color: rgba(67, 97, 238, 0.15);
            color: #4361ee;
        }
        .status-entregado {
            background-color: rgba(46, 204, 113, 0.15);
            color: #2ecc71;
        }
        .upload-box {
            margin-top: 20px;
            padding: 18px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(243, 246, 255, 0.95), rgba(255, 255, 255, 0.98));
            border: 1px solid rgba(67, 97, 238, 0.18);
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.05);
        }
        .upload-box strong {
            display: block;
            margin-bottom: 10px;
            font-size: 1rem;
            color: #1d2a78;
        }
        .upload-box p {
            margin: 0 0 12px;
            color: #3d4b6a;
            line-height: 1.6;
        }
        .upload-box input[type=file] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(67, 97, 238, 0.2);
            background: rgba(255, 255, 255, 0.92);
            cursor: pointer;
        }
        .upload-box button,
        .order-card .btn-primary {
            padding: 12px 18px;
            background: linear-gradient(135deg, #3f66ff, #2d6cdf);
            color: white;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 14px 24px rgba(45, 108, 223, 0.18);
        }
        .upload-box button:hover,
        .order-card .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 32px rgba(45, 108, 223, 0.22);
        }
        .upload-box button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="orders-container">
        <div class="orders-header">
            <div>
                <a href="../index.html" class="back-btn">← Volver a la Tienda</a>
                <a href="mi_cuenta.php" class="back-btn" style="margin-left: 10px; text-decoration: none;">⚙️ Mi Cuenta</a>
                <button type="button" class="help-btn-header" onclick="abrirModalAyuda('cliente')" style="display: inline-flex; margin-left: 10px;" title="Centro de Ayuda / Manual de Usuario">
                    ❓ Ayuda
                </button>
            </div>
            <h1 style="font-weight: 800; margin: 0;">📦 Mis Pedidos</h1>
        </div>

        <div id="orders-list">
            <!-- Cargado por JS -->
            <div style="text-align: center; padding: 50px;">
                <div style="font-size: 3rem;">⌛</div>
                <p>Cargando tu historial...</p>
            </div>
        </div>
    </div>

    <!-- Modal Reportar Abono Cliente -->
    <div id="modalReportarAbonoCliente" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:9999; display:none; justify-content:center; align-items:center; padding:16px; box-sizing:border-box;">
        <div style="background:#fff; border-radius:18px; width:100%; max-width:520px; max-height:92vh; overflow-y:auto; padding:26px; box-shadow:0 20px 45px rgba(0,0,0,0.25); position:relative;">
            <button type="button" onclick="cerrarModalReportarAbonoCliente()" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; border-radius:50%; width:34px; height:34px; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b;">&times;</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                <div style="font-size:1.8rem;">💵</div>
                <div>
                    <h3 style="margin:0; font-size:1.25rem; font-weight:800; color:#1e293b;">Reportar Abono de Apartado</h3>
                    <p style="margin:2px 0 0 0; font-size:0.85rem; color:#64748b;">Abona a tu producto reservado</p>
                </div>
            </div>

            <!-- Resumen Saldo -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:18px; display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:0.88rem;">
                <div>
                    <span style="color:#64748b; display:block; font-size:0.78rem;">Total del Apartado:</span>
                    <strong id="modalApTotalUsd" style="color:#1e293b; font-size:1.05rem;">$0.00 USD</strong>
                </div>
                <div>
                    <span style="color:#64748b; display:block; font-size:0.78rem;">Saldo Pendiente:</span>
                    <strong id="modalApPendienteUsd" style="color:#dc2626; font-size:1.05rem;">$0.00 USD</strong>
                </div>
                <div style="grid-column:1 / -1; border-top:1px dashed #cbd5e1; pt:8px; margin-top:4px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.8rem; color:#64748b;">Tasa BCV Referencial:</span>
                    <strong id="modalApTasa" style="font-size:0.85rem; color:#0f766e;">Bs 0.00 / USD</strong>
                </div>
            </div>

            <form id="formAbonoCliente" onsubmit="enviarAbonoCliente(event)">
                <input type="hidden" id="modalApId" name="apartado_id" value="" />
                
                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.85rem; font-weight:700; color:#334155; margin-bottom:6px;">Monto a Abonar en USD <span style="color:#dc2626;">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:700; color:#64748b;">$</span>
                        <input type="number" id="inputAbonoUsd" name="monto_usd" step="0.01" min="0.5" required placeholder="0.00" oninput="actualizarConversionBsAbono()" style="width:100%; box-sizing:border-box; padding:10px 12px 10px 28px; border:1px solid #cbd5e1; border-radius:10px; font-size:1.05rem; font-weight:700; color:#1e293b;" />
                    </div>
                    <div style="margin-top:4px; font-size:0.82rem; color:#0f766e; font-weight:600;" id="modalApEquivBs">Equivalente en Bs: Bs 0.00</div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.85rem; font-weight:700; color:#334155; margin-bottom:6px;">Método de Pago <span style="color:#dc2626;">*</span></label>
                    <select id="selectAbonoMetodo" name="metodo_pago" required style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:0.92rem; background:#fff;">
                        <option value="Pago Móvil">📱 Pago Móvil</option>
                        <option value="Transferencia Bancaria">🏦 Transferencia Bancaria</option>
                        <option value="Efectivo USD (Pago en Tienda)">💵 Efectivo USD (En Tienda)</option>
                        <option value="Efectivo Bs (Pago en Tienda)">💵 Efectivo Bs (En Tienda)</option>
                        <option value="Punto de Venta (En Tienda)">💳 Tarjeta / Punto de Venta</option>
                        <option value="Zelle / Binance">🌐 Zelle / Binance</option>
                        <option value="Biopago">⚡ Biopago</option>
                    </select>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.85rem; font-weight:700; color:#334155; margin-bottom:6px;">Número de Referencia <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="inputAbonoRef" name="referencia" required placeholder="Ej: 12345678" style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:0.92rem;" />
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:0.85rem; font-weight:700; color:#334155; margin-bottom:6px;">Adjuntar Comprobante de Pago (Opcional)</label>
                    <input type="file" id="inputAbonoFile" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf" style="width:100%; box-sizing:border-box; padding:8px; border:1px solid #cbd5e1; border-radius:10px; font-size:0.85rem; background:#fff;" />
                    <small style="color:#64748b; font-size:0.75rem; display:block; margin-top:3px;">Formatos permitidos: JPG, PNG, WEBP o PDF</small>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block; font-size:0.85rem; font-weight:700; color:#334155; margin-bottom:6px;">Notas adicionales (Opcional)</label>
                    <input type="text" id="inputAbonoNotas" name="notas" placeholder="Ej: Abono correspondiente a la 2da cuota" style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; font-size:0.88rem;" />
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="cerrarModalReportarAbonoCliente()" style="background:#f1f5f9; color:#475569; border:none; padding:11px 18px; border-radius:10px; font-weight:700; cursor:pointer;">Cancelar</button>
                    <button type="submit" id="btnEnviarAbonoCliente" style="background:#15803d; color:white; border:none; padding:11px 22px; border-radius:10px; font-weight:700; cursor:pointer; box-shadow:0 8px 16px rgba(21,128,61,0.25);">📤 Registrar Abono</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function escapeHtml(text) {
            return String(text ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        }

        function getStatusBadge(estado, pagoEstado, esApartado, apartado) {
            const estadoLower = String(estado || '').toLowerCase();
            const pagoLower = String(pagoEstado || '').toLowerCase();

            if (esApartado && apartado) {
                if (apartado.estado === 'Completado') {
                    return '<span class="status-badge" style="background:#dcfce7; color:#15803d;">🎉 APARTADO 100% PAGADO (Listo para retirar)</span>';
                }
                if (apartado.estado === 'Cancelado') {
                    return '<span class="status-badge status-cancelado">❌ APARTADO CANCELADO</span>';
                }
                if (apartado.dias_restantes < 0) {
                    return '<span class="status-badge" style="background:#fee2e2; color:#b91c1c;">🚨 APARTADO VENCIDO</span>';
                }
                return '<span class="status-badge" style="background:#e0e7ff; color:#4338ca;">🏷️ APARTADO ACTIVO (En abonos)</span>';
            }

            if (!pagoEstado && (estadoLower === 'pendiente pago' || estadoLower === 'pendiente')) {
                return '';
            }
            if (estadoLower.includes('validado') || estadoLower.includes('entregado') || pagoLower === 'aprobado' || pagoLower === 'validado') {
                return '<span class="status-badge status-validado">✅ ' + escapeHtml(estado) + '</span>';
            }
            if (estadoLower.includes('preparando')) {
                return '<span class="status-badge status-preparando">📦 ' + escapeHtml(estado) + '</span>';
            }
            if (estadoLower.includes('cancel')) {
                return '<span class="status-badge status-cancelado">❌ ' + escapeHtml(estado) + '</span>';
            }
            if (estadoLower.includes('corrección') || estadoLower.includes('correccion') || pagoLower === 'rechazado') {
                return '<span class="status-badge status-correccion">⚠️ Corrección Solicitada</span>';
            }
            if (estadoLower === 'pago enviado' || pagoLower === 'pendiente' || pagoLower === 'en_verificacion') {
                return '<span class="status-badge status-verificacion">⏳ Pago Enviado (En Verificación)</span>';
            }
            return '';
        }

        function formatTelForPdf(tel) {
            if (!tel) return 'N/A';
            const clean = String(tel).replace(/[^0-9]/g, '');
            if (clean.length === 11) {
                return clean.substring(0, 4) + '-' + clean.substring(4);
            }
            if (clean.length === 7) {
                return '0412-' + clean;
            }
            return tel;
        }

        document.addEventListener('DOMContentLoaded', () => {
            fetch('../api/get_mis_pedidos.php')
                .then(res => res.json())
                .then(data => {
                    const container = document.getElementById('orders-list');
                    // Reglas globales de apartados definidas por el administrador
                    const cfgApartadoCliente = Object.assign({ cuotas: 3, dias_limite: 15 }, data.config_apartado || {});
                    if (data.success) {
                        if (data.data.length === 0) {
                            container.innerHTML = `
                                <div style="text-align: center; padding: 100px; background: var(--card-bg); border-radius: 30px;">
                                    <div style="font-size: 4rem; margin-bottom: 20px;">🛒</div>
                                    <h2 style="margin-bottom: 10px;">Aún no tienes pedidos</h2>
                                    <p style="color: var(--text-light);">¡Explora nuestro catálogo y realiza tu primera compra o apartado!</p>
                                    <a href="../index.html" class="btn-primary" style="display: inline-block; margin-top: 30px; text-decoration: none;">Ir a la tienda</a>
                                </div>
                            `;
                            return;
                        }

                        window.pedidosMap = {};
                        container.innerHTML = data.data.map(p => {
                            window.pedidosMap[p.id] = p;
                            const statusBadge = getStatusBadge(p.estado, p.pago_estado, p.es_apartado, p.apartado);
                            const badgeHtml = statusBadge ? `<div style="margin-bottom: 12px;">${statusBadge}</div>` : '';
                            
                            let pagoEstadoHtml = '';
                            if (!p.pago_estado && (p.estado === 'Pendiente Pago' || p.estado === 'Pendiente')) {
                                pagoEstadoHtml = '';
                            } else if (p.pago_estado === 'pendiente' || p.pago_estado === 'en_verificacion' || p.estado === 'Pago Enviado') {
                                pagoEstadoHtml = '<div style="margin-top: 10px; color: #2563eb; font-weight: 600; font-size: 0.9rem; background: rgba(37, 99, 235, 0.1); padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(37, 99, 235, 0.2);">⏳ Información de pago enviada. El administrador está verificando tu transacción.</div>';
                            } else if (p.pago_estado === 'rechazado' || p.estado === 'Corrección solicitada') {
                                pagoEstadoHtml = '<div style="margin-top: 10px; color: #dc2626; font-weight: 600; font-size: 0.9rem; background: rgba(220, 38, 38, 0.1); padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(220, 38, 38, 0.2);">⚠️ El administrador solicitó corregir los datos de tu pago.</div>';
                            } else if (p.pago_estado) {
                                pagoEstadoHtml = '<div style="margin-top: 10px; color: var(--text-light); font-size: 0.9rem;"><strong>Estado del Pago:</strong> ' + escapeHtml(p.pago_estado) + '</div>';
                            }
                            
                            // Bloque especial si es un APARTADO
                            let apartadoBloqueHtml = '';
                            if (p.es_apartado && p.apartado) {
                                const ap = p.apartado;
                                const pct = ap.porcentaje_pagado || 0;
                                const colorBarra = pct >= 100 ? '#16a34a' : (pct >= 50 ? '#0284c7' : '#d97706');
                                const esCompletado = (ap.estado === 'Completado');
                                const esCancelado = (ap.estado === 'Cancelado');

                                let tiempoMsg = '';
                                if (esCancelado) {
                                    tiempoMsg = `<span style="color:#b91c1c;">Este apartado fue cancelado.</span>`;
                                } else if (esCompletado) {
                                    tiempoMsg = `<span style="color:#15803d; font-weight:700;">✅ ¡Has pagado el 100%! Ya puedes pasar a retirar tu producto en nuestra tienda física.</span>`;
                                } else if (ap.dias_restantes < 0) {
                                    tiempoMsg = `<span style="color:#b91c1c; font-weight:700;">⚠️ El plazo límite de ${ap.dias_limite || 15} días venció el ${ap.fecha_limite}. Por favor completa el saldo para poder retirar tu producto.</span>`;
                                } else if (ap.dias_restantes === 0) {
                                    tiempoMsg = `<span style="color:#ea580c; font-weight:800;">⚡ ¡El plazo límite vence HOY (${ap.fecha_limite})!</span>`;
                                } else {
                                    tiempoMsg = `<span style="color:#4338ca; font-weight:600;">⏳ Tienes hasta el <strong>${ap.fecha_limite}</strong> (quedan <strong>${ap.dias_restantes} días</strong>) para pagar el restante.</span>`;
                                }

                                // Plan de cuotas definido por el administrador
                                const cuotasAp = parseInt(ap.cuotas, 10) || cfgApartadoCliente.cuotas;
                                const cuotaAp = (ap.total_usd > 0) ? Math.round((ap.total_usd / cuotasAp) * 100) / 100 : 0;
                                let planMsg = '';
                                let sugeridaAp = 0;
                                if (cuotaAp > 0 && !esCancelado) {
                                    const abonadoAp = parseFloat(ap.monto_abonado_usd || 0);
                                    let pagadasAp = Math.floor((abonadoAp + 0.005) / cuotaAp);
                                    pagadasAp = Math.max(0, Math.min(cuotasAp, pagadasAp));
                                    if (parseFloat(ap.monto_pendiente_usd || 0) <= 0.001) pagadasAp = cuotasAp;
                                    const restantesAp = Math.max(0, cuotasAp - pagadasAp);
                                    sugeridaAp = restantesAp > 0 ? Math.round((parseFloat(ap.monto_pendiente_usd || 0) / restantesAp) * 100) / 100 : 0;
                                    planMsg = `
                                        <div style="margin-top:10px; font-size:0.8rem; color:#4338ca; background:#eef2ff; border:1px dashed #c7d2fe; border-radius:8px; padding:8px 10px; line-height:1.6;">
                                            📐 Plan: <strong>${cuotasAp} cuota${cuotasAp > 1 ? 's' : ''} de $${cuotaAp.toFixed(2)}</strong> ·
                                            Pagadas <strong>${pagadasAp}/${cuotasAp}</strong>
                                            ${restantesAp > 0
                                                ? `· Te falta${restantesAp > 1 ? 'n' : ''} <strong>${restantesAp} cuota${restantesAp > 1 ? 's' : ''}</strong> de <strong>$${sugeridaAp.toFixed(2)}</strong>`
                                                : '· ✅ ¡Ya completaste tus cuotas!'}
                                        </div>
                                    `;
                                }

                                const abonosHtml = (ap.abonos && ap.abonos.length > 0) ? `
                                    <div style="margin-top:12px; font-size:0.82rem; background:#fff; padding:10px 14px; border-radius:10px; border:1px solid #e5e7eb;">
                                        <strong>Historial de abonos realizados:</strong>
                                        <ul style="margin:6px 0 0 0; padding-left:18px; color:#4b5569;">
                                            ${ap.abonos.map(ab => `
                                                <li>${ab.fecha_abono}: <strong>$${parseFloat(ab.monto_usd).toFixed(2)} USD</strong> (Bs ${parseFloat(ab.monto_bs||0).toLocaleString('es-VE', {minimumFractionDigits: 2})}) — <em>${escapeHtml(ab.metodo_pago)} (Ref: ${escapeHtml(ab.referencia || '-')})</em></li>
                                            `).join('')}
                                        </ul>
                                    </div>
                                ` : '';

                                const btnAbonarHtml = (!esCompletado && !esCancelado) ? `
                                    <div style="margin-top:14px;">
                                        <button type="button" class="btn-primary" onclick="abrirModalReportarAbonoCliente(${ap.id}, ${ap.total_usd}, ${ap.monto_abonado_usd}, ${ap.monto_pendiente_usd}, ${ap.tasa_cambio}, ${sugeridaAp})" style="background:#15803d; border:none; padding:11px 18px; border-radius:10px; font-weight:700; cursor:pointer; color:white; display:inline-flex; align-items:center; gap:8px;">
                                            💵 Reportar Abono / Pago Parcial
                                        </button>
                                    </div>
                                ` : '';

                                apartadoBloqueHtml = `
                                    <div style="margin-top:15px; padding:16px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; font-size:0.9rem;">
                                            <div>Total Apartado: <strong>$${ap.total_usd.toFixed(2)} USD</strong></div>
                                            <div>Total Abonado: <strong style="color:#15803d;">$${ap.monto_abonado_usd.toFixed(2)} USD</strong></div>
                                            <div>Saldo Pendiente: <strong style="color:${ap.monto_pendiente_usd > 0 ? '#b91c1c' : '#15803d'};">$${ap.monto_pendiente_usd.toFixed(2)} USD</strong></div>
                                        </div>

                                        <div style="width:100%; background:#e2e8f0; border-radius:8px; height:10px; overflow:hidden; margin:12px 0 6px 0;">
                                            <div style="width:${pct}%; background:${colorBarra}; height:100%;"></div>
                                        </div>
                                        <div style="font-size:0.78rem; color:#64748b; text-align:right;">${pct}% pagado</div>

                                        <div style="margin-top:8px; font-size:0.85rem; line-height:1.5;">
                                            ${tiempoMsg}
                                            ${planMsg}
                                        </div>

                                        <div style="font-size:0.8rem; color:#64748b; margin-top:6px;">
                                            ℹ️ Tu producto se encuentra reservado en stock. Podrás retirarlo en nuestra tienda física una vez completado el 100% del pago.
                                        </div>

                                        ${abonosHtml}
                                        ${btnAbonarHtml}
                                    </div>
                                `;
                            }

                            const telFormateado = formatTelForPdf(p.telefono_origen);
                            const pagoInfo = p.banco_origen ? `<div style="margin-top: 8px; font-size: 0.85rem; color: var(--text-light); background: rgba(0,0,0,0.03); padding: 8px 12px; border-radius: 8px;">
                                <strong>Banco enviado:</strong> ${escapeHtml(p.banco_origen)} | 
                                <strong>Teléfono:</strong> ${escapeHtml(telFormateado)} | 
                                <strong>Ref:</strong> ${escapeHtml(p.referencia || 'N/A')}
                            </div>` : '';
                            const correctionMsg = p.correccion_mensaje ? '<div style="margin-top: 10px; color: var(--danger); background: rgba(239, 68, 68, 0.08); padding: 10px; border-radius: 10px;"><strong>Mensaje del administrador:</strong> ' + escapeHtml(p.correccion_mensaje) + '</div>' : '';
                            
                            // Extraer prefijo y número para el formulario
                            const telClean = String(p.telefono_origen || '').replace(/[^0-9]/g, '');
                            let prefijoSel = '0412';
                            let numeroVal = telClean;

                            if (telClean.length >= 11) {
                                prefijoSel = telClean.substring(0, 4);
                                numeroVal = telClean.substring(4);
                            }

                            const showUploadBox = (!p.es_apartado) && ((!p.pago_estado && (p.estado === 'Pendiente Pago' || p.estado === 'Pendiente')) || (p.pago_estado === 'rechazado' || p.estado === 'Corrección solicitada'));
                            const isCorrection = (p.pago_estado === 'rechazado' || p.estado === 'Corrección solicitada');
                            
                            const fileField = isCorrection ? `
                                <div style="margin-bottom: 8px; margin-top: 10px;">
                                    <label style="font-size: 0.8rem; color: var(--text-light); display: block; margin-bottom: 4px; font-weight: 600;">📷 Adjuntar Comprobante de Pago (Imagen o PDF):</label>
                                    <input type="file" id="file_${p.id}" accept=".pdf,.jpg,.jpeg,.png,.webp" style="width:100%; padding:8px; border-radius:8px; border:1px solid #ccc; background:#fff;">
                                </div>
                            ` : '';

                            const bancos = ['0102 - Banco de Venezuela','0104 - Banco Venezolano de Crédito','0105 - Mercantil','0108 - Provincial','0114 - Bancaribe','0115 - Banco Exterior','0128 - Banco Caroní','0134 - Banesco','0137 - Banco Sofitasa','0138 - Banco Plaza','0151 - BFC Banco Fondo Común','0156 - 100% Banco','0157 - Bancosur','0163 - Banco del Tesoro','0166 - Banco Agrícola','0168 - Bancrecer','0169 - Mi Banco','0171 - Banco Activo','0172 - Bancamiga','0174 - Banplus','0175 - Banco Bicentenario','0177 - Banfanb','0191 - BNC','Otro'];
                            const bancoOrigen = p.banco_origen || '';
                            const optionsHtml = bancos.map(b => `<option value="${b}" ${bancoOrigen === b ? 'selected' : ''}>${b}</option>`).join('');
                            
                            const uploadBox = showUploadBox ? `
                                <div class="upload-box">
                                    <strong>${p.banco_origen ? '✏️ Actualizar información de pago' : '💳 Enviar información de pago'}</strong>
                                    <p style="font-size: 0.85rem; margin-bottom: 10px;">Ingresa tus datos de pago para que el administrador pueda verificar la transacción.</p>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px;">
                                        <select id="banco_${p.id}" style="padding: 8px; border-radius: 8px; border: 1px solid #ccc; width: 100%; box-sizing: border-box; background: #fff;">
                                            <option value="">Seleccione Banco...</option>
                                            ${optionsHtml}
                                        </select>
                                        <div style="display:flex; align-items:center; background:#fff; border:1px solid #ccc; border-radius:8px; overflow:hidden;">
                                            <select id="prefijo_telefono_${p.id}" style="border:none; padding:8px; background:transparent; font-size:14px; outline:none; border-right:1px solid #ccc; cursor:pointer;">
                                                <option value="0412" ${prefijoSel === '0412' ? 'selected' : ''}>0412</option>
                                                <option value="0422" ${prefijoSel === '0422' ? 'selected' : ''}>0422</option>
                                                <option value="0414" ${prefijoSel === '0414' ? 'selected' : ''}>0414</option>
                                                <option value="0424" ${prefijoSel === '0424' ? 'selected' : ''}>0424</option>
                                                <option value="0416" ${prefijoSel === '0416' ? 'selected' : ''}>0416</option>
                                                <option value="0426" ${prefijoSel === '0426' ? 'selected' : ''}>0426</option>
                                            </select>
                                            <input type="text" id="telefono_${p.id}" value="${escapeHtml(numeroVal)}" placeholder="1234567" style="border:none; flex:1; background:transparent; border-radius:0; padding:8px;" inputmode="numeric" maxlength="7">
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 8px;">
                                        <input type="text" id="referencia_${p.id}" value="${escapeHtml(p.referencia || '')}" placeholder="Número de Referencia" style="padding: 8px; border-radius: 8px; border: 1px solid #ccc; width: 100%; box-sizing: border-box;" maxlength="22">
                                        <small style="color: var(--text-light); font-size: 0.78rem; display: block; margin-top: 4px;">Debe tener entre 6 y 22 dígitos</small>
                                    </div>
                                    ${fileField}
                                    <button type="button" onclick="enviarComprobanteArchivo(${p.id}, ${isCorrection ? `'file_${p.id}'` : 'null'}, 'banco_${p.id}', 'telefono_${p.id}', 'referencia_${p.id}')" style="margin-top: 10px;">Enviar datos al administrador</button>
                                </div>` : '';

                            const esValido = ['Pago Validado', 'Preparando', 'Entregado'].includes(p.estado) || p.pago_estado === 'aprobado' || p.pago_estado === 'validado';
                            const reciboBtn = esValido ? `
                                <div style="margin-top: 15px;">
                                    <button type="button" onclick="generarReciboCliente(${p.id})" style="background: #16a34a; color: white; border: none; padding: 10px 16px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;">
                                        📥 Descargar Recibo Oficial (PDF)
                                    </button>
                                </div>
                            ` : '';

                            return `
                            <div class="order-card">
                                <div class="order-meta">
                                    <span class="order-id">Orden #${p.id}</span>
                                    <span class="order-date">${new Date(p.fecha_venta).toLocaleString()}</span>
                                </div>
                                ${badgeHtml}
                                <div class="order-products">
                                    <strong>Productos:</strong><br>
                                    ${escapeHtml(p.productos || 'Sin detalle')}
                                </div>
                                ${pagoEstadoHtml}
                                ${apartadoBloqueHtml}
                                ${pagoInfo}
                                ${correctionMsg}
                                ${uploadBox}
                                ${reciboBtn}
                            </div>
                            `;
                        }).join('');
                    } else {
                        container.innerHTML = '<p>Error al cargar pedidos.</p>';
                    }
                });
        });

        function generarReciboCliente(pedidoId) {
            const p = window.pedidosMap ? window.pedidosMap[pedidoId] : null;
            if (!p) {
                alert('No se pudo encontrar la información de la orden.');
                return;
            }
            if (!window.jspdf) {
                alert('La librería de generación de PDF aún está cargando. Reintenta en unos segundos.');
                return;
            }

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();

            const primaryColor = [37, 99, 235];    // #2563eb
            const textColor = [30, 41, 59];        // #1e293b
            const lightTextColor = [100, 116, 139];// #64748b
            const greenColor = [22, 163, 74];      // #16a34a

            // Encabezado Superior
            doc.setFillColor(248, 250, 252);
            doc.rect(0, 0, 210, 40, 'F');

            doc.setFont("helvetica", "bold");
            doc.setFontSize(22);
            doc.setTextColor(...primaryColor);
            doc.text("<?php echo strtoupper($nombreTienda); ?>", 15, 22);

            doc.setFontSize(10);
            doc.setFont("helvetica", "normal");
            doc.setTextColor(...lightTextColor);
            doc.text("COMPROBANTE OFICIAL DE COMPRA", 15, 29);

            doc.setFont("helvetica", "bold");
            doc.setFontSize(14);
            doc.setTextColor(...textColor);
            doc.text(`ORDEN #${p.id}`, 195, 22, { align: "right" });

            doc.setFontSize(9);
            doc.setFont("helvetica", "normal");
            doc.setTextColor(...lightTextColor);
            const fechaStr = p.fecha_venta ? new Date(p.fecha_venta).toLocaleString('es-VE') : new Date().toLocaleString();
            doc.text(`Fecha: ${fechaStr}`, 195, 29, { align: "right" });

            doc.setDrawColor(226, 232, 240);
            doc.line(15, 40, 195, 40);

            // Información del Pedido y Transacción
            let y = 52;

            // Columna 1: Datos del Pedido
            doc.setFontSize(10);
            doc.setFont("helvetica", "bold");
            doc.setTextColor(...textColor);
            doc.text("Información del Pedido", 15, y);
            
            doc.setFontSize(9);
            doc.setFont("helvetica", "normal");
            doc.setTextColor(...lightTextColor);
            doc.text(`Estado de la Orden: ${p.estado || 'Procesado'}`, 15, y + 6);
            doc.text(`Estado del Pago: VALIDADO`, 15, y + 12);

            // Columna 2: Datos del Pago
            doc.setFontSize(10);
            doc.setFont("helvetica", "bold");
            doc.setTextColor(...textColor);
            doc.text("Datos del Pago", 115, y);

            doc.setFontSize(9);
            doc.setFont("helvetica", "normal");
            doc.setTextColor(...lightTextColor);
            doc.text(`Banco Origen: ${p.banco_origen || 'No especificado'}`, 115, y + 6);
            doc.text(`N° Referencia: ${p.referencia || 'N/A'}`, 115, y + 12);
            doc.text(`Teléfono Origen: ${formatTelForPdf(p.telefono_origen)}`, 115, y + 18);

            // Tabla de Productos
            y += 30;
            doc.setFillColor(241, 245, 249);
            doc.rect(15, y, 180, 8, 'F');

            doc.setFont("helvetica", "bold");
            doc.setFontSize(9);
            doc.setTextColor(...textColor);
            doc.text("DESCRIPCIÓN DEL PRODUCTO", 18, y + 5.5);
            doc.text("CANTIDAD", 150, y + 5.5, { align: "center" });

            y += 12;
            doc.setFont("helvetica", "normal");
            doc.setTextColor(...textColor);

            const prods = (p.productos || 'Sin detalle de productos').split(',');
            prods.forEach((item) => {
                const itemClean = item.trim();
                let cantidad = "1";
                let nombre = itemClean;

                const match = itemClean.match(/^(\d+)x\s*(.+)$/i);
                if (match) {
                    cantidad = match[1];
                    nombre = match[2];
                }

                if (nombre.length > 55) {
                    nombre = nombre.substring(0, 52) + '...';
                }

                doc.text(nombre, 18, y);
                doc.text(cantidad, 150, y, { align: "center" });

                y += 6;
                doc.setDrawColor(241, 245, 249);
                doc.line(15, y, 195, y);
                y += 4;

                if (y > 250) {
                    doc.addPage();
                    y = 20;
                }
            });

            // Resumen de Totales
            y += 5;
            doc.setFillColor(248, 250, 252);
            doc.roundedRect(110, y, 85, 28, 3, 3, 'F');
            doc.setDrawColor(226, 232, 240);
            doc.roundedRect(110, y, 85, 28, 3, 3, 'D');

            const totalUSD = parseFloat(p.total_usd || 0);
            const totalBs = parseFloat(p.total_bs || 0);

            doc.setFontSize(10);
            doc.setFont("helvetica", "bold");
            doc.setTextColor(...textColor);
            doc.text("TOTAL USD:", 115, y + 10);
            doc.text(`$${totalUSD.toFixed(2)} USD`, 190, y + 10, { align: "right" });

            doc.setFontSize(11);
            doc.setTextColor(...greenColor);
            doc.text("TOTAL BS:", 115, y + 20);
            doc.text(`Bs ${totalBs.toFixed(2)}`, 190, y + 20, { align: "right" });

            // Pie de página
            doc.setFontSize(8);
            doc.setFont("helvetica", "italic");
            doc.setTextColor(...lightTextColor);
            doc.text("Gracias por su compra en <?php echo $nombreTienda; ?>. Este recibo es un comprobante digital válido de su transacción.", 105, 285, { align: "center" });

            doc.save(`Recibo_<?php echo preg_replace('/[^a-zA-Z0-9]/', '', $nombreTienda); ?>_Orden_${p.id}.pdf`);
        }

        async function enviarComprobanteArchivo(ventaId, inputId, bancoId, telefonoId, referenciaId) {
            const input = document.getElementById(inputId);
            const bancoVal = bancoId && document.getElementById(bancoId) ? document.getElementById(bancoId).value.trim() : '';
            
            const prefijoId = telefonoId ? telefonoId.replace('telefono_', 'prefijo_telefono_') : '';
            const prefijoVal = prefijoId && document.getElementById(prefijoId) ? document.getElementById(prefijoId).value : '';
            let numeroVal = telefonoId && document.getElementById(telefonoId) ? document.getElementById(telefonoId).value.trim() : '';
            if (numeroVal && numeroVal.length < 7) {
                alert('El número de teléfono debe tener los 7 dígitos completos (ej: 1234567).');
                return;
            }
            let telefonoVal = numeroVal ? prefijoVal + numeroVal : '';
            
            const referenciaVal = referenciaId && document.getElementById(referenciaId) ? document.getElementById(referenciaId).value.trim() : '';

            if (referenciaVal && (referenciaVal.length < 6 || referenciaVal.length > 22)) {
                alert('El número de referencia debe tener entre 6 y 22 dígitos.');
                return;
            }

            const hasFile = input && input.files && input.files.length > 0;

            if (!bancoVal && !referenciaVal && !hasFile) {
                alert('Por favor ingresa la información de tu pago (Banco, Referencia o adjunta una captura).');
                return;
            }

            const formData = new FormData();
            formData.append('venta_id', ventaId);
            if (bancoVal) formData.append('banco_origen', bancoVal);
            if (telefonoVal) formData.append('telefono_origen', telefonoVal);
            if (referenciaVal) formData.append('referencia', referenciaVal);

            if (hasFile) {
                formData.append('comprobante', input.files[0]);
            }

            try {
                const response = await fetch('../api/subir_comprobante.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    alert('✅ Información de pago enviada. El administrador revisará tu pago.');
                    window.location.reload();
                } else {
                    alert('❌ Error: ' + result.message);
                }
            } catch (error) {
                console.error(error);
                alert('❌ Error al enviar la información de pago. Intenta nuevamente.');
            }
        }

        // backward-compatible wrapper
        function enviarCapturaCorreccion(ventaId) {
            enviarComprobanteArchivo(ventaId, `correctionFile_${ventaId}`);
        }

        let apartadoActivoData = null;

        function abrirModalReportarAbonoCliente(apartadoId, totalUsd, abonadoUsd, pendienteUsd, tasaCambio, cuotaSugerida) {
            apartadoActivoData = {
                id: apartadoId,
                totalUsd: parseFloat(totalUsd || 0),
                abonadoUsd: parseFloat(abonadoUsd || 0),
                pendienteUsd: parseFloat(pendienteUsd || 0),
                tasaCambio: parseFloat(tasaCambio || 50)
            };

            document.getElementById('modalApId').value = apartadoId;
            document.getElementById('modalApTotalUsd').textContent = `$${apartadoActivoData.totalUsd.toFixed(2)} USD`;
            document.getElementById('modalApPendienteUsd').textContent = `$${apartadoActivoData.pendienteUsd.toFixed(2)} USD`;
            document.getElementById('modalApTasa').textContent = `Bs ${apartadoActivoData.tasaCambio.toLocaleString('es-VE', {minimumFractionDigits: 2})} / USD`;

            const inputUsd = document.getElementById('inputAbonoUsd');
            const sugerida = Math.min(parseFloat(cuotaSugerida || 0), apartadoActivoData.pendienteUsd);
            inputUsd.value = sugerida > 0 ? sugerida.toFixed(2) : '';
            inputUsd.max = apartadoActivoData.pendienteUsd;
            inputUsd.placeholder = `Mínimo $0.50, Máx $${apartadoActivoData.pendienteUsd.toFixed(2)}`;

            document.getElementById('inputAbonoRef').value = '';
            document.getElementById('inputAbonoNotas').value = '';
            if (document.getElementById('inputAbonoFile')) document.getElementById('inputAbonoFile').value = '';
            document.getElementById('modalApEquivBs').textContent = 'Equivalente en Bs: Bs 0.00';

            const modal = document.getElementById('modalReportarAbonoCliente');
            if (modal) modal.style.display = 'flex';
        }

        function cerrarModalReportarAbonoCliente() {
            const modal = document.getElementById('modalReportarAbonoCliente');
            if (modal) modal.style.display = 'none';
        }

        function actualizarConversionBsAbono() {
            if (!apartadoActivoData) return;
            const usdVal = parseFloat(document.getElementById('inputAbonoUsd').value || 0);
            const equivBs = usdVal * apartadoActivoData.tasaCambio;
            document.getElementById('modalApEquivBs').textContent = `Equivalente en Bs: Bs ${equivBs.toLocaleString('es-VE', {minimumFractionDigits: 2})}`;
        }

        async function enviarAbonoCliente(e) {
            e.preventDefault();
            if (!apartadoActivoData) return;

            const montoUsd = parseFloat(document.getElementById('inputAbonoUsd').value || 0);
            if (montoUsd <= 0) {
                alert('Ingresa un monto válido a abonar.');
                return;
            }

            if (montoUsd > (apartadoActivoData.pendienteUsd + 0.01)) {
                alert(`El monto ingresado ($${montoUsd.toFixed(2)}) supera el saldo pendiente de tu apartado ($${apartadoActivoData.pendienteUsd.toFixed(2)}).`);
                return;
            }

            const referencia = document.getElementById('inputAbonoRef').value.trim();
            if (!referencia) {
                alert('Por favor ingresa el número de referencia del pago.');
                return;
            }

            const btn = document.getElementById('btnEnviarAbonoCliente');
            btn.disabled = true;
            btn.textContent = 'Enviando abono...';

            const formData = new FormData(document.getElementById('formAbonoCliente'));

            try {
                const res = await fetch('../api/save_abono_cliente.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    alert(data.message || '✅ ¡Abono registrado con éxito!');
                    cerrarModalReportarAbonoCliente();
                    window.location.reload();
                } else {
                    alert('❌ ' + (data.error || 'No se pudo registrar el abono.'));
                }
            } catch (err) {
                console.error(err);
                alert('❌ Error al conectar con el servidor.');
            } finally {
                btn.disabled = false;
                btn.textContent = '📤 Registrar Abono';
            }
        }
    </script>
    <script src="../assets/js/validaciones.js?v=1.0"></script>
    <script src="../assets/js/manual.js?v=1.0"></script>
</body>
</html>
