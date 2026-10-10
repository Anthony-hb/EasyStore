// ==================== MÓDULO DE DEVOLUCIONES A PROVEEDORES ====================

let devolucionProvData = [];
let devolucionProvItemsCache = [];
let devolucionProvTotalCache = 0;

function abrirModalRegistrarDevolucionProveedor() {
    document.getElementById('devolucionProvBuscarCompra').value = '';
    document.getElementById('devolucionProvCompraId').innerHTML = '<option value="">-- Selecciona una compra pendiente de devolución --</option>';
    document.getElementById('devolucionProvListaCompras').style.display = 'none';
    document.getElementById('devolucionProvListaCompras').innerHTML = '';
    document.getElementById('devolucionProvDetalleBox').style.display = 'none';
    document.getElementById('devolucionProvCamposPago').style.display = 'none';
    document.getElementById('devolucionProvMotivo').value = '';
    document.getElementById('devolucionProvNotas').value = '';
    document.getElementById('devolucionProvMetodo').value = 'nota_credito';
    document.getElementById('devolucionProvTotalCalculado').innerText = '$0.00 USD  (selecciona las cantidades)';
    devolucionProvItemsCache = [];
    devolucionProvTotalCache = 0;

    const modal = document.getElementById('modalRegistrarDevolucionProveedor');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('devolucionProvBuscarCompra').focus();
    }
}

function cerrarModalRegistrarDevolucionProveedor() {
    const modal = document.getElementById('modalRegistrarDevolucionProveedor');
    if (modal) modal.style.display = 'none';
}

async function buscarComprasDevolubles() {
    const buscar = document.getElementById('devolucionProvBuscarCompra').value.trim();
    const listaBox = document.getElementById('devolucionProvListaCompras');
    const select = document.getElementById('devolucionProvCompraId');

    listaBox.style.display = 'block';
    listaBox.innerHTML = '<div style="padding:12px; color:#888; font-size:0.85rem;">⌛ Buscando compras pendientes...</div>';
    select.innerHTML = '<option value="">-- Selecciona una compra pendiente de devolución --</option>';

    try {
        const res = await fetch(`get_compras_devolubles.php${buscar ? '?buscar=' + encodeURIComponent(buscar) : ''}`);
        const data = await res.json();

        if (!data.success) {
            listaBox.innerHTML = `<div style="padding:12px; color:#c53030; font-size:0.85rem;">${escaparHTML(data.error || 'Error')}</div>`;
            return;
        }

        devolucionProvData = data.compras || [];

        if (devolucionProvData.length === 0) {
            listaBox.innerHTML = `<div style="padding:12px; color:#888; font-size:0.85rem;">
                No hay compras con mercancía por devolver${buscar ? ` para "<strong>${escaparHTML(buscar)}</strong>"` : ''}.
            </div>`;
            return;
        }

        listaBox.innerHTML = devolucionProvData.map(c => `
            <label style="display:flex; gap:10px; align-items:center; padding:9px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer; font-size:0.83rem;"
                   onmouseover="this.style.background='#faf5ff'"
                   onmouseout="this.style.background='transparent'">
                <input type="radio" name="compraDevolucionElegida" value="${c.id}" style="cursor:pointer;"
                       onchange="seleccionarCompraDevolucion()">
                <div style="flex:1; min-width:0;">
                    <div>
                        <strong>Compra #${c.id}</strong>
                        ${c.numero_factura ? `<span style="color:#666;"> — Factura: ${escaparHTML(c.numero_factura)}</span>` : ''}
                        <span style="color:#888;"> (${c.fecha_compra ? c.fecha_compra.substring(0,10) : ''})</span>
                    </div>
                    <div style="color:#666; font-size:0.78rem;">
                        Proveedor: ${escaparHTML(c.proveedor_nombre || 'Sin proveedor')} ·
                        Pendiente: <strong>${c.pendiente_devolver} unids</strong> · ${fmtUSD(c.total_usd)}
                    </div>
                </div>
            </label>`).join('');

        if (select) {
            select.innerHTML = '<option value="">-- Selecciona una compra pendiente de devolución --</option>' +
                devolucionProvData.map(c =>
                    `<option value="${c.id}">Compra #${c.id} — ${escaparHTML(c.proveedor_nombre || '')} (${c.pendiente_devolver} pend.)</option>`
                ).join('');
        }
    } catch (err) {
        console.error("Error al buscar compras devolubles:", err);
        listaBox.innerHTML = '<div style="padding:12px; color:#c53030; font-size:0.85rem;">No se pudo conectar con el servidor.</div>';
    }
}

async function seleccionarCompraDevolucion() {
    const radio  = document.querySelector('input[name="compraDevolucionElegida"]:checked');
    const select = document.getElementById('devolucionProvCompraId');
    const compraId = radio ? parseInt(radio.value) : parseInt(select?.value || 0);

    if (!compraId) return;

    const detalleBox = document.getElementById('devolucionProvDetalleBox');
    const camposPago  = document.getElementById('devolucionProvCamposPago');
    const resumen     = document.getElementById('devolucionProvCompraResumen');
    const itemsBody   = document.getElementById('devolucionProvItemsBody');
    const todoDevuelto= document.getElementById('devolucionProvTodoDevuelto');

    detalleBox.style.display = 'block';
    resumen.innerHTML = '<div style="color:#888;">⌛ Cargando productos de la compra...</div>';
    itemsBody.innerHTML = '';
    camposPago.style.display = 'none';
    todoDevuelto.style.display = 'none';

    try {
        const response = await fetch(`get_items_compra_devolucion.php?compra_id=${compraId}`);
        const data = await response.json();

        if (!data.success) {
            resumen.innerHTML = `<div style="color:#c53030; font-weight:600;">${escaparHTML(data.error || 'No se pudo cargar la compra.')}</div>`;
            devolucionProvItemsCache = [];
            actualizarResumenDevolucionProveedor();
            return;
        }

        const c = data.compra;
        devolucionProvItemsCache = data.items || [];

        resumen.innerHTML = `
            <div><strong>Compra #${c.id}</strong> — Proveedor: ${escaparHTML(c.proveedor_nombre || 'Sin proveedor')}</div>
            <div>Fecha: ${c.fecha_compra ? new Date(c.fecha_compra).toLocaleString('es-VE', {dateStyle: 'short', timeStyle: 'short'}) : '-'}</div>
            <div>Factura: ${escaparHTML(c.numero_factura || '-')} | Total: <strong>${fmtUSD(c.total_usd)}</strong></div>`;

        if (!devolucionProvItemsCache.length) {
            itemsBody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px; color:#888;">Esta compra no tiene productos pendientes de devolver.</td></tr>';
            actualizarResumenDevolucionProveedor();
            return;
        }

        itemsBody.innerHTML = devolucionProvItemsCache.map(it => {
            const agotado = it.pendiente === 0;
            return `<tr style="${agotado ? 'opacity:0.5;' : ''}">
                <td>${escaparHTML(it.nombre_producto)} ${it.producto_id === null ? '<div style="font-size:0.72rem; color:#b45309;">⚠️ Sin vínculo a producto</div>' : ''}</td>
                <td style="text-align:center;">${it.comprado}</td>
                <td style="text-align:center;">${it.devuelto_activo}</td>
                <td style="text-align:center;"><strong>${it.pendiente}</strong></td>
                <td style="text-align:center;">${fmtUSD(it.precio_unitario_usd)}</td>
                <td style="text-align:center;">
                    ${agotado
                        ? '<span style="font-size:0.75rem; color:#94a3b8;">Todo devuelto</span>'
                        : `<input type="number" min="0" max="${it.pendiente}" value="0"
                                   oninput="calcularTotalDevolucionProveedor()"
                                   style="width:70px; padding:6px; border:1px solid #d1d5db; border-radius:6px; font-weight:700; text-align:center;">`}
                </td>
                <td style="text-align:right;" data-subtotal-prov="${it.compra_detalle_id}">${fmtUSD(0)}</td>
            </tr>`;
        }).join('');

        const hayPendiente = devolucionProvItemsCache.some(it => it.pendiente > 0);
        todoDevuelto.style.display = hayPendiente ? 'none' : 'block';
        camposPago.style.display = hayPendiente ? 'flex' : 'none';

        actualizarResumenDevolucionProveedor();
    } catch (err) {
        console.error("Error al cargar items de compra:", err);
        resumen.innerHTML = '<div style="color:#c53030; font-weight:600;">No se pudo conectar con el servidor.</div>';
        devolucionProvItemsCache = [];
    }
}

function calcularTotalDevolucionProveedor() {
    const inputs = document.querySelectorAll('#devolucionProvItemsBody input[type="number"]');
    let total = 0;

    inputs.forEach(inp => {
        const fila = inp.closest('tr');
        const celdaSubtotal = fila?.querySelector('[data-subtotal-prov]');
        const item = devolucionProvItemsCache.find(it => celdaSubtotal && String(it.compra_detalle_id) === celdaSubtotal.getAttribute('data-subtotal-prov'));
        if (!item) return;

        let cant = parseInt(inp.value || 0);
        if (isNaN(cant) || cant < 0) cant = 0;
        if (cant > item.pendiente) {
            cant = item.pendiente;
            inp.value = cant;
        }

        const subtotal = Math.round(item.precio_unitario_usd * cant * 100) / 100;
        total += subtotal;
        if (celdaSubtotal) celdaSubtotal.innerText = subtotal > 0 ? fmtUSD(subtotal) : '—';
    });

    devolucionProvTotalCache = Math.round(total * 100) / 100;
    actualizarResumenDevolucionProveedor();
}

function actualizarResumenDevolucionProveedor() {
    const elTotal = document.getElementById('devolucionProvTotalCalculado');
    const total = parseFloat(devolucionProvTotalCache || 0);
    if (elTotal) {
        elTotal.innerText = total > 0 ? `${fmtUSD(total)} USD` : '$0.00 USD  (selecciona las cantidades)';
    }
}

async function guardarDevolucionProveedor() {
    const radio  = document.querySelector('input[name="compraDevolucionElegida"]:checked');
    const select = document.getElementById('devolucionProvCompraId');
    const compraId = radio ? parseInt(radio.value) : parseInt(select?.value || 0);

    const metodo = document.getElementById('devolucionProvMetodo')?.value || '';
    const motivo = document.getElementById('devolucionProvMotivo')?.value?.trim();
    const notas  = document.getElementById('devolucionProvNotas')?.value?.trim();

    if (!compraId) {
        alert('Selecciona la compra a devolver.');
        return;
    }
    if (!motivo) {
        alert('Indica el motivo de la devolución al proveedor.');
        return;
    }

    const detalles = [];
    document.querySelectorAll('#devolucionProvItemsBody input[type="number"]').forEach(inp => {
        const fila = inp.closest('tr');
        const celda = fila?.querySelector('[data-subtotal-prov]');
        if (!celda) return;
        const item = devolucionProvItemsCache.find(it => String(it.compra_detalle_id) === celda.getAttribute('data-subtotal-prov'));
        if (!item) return;
        const cant = parseInt(inp.value || 0);
        if (cant > 0) {
            detalles.push({ compra_detalle_id: item.compra_detalle_id, cantidad: cant });
        }
    });

    if (detalles.length === 0) {
        alert('Indica al menos una cantidad a devolver al proveedor.');
        return;
    }

    const total = parseFloat(devolucionProvTotalCache || 0);
    const detalleTexto = detalles.map(d => {
        const item = devolucionProvItemsCache.find(it => it.compra_detalle_id === d.compra_detalle_id);
        return `  - ${d.cantidad} und. de "${item ? item.nombre_producto : '?'}"`;
    }).join('\n');

    const confirmacion =
        `Registrar devolución al proveedor de la compra #${compraId}:\n\n${detalleTexto}\n\n` +
        `Total a devolver: ${fmtUSD(total)}\n` +
        `Método: ${metodo}\n\n` +
        `Se restará el stock de estos productos. ¿Confirmas?`;

    if (!confirm(confirmacion)) return;

    const btn = document.getElementById('devolucionProvBtnGuardar');
    const textoOriginal = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Registrando...'; }

    try {
        const response = await fetch('save_devolucion_proveedor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                compra_id: compraId,
                metodo_reembolso: metodo,
                motivo: motivo,
                notas: notas,
                detalles: detalles
            })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Devolución al proveedor registrada.');
            cerrarModalRegistrarDevolucionProveedor();
            cargarDevolucionesProveedor();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();
            (resData.advertencias || []).forEach(a => alert(`⚠️ ${a}`));
        } else {
            alert(`No se pudo registrar la devolución: ${resData.error || 'Ocurrió un error inesperado.'}`);
        }
    } catch (err) {
        console.error("Error al registrar devolución a proveedor:", err);
        alert("No se pudo procesar la respuesta del servidor.");
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = textoOriginal; }
    }
}

function cargarDevolucionesProveedor() {
    fetch('get_devoluciones_proveedor.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                mostrarNotificacion(data.error || 'Error al cargar devoluciones a proveedores', true);
                return;
            }

            devolucionProvData = data.devoluciones || [];

            const tbody = document.getElementById('tablaDevolucionesProveedorBody');
            const stats = data.stats || {};

            if (document.getElementById('devProvStatTotalUSD')) document.getElementById('devProvStatTotalUSD').innerText = '$' + parseFloat(stats.monto_activo_usd || 0).toFixed(2);
            if (document.getElementById('devProvStatEfectivoUSD')) document.getElementById('devProvStatEfectivoUSD').innerText = '$' + parseFloat(stats.monto_efectivo_usd || 0).toFixed(2);
            if (document.getElementById('devProvStatNCUSD')) document.getElementById('devProvStatNCUSD').innerText = '$' + parseFloat(stats.monto_nota_credito_usd || 0).toFixed(2);
            if (document.getElementById('devProvStatActivas')) document.getElementById('devProvStatActivas').innerText = stats.activas || 0;

            if (!tbody) return;

            if (devolucionProvData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:30px; color:#999;">No hay devoluciones registradas</td></tr>';
                return;
            }

            tbody.innerHTML = devolucionProvData.map(d => {
                const esAnulada = d.estado === 'ANULADA';
                const metodoLabel = {
                    'efectivo': 'Efectivo',
                    'pago_movil': 'Pago Móvil',
                    'transferencia': 'Transferencia',
                    'nota_credito': 'Nota de Crédito',
                    'otros': 'Otros'
                }[d.metodo_reembolso] || d.metodo_reembolso;

                return `<tr style="${esAnulada ? 'opacity:0.65;' : ''}">
                    <td>${d.fecha_devolucion || '-'}</td>
                    <td>#${d.compra_id}</td>
                    <td>${escaparHTML(d.proveedor_nombre || '-')}</td>
                    <td style="max-width:220px; white-space:normal; font-size:0.8rem;">${escaparHTML(d.productos || '-')}</td>
                    <td><span style="padding:4px 8px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#e0f2fe; color:#0369a1;">${d.tipo}</span></td>
                    <td style="font-weight:700; color:${esAnulada ? '#9ca3af' : '#7e22ce'};">$${parseFloat(d.monto_devuelto_usd || 0).toFixed(2)}</td>
                    <td style="font-size:0.82rem;">${metodoLabel}</td>
                    <td style="max-width:200px; white-space:normal; font-size:0.78rem;">${escaparHTML(d.motivo || '-')}</td>
                    <td style="font-size:0.82rem;">${d.usuario_admin_id || '-'}</td>
                    <td style="text-align:center;">
                        ${esAnulada
                            ? `<span style="padding:5px 10px; border-radius:6px; font-size:0.75rem; background:#f1f5f9; color:#475569;">Anulada</span>`
                            : `<button class="btn-secondary" style="background:#b45309; color:white; border:none; padding:6px 10px; font-size:0.75rem; border-radius:6px; font-weight:700;" onclick="abrirModalAnularDevolucionProveedor(${d.id}, '${escaparHTML(d.compra_id + ' - ' + (d.proveedor_nombre || ''))}', '${escaparHTML(d.productos || '')}', '${parseFloat(d.monto_devuelto_usd || 0).toFixed(2)}')">Anular</button>`
                        }
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(err => {
            console.error(err);
            mostrarNotificacion('Error al cargar devoluciones a proveedores', true);
        });
}

let devolucionProvAnularId = null;

function abrirModalAnularDevolucionProveedor(id, resumenCompra, productos, monto) {
    devolucionProvAnularId = id;
    const modal = document.getElementById('modalAnularDevolucionProveedor');
    const resumen = document.getElementById('anularDevolucionProvResumen');
    document.getElementById('anularDevolucionProvMotivo').value = '';

    resumen.innerHTML = `
        <div><strong>Devolución a Proveedor #${id}</strong></div>
        <div>Compra: ${resumenCompra}</div>
        <div>Productos: ${productos}</div>
        <div>Monto devuelto: <strong>$${monto} USD</strong></div>`;

    if (modal) modal.style.display = 'flex';
}

function cerrarModalAnularDevolucionProveedor() {
    const modal = document.getElementById('modalAnularDevolucionProveedor');
    if (modal) modal.style.display = 'none';
    devolucionProvAnularId = null;
}

async function confirmarAnulacionDevolucionProveedor(e) {
    e.preventDefault();
    if (!devolucionProvAnularId) return;

    const motivo = document.getElementById('anularDevolucionProvMotivo')?.value?.trim();
    if (!motivo) {
        alert('Debes indicar el motivo de la anulación.');
        return;
    }

    if (!confirm('¿Confirmas anular esta devolución al proveedor? Se reingresará la mercancía al inventario.')) return;

    const btn = e.target.querySelector('button[type="submit"]');
    const textoOriginal = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Anulando...'; }

    try {
        const response = await fetch('anular_devolucion_proveedor.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                devolucion_proveedor_id: devolucionProvAnularId,
                motivo: motivo
            })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Devolución al proveedor anulada.');
            cerrarModalAnularDevolucionProveedor();
            cargarDevolucionesProveedor();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();
        } else {
            alert(`No se pudo anular: ${resData.error || 'Error inesperado.'}`);
        }
    } catch (err) {
        console.error(err);
        alert('Error al procesar la anulación.');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = textoOriginal; }
    }
}