/**
 * ============================================================
 *  MODULO UNIFICADO: HISTORIAL DE CLIENTES
 * ============================================================
 *  Reúne en una sola pantalla las Compras, los Apartados y las
 *  Devoluciones de cada cliente.
 *
 *  Pantalla 1 (indice): lista de clientes con un resumen de su
 *  actividad y un buscador.
 *  Pantalla 2 (detalle): todo el historial del cliente elegido,
 *  con filtros por tipo de movimiento, por periodo y una busqueda
 *  de texto libre.
 *
 *  A diferencia de los modulos sueltos, aqui no se filtra por
 *  estado de venta: se ve todo, incluidas las ventas que aun no
 *  se han pagado.
 * ============================================================
 */

let historialClientesData = [];
let historialDetalle = null;
let historialClienteActual = null;

/** Lo que se esta viendo en pantalla tras aplicar los filtros. Lo usan los reportes. */
let historialVisto = null;

/** Ultima vez que se abrio un cliente, para refrescar sin perder el scroll. */
let historialClienteCargando = false;

// ==================== PANTALLA 1: INDICE DE CLIENTES ====================

function cargarIndiceHistorial() {
    const tbody = document.getElementById('historialIndiceBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:30px; color:#999;">Cargando clientes...</td></tr>';

    fetch('get_clientes_historial.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:30px; color:#b91c1c;">${escaparHTML(data.error || 'No se pudieron cargar los clientes.')}</td></tr>`;
                return;
            }
            historialClientesData = data.clientes || [];
            renderizarIndiceHistorial();
        })
        .catch(err => {
            console.error('Error al cargar el indice de historial:', err);
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:30px; color:#b91c1c;">Error de conexión al cargar los clientes.</td></tr>';
        });
}

function renderizarIndiceHistorial() {
    const tbody = document.getElementById('historialIndiceBody');
    if (!tbody) return;

    if (historialClientesData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:35px; color:#888;">Todavía no hay clientes registrados.</td></tr>';
        return;
    }

    tbody.innerHTML = historialClientesData.map(c => {
        const nombre = escaparHTML(c.nombre_completo || c.usuario || 'Sin nombre');
        const tieneCompras = c.ventas_pagadas > 0;
        const tieneApartados = c.apartados_activos > 0;
        const tieneDevoluciones = c.devoluciones_activas > 0;

        return `
        <tr>
            <td><strong>${nombre}</strong></td>
            <td style="font-size:0.82rem; color:#555;">${escaparHTML(c.usuario || '-')}</td>
            <td style="font-size:0.82rem;">${escaparHTML(c.cedula || '-')}</td>
            <td style="font-size:0.78rem; color:#666;">
                ${escaparHTML(c.telefono || '-')}
                ${c.email ? `<div style="color:#888;">${escaparHTML(c.email)}</div>` : ''}
            </td>
            <td style="text-align:center;">
                <strong style="font-size:1rem;">${c.total_ventas}</strong>
                <div style="font-size:0.72rem; color:#888;">${tieneCompras ? c.ventas_pagadas + ' pagadas' : 'sin pagar'}</div>
            </td>
            <td style="white-space:nowrap;">
                <strong style="color:#1d4ed8;">${fmtUSD(c.compras_usd)}</strong>
                ${c.ganancia_usd > 0 ? `<div style="font-size:0.72rem; color:#16a34a;">+${fmtUSD(c.ganancia_usd)} gan.</div>` : ''}
            </td>
            <td style="text-align:center;">
                ${tieneApartados
                    ? `<span style="display:inline-block; padding:3px 9px; border-radius:12px; background:#fef3c7; color:#b45309; font-weight:700; font-size:0.78rem;">${c.apartados_activos}</span>
                       <div style="font-size:0.72rem; color:#b91c1c; font-weight:700;">${fmtUSD(c.saldo_pendiente)}</div>`
                    : '<span style="color:#bbb;">—</span>'}
            </td>
            <td style="text-align:center;">
                ${tieneDevoluciones
                    ? `<span style="display:inline-block; padding:3px 9px; border-radius:12px; background:#f3e8ff; color:#7e22ce; font-weight:700; font-size:0.78rem;">${c.devoluciones_activas}</span>
                       <div style="font-size:0.72rem; color:#7e22ce;">-${fmtUSD(c.reembolsado_usd)}</div>`
                    : '<span style="color:#bbb;">—</span>'}
            </td>
            <td style="font-size:0.78rem; color:#666; white-space:nowrap;">${c.ultima_venta ? escaparHTML(String(c.ultima_venta).substring(0, 10)) : 'Nunca'}</td>
            <td style="text-align:center;">
                <button type="button" onclick="abrirHistorialCliente(${c.id})"
                        style="padding:6px 14px; background:#4f46e5; color:#fff; border:none; border-radius:7px; font-weight:700; font-size:0.8rem; cursor:pointer;">
                    Ver Historial →
                </button>
            </td>
        </tr>`;
    }).join('');

    filtrarIndiceHistorial();
}

function filtrarIndiceHistorial() {
    const texto = ((document.getElementById('historialBuscarCliente')?.value) || '').toLowerCase().trim();
    const actividad = (document.getElementById('historialFiltroActividad')?.value) || '';
    const pasaRangoFecha = _creadorFiltroRangoHistorial('historialIndice');

    const tbody = document.getElementById('historialIndiceBody');
    if (!tbody) return;

    tbody.querySelectorAll('tr').forEach((row, i) => {
        if (row.cells.length === 1 && row.cells[0].getAttribute('colspan')) return;

        const c = historialClientesData[i];
        if (!c) return;

        let coincide = true;

        if (texto) {
            const textoFila = [
                c.nombre_completo, c.usuario, c.cedula, c.email, c.telefono,
                c.total_ventas, c.ventas_pagadas, c.compras_usd,
                c.apartados_activos, c.saldo_pendiente,
                c.devoluciones_activas, c.reembolsado_usd
            ].join(' ').toLowerCase();
            if (!textoFila.includes(texto)) coincide = false;
        }

        if (coincide) {
            if (actividad === 'con_compras' && c.ventas_pagadas === 0) coincide = false;
            if (actividad === 'con_apartados' && c.apartados_activos === 0) coincide = false;
            if (actividad === 'con_devoluciones' && c.devoluciones_activas === 0) coincide = false;
            if (actividad === 'sin_movimiento' && (c.total_ventas + c.apartados_activos + c.devoluciones_activas) > 0) coincide = false;
        }

        // Filtro de fecha/hora sobre la última actividad del cliente.
        if (coincide && !pasaRangoFecha(c.ultima_venta)) coincide = false;

        row.dataset.matchFilter = coincide ? 'true' : 'false';
    });

    if (estadoPaginacion['historialIndiceBody']) {
        estadoPaginacion['historialIndiceBody'].paginaActual = 1;
    }
    actualizarPaginacionTabla('historialIndiceBody');
}

/** Vacia el rango de fecha/hora (y el resto de filtros) del índice y repinta. */
function limpiarFiltrosHistorialIndice() {
    ['historialIndiceFechaDesde', 'historialIndiceFechaHasta', 'historialIndiceHoraDesde', 'historialIndiceHoraHasta',
     'historialBuscarCliente', 'historialFiltroActividad'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    filtrarIndiceHistorial();
}

/**
 * Devuelve una funcion que valida si una fecha de registro pasa el rango de
 * fecha/hora elegido en la barra del filtro indicado ("historialIndice" o
 * "historialDetalle"). Usa los mismos límites que el filtro genérico de las
 * tablas (admin.js): si solo se elige una hora, se compara la hora del día.
 * @param {string} prefijo ids: <prefijo>FechaDesde | FechaHasta | HoraDesde | HoraHasta
 */
function _creadorFiltroRangoHistorial(prefijo) {
    const valor = tipo => {
        const el = document.getElementById(prefijo + tipo);
        return el ? String(el.value || '').trim() : '';
    };

    const desde = valor('FechaDesde');
    const hasta = valor('FechaHasta');
    const horaDesde = valor('HoraDesde');
    const horaHasta = valor('HoraHasta');

    if (!desde && !hasta && !horaDesde && !horaHasta) return () => true;

    const limiteDesde = _limiteFechaTabla(desde, horaDesde, true);
    const limiteHasta = _limiteFechaTabla(hasta, horaHasta, false);
    const horaSoloDesde = (!desde && horaDesde) ? horaDesde : '';
    const horaSoloHasta = (!hasta && horaHasta) ? horaHasta : '';

    return fecha => {
        const f = _parsearFechaTabla(fecha);
        if (!f) return false; // sin fecha registrada no pasa un filtro de fecha
        if (limiteDesde && f < limiteDesde) return false;
        if (limiteHasta && f > limiteHasta) return false;
        if (horaSoloDesde && _horaDiaTabla(f) < horaSoloDesde) return false;
        if (horaSoloHasta && _horaDiaTabla(f) > horaSoloHasta) return false;
        return true;
    };
}

// ==================== PANTALLA 2: DETALLE DEL CLIENTE ====================

function abrirHistorialCliente(clienteId) {
    if (historialClienteCargando) return;
    historialClienteCargando = true;

    // Ficha provisional mientras llegan los datos
    const ficha = document.getElementById('historialFichaCliente');

    if (ficha) ficha.innerHTML = '<div style="text-align:center; padding:18px; color:#64748b;">Cargando historial del cliente...</div>';
    ['histCliStatCompras', 'histCliStatGanancia', 'histCliStatSaldo', 'histCliStatReembolso'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '$0.00';
    });

    fetch(`get_historial_cliente.php?cliente_id=${encodeURIComponent(clienteId)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                if (ficha) ficha.innerHTML = `<div style="text-align:center; padding:18px; color:#b91c1c; font-weight:600;">${escaparHTML(data.error || 'No se pudo cargar el historial.')}</div>`;
                return;
            }

            historialDetalle = data;
            historialClienteActual = data.cliente;

            // Los modales de abono/entrega del modulo Apartados trabajan sobre
            // la variable global apartadosData. Se alimenta con la misma forma
            // de datos para poder reutilizarlos desde aqui.
            if (typeof apartadosData !== 'undefined') {
                apartadosData = data.apartados || [];
            }

            mostrarVistasHistorial(true);
            renderizarFichaCliente(data);
            renderizarKPIsHistorial(data.resumen || {});
            filtrarHistorialDetalle();

            const seccion = document.getElementById('admin-seccion-historial');
            if (seccion && typeof seccion.scrollIntoView === 'function') {
                seccion.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        })
        .catch(err => {
            console.error('Error al cargar el historial del cliente:', err);
            if (ficha) ficha.innerHTML = '<div style="text-align:center; padding:18px; color:#b91c1c; font-weight:600;">Error de conexión al cargar el historial.</div>';
        })
        .finally(() => {
            historialClienteCargando = false;
        });
}

function mostrarVistasHistorial(enDetalle) {
    const indice = document.getElementById('historialVistaIndice');
    const detalle = document.getElementById('historialVistaDetalle');
    const btnVolver = document.getElementById('btnHistorialVolver');

    if (indice) indice.style.display = enDetalle ? 'none' : 'block';
    if (detalle) detalle.style.display = enDetalle ? 'block' : 'none';
    if (btnVolver) btnVolver.style.display = enDetalle ? 'inline-flex' : 'none';
}

function cerrarDetalleHistorial() {
    mostrarVistasHistorial(false);
    historialDetalle = null;
    historialClienteActual = null;
    historialVisto = null;

    // Se limpian los filtros del detalle para que la proxima consulta empiece
    // mostrando todo el historial del proximo cliente.
    const input = document.getElementById('historialBuscarDetalle');
    if (input) input.value = '';
    const selTipo = document.getElementById('historialFiltroTipo');
    if (selTipo) selTipo.value = 'todo';
    const selPeriodo = document.getElementById('historialFiltroPeriodo');
    if (selPeriodo) selPeriodo.value = '';
    ['historialDetalleFechaDesde', 'historialDetalleFechaHasta', 'historialDetalleHoraDesde', 'historialDetalleHoraHasta'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    // Se vacian los bloques para que no quede info del cliente anterior
    // visible un instante si se reabre la seccion.
    ['historialVentasBody', 'historialApartadosBody', 'historialDevolucionesBody'].forEach(id => {
        const cuerpo = document.getElementById(id);
        if (cuerpo) cuerpo.innerHTML = '';
    });
    ['historialContVentas', 'historialContApartados', 'historialContDevoluciones'].forEach(id => {
        const contador = document.getElementById(id);
        if (contador) { contador.textContent = '0'; contador.style.background = '#f1f5f9'; contador.style.color = '#94a3b8'; }
    });
    ['histCliStatCompras', 'histCliStatGanancia', 'histCliStatSaldo', 'histCliStatReembolso'].forEach(id => {
        const kpi = document.getElementById(id);
        if (kpi) kpi.textContent = '$0.00';
    });
    const ficha = document.getElementById('historialFichaCliente');
    if (ficha) ficha.innerHTML = '';

    cargarIndiceHistorial();
}

function renderizarFichaCliente(data) {
    const ficha = document.getElementById('historialFichaCliente');
    if (!ficha) return;

    const c = data.cliente || {};
    const resumen = data.resumen || {};

    const movimiento = [];
    if (resumen.ventas_total) movimiento.push(`${resumen.ventas_total} compra(s)`);
    if (resumen.apartados_activos) movimiento.push(`${resumen.apartados_activos} apartado(s) activo(s)`);
    if (resumen.devoluciones_activas) movimiento.push(`${resumen.devoluciones_activas} devolución(es)`);
    if (movimiento.length === 0) movimiento.push('sin movimientos registrados');

    ficha.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
            <div style="display:flex; gap:14px; align-items:center;">
                <div style="width:56px; height:56px; border-radius:50%; background:#4f46e5; color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.4rem; font-weight:800; flex-shrink:0;">
                    ${escaparHTML((c.nombre_completo || '?').trim().charAt(0).toUpperCase())}
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.25rem;">${escaparHTML(c.nombre_completo || 'Sin nombre')}</h3>
                    <div style="font-size:0.85rem; color:#4b5563; margin-top:3px;">
                        👤 ${escaparHTML(c.usuario || '-')}
                        ${c.cedula ? ` · 🪪 CI: ${escaparHTML(c.cedula)}` : ''}
                        ${c.telefono ? ` · 📞 ${escaparHTML(c.telefono)}` : ''}
                        ${c.email ? ` · ✉️ ${escaparHTML(c.email)}` : ''}
                    </div>
                    <div style="font-size:0.8rem; color:#6366f1; font-weight:700; margin-top:5px;">
                        ${movimiento.join(' · ')}
                    </div>
                </div>
            </div>
            <div style="text-align:right; font-size:0.78rem; color:#4b5563;">
                <div>📅 Registrado: ${c.fecha_registro ? escaparHTML(String(c.fecha_registro).substring(0, 10)) : '-'}</div>
                ${c.direccion ? `<div style="margin-top:3px;">🏠 ${escaparHTML(c.direccion)}</div>` : ''}
            </div>
        </div>`;
}

function renderizarKPIsHistorial(resumen) {
    const set = (id, valor) => {
        const el = document.getElementById(id);
        if (el) el.textContent = valor;
    };
    set('histCliStatCompras', fmtUSD(resumen.compras_usd || 0));
    set('histCliStatGanancia', fmtUSD(resumen.ganancia_usd || 0));
    set('histCliStatSaldo', fmtUSD(resumen.apartados_saldo || 0));
    set('histCliStatReembolso', fmtUSD(resumen.devoluciones_usd || 0));
}

/** Refresca el detalle si hay un cliente abierto (lo llama admin.js tras abonos/entregas). */
function refrescarHistorialSiEstaAbierto() {
    if (!historialClienteActual) return;
    const clienteId = historialClienteActual.id;
    historialClienteActual = null; // permite recargar
    abrirHistorialCliente(clienteId);
}

// ==================== FILTROS DEL DETALLE ====================

function _historialPasaPeriodo(fecha, dias) {
    if (!dias) return true;
    if (!fecha) return false;
    const f = new Date(String(fecha).replace(' ', 'T'));
    if (isNaN(f)) return false;
    const limite = new Date();
    limite.setHours(0, 0, 0, 0);
    limite.setDate(limite.getDate() - parseInt(dias, 10));
    return f >= limite;
}

function filtrarHistorialDetalle() {
    if (!historialDetalle) return;

    const texto = ((document.getElementById('historialBuscarDetalle')?.value) || '').toLowerCase().trim();
    const tipo = (document.getElementById('historialFiltroTipo')?.value) || 'todo';
    const periodo = (document.getElementById('historialFiltroPeriodo')?.value) || '';
    const pasaRangoFecha = _creadorFiltroRangoHistorial('historialDetalle');

    const mostrarVentas = (tipo === 'todo' || tipo === 'ventas');
    const mostrarApartados = (tipo === 'todo' || tipo === 'apartados');
    const mostrarDevoluciones = (tipo === 'todo' || tipo === 'devoluciones');

    // Al cambiar los filtros se vuelve a la primera página de cada bloque.
    ['historialVentasBody', 'historialApartadosBody', 'historialDevolucionesBody'].forEach(id => {
        if (estadoPaginacion[id]) estadoPaginacion[id].paginaActual = 1;
    });

    // ---- COMPRAS ----
    const ventas = (historialDetalle.ventas || []).filter(v => {
        if (periodo && !_historialPasaPeriodo(v.fecha_venta, periodo)) return false;
        if (!pasaRangoFecha(v.fecha_venta)) return false;
        if (texto) {
            const textoFila = `${v.id} ${v.productos || ''} ${v.estado || ''} ${v.metodo_pago || ''} ${v.referencia_pago || ''} ${v.total_usd} ${v.total_bs}`.toLowerCase();
            if (!textoFila.includes(texto)) return false;
        }
        return true;
    });
    pintarBloqueHistorial('historialBloqueVentas', 'historialVentasBody', mostrarVentas ? ventas.length : 0, renderizarVentasHistorial, ventas, 8,
        'Este cliente no tiene compras que coincidan con los filtros.');

    // ---- APARTADOS ----
    const apartados = (historialDetalle.apartados || []).filter(a => {
        if (periodo && !_historialPasaPeriodo(a.fecha_apartado, periodo)) return false;
        if (!pasaRangoFecha(a.fecha_apartado)) return false;
        if (texto) {
            const textoFila = `${a.id} ${a.venta_id} ${a.productos || ''} ${a.estado || ''} ${a.total_usd} ${a.monto_abonado_usd} ${a.monto_pendiente_usd}`.toLowerCase();
            if (!textoFila.includes(texto)) return false;
        }
        return true;
    });
    pintarBloqueHistorial('historialBloqueApartados', 'historialApartadosBody', mostrarApartados ? apartados.length : 0, renderizarApartadosHistorial, apartados, 0,
        'Este cliente no tiene apartados que coincidan con los filtros.');

    // ---- DEVOLUCIONES ----
    const devoluciones = (historialDetalle.devoluciones || []).filter(d => {
        if (periodo && !_historialPasaPeriodo(d.fecha_devolucion, periodo)) return false;
        if (!pasaRangoFecha(d.fecha_devolucion)) return false;
        if (texto) {
            const textoFila = `${d.id} ${d.venta_id} ${d.productos || ''} ${d.tipo || ''} ${d.estado || ''} ${d.metodo_reembolso || ''} ${d.motivo || ''} ${d.monto_devuelto_usd}`.toLowerCase();
            if (!textoFila.includes(texto)) return false;
        }
        return true;
    });
    pintarBloqueHistorial('historialBloqueDevoluciones', 'historialDevolucionesBody', mostrarDevoluciones ? devoluciones.length : 0, renderizarDevolucionesHistorial, devoluciones, 8,
        'Este cliente no tiene devoluciones que coincidan con los filtros.');

    // Se guarda lo que se está viendo para que el reporte y el Excel salgan
    // exactamente con los mismos filtros que la pantalla, no con todo el historial.
    historialVisto = {
        tipo: tipo,
        periodo: periodo,
        texto: texto,
        ventas: ventas,
        apartados: apartados,
        devoluciones: devoluciones,
        // Que bloques están a la vista: si el filtro es "solo apartados" el
        // reporte no debe imprimir una tabla de compras vacía.
        muestraVentas: mostrarVentas,
        muestraApartados: mostrarApartados,
        muestraDevoluciones: mostrarDevoluciones
    };
}

/** Vacia los filtros del detalle (texto, tipo, periodo y rango de fecha/hora). */
function limpiarFiltrosHistorialDetalle() {
    const ids = ['historialBuscarDetalle', 'historialFiltroTipo', 'historialFiltroPeriodo',
                 'historialDetalleFechaDesde', 'historialDetalleFechaHasta',
                 'historialDetalleHoraDesde', 'historialDetalleHoraHasta'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.value = (id === 'historialFiltroTipo') ? 'todo' : '';
    });
    filtrarHistorialDetalle();
}

/**
 * Muestra u oculta un bloque completo del historial y pinta su contenido.
 * @param {string} bloqueId  id del div del bloque
 * @param {string} cuerpoId  id del tbody (o div, en el caso de apartados)
 * @param {number} cantidad  cuantos registros pasaron el filtro
 * @param {Function} pintar  funcion que dibuja las filas
 * @param {Array} lista      registros filtrados
 * @param {number} colspan   columnas de la tabla, para el mensaje de "sin resultados"
 * @param {string} vacio     texto del mensaje de "sin resultados"
 */
function pintarBloqueHistorial(bloqueId, cuerpoId, cantidad, pintar, lista, colspan, vacio) {
    const bloque = document.getElementById(bloqueId);
    const cuerpo = document.getElementById(cuerpoId);
    const contador = document.getElementById(bloqueId.replace('Bloque', 'Cont'));

    if (contador) {
        contador.textContent = cantidad;
        contador.style.background = cantidad > 0 ? '#e0e7ff' : '#f1f5f9';
        contador.style.color = cantidad > 0 ? '#4338ca' : '#94a3b8';
    }
    if (!bloque || !cuerpo) return;

    bloque.style.display = cantidad > 0 ? 'block' : 'none';

    if (cantidad === 0) {
        // Dentro de un <tbody> el mensaje debe ser un <tr>, si no el navegador
        // lo saca de la tabla y rompe el desenho.
        const mensaje = `text-align:center; padding:22px; color:#94a3b8; font-size:0.88rem;`;
        cuerpo.innerHTML = (cuerpo.tagName === 'TBODY')
            ? `<tr><td colspan="${colspan}" style="${mensaje}">${escaparHTML(vacio)}</td></tr>`
            : `<div style="${mensaje}">${escaparHTML(vacio)}</div>`;
        return;
    }
    cuerpo.innerHTML = pintar(lista);
    // Se pagina el contenido recien pintado: funciona tanto para <tbody>
    // (filas <tr>) como para contenedores de tarjetas como los apartados (div).
    actualizarPaginacionTabla(cuerpoId);
}

function renderizarVentasHistorial(lista) {
    return lista.map(v => {
        const badge = _historialBadgeVenta(v.estado);
        const esApartado = v.estado === 'Apartado';

        return `
        <tr>
            <td style="font-size:0.8rem; white-space:nowrap;">${v.fecha_venta ? escaparHTML(String(v.fecha_venta).substring(0, 16)) : '-'}</td>
            <td><strong style="color:#4338ca;">#${v.id}</strong></td>
            <td style="font-size:0.82rem; max-width:230px; white-space:normal;">${escaparHTML(v.productos || 'Sin detalles')}</td>
            <td style="white-space:nowrap;"><strong style="color:#1d4ed8;">${fmtUSD(v.total_usd)}</strong></td>
            <td style="white-space:nowrap; font-size:0.78rem; color:#666;">Bs ${parseFloat(v.total_bs || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
            <td style="font-size:0.78rem;">${escaparHTML(v.metodo_pago || '-')}${v.referencia_pago ? `<div style="color:#888; font-size:0.72rem;">Ref: ${escaparHTML(v.referencia_pago)}</div>` : ''}</td>
            <td style="white-space:nowrap; font-weight:700; color:${parseFloat(v.ganancia_usd) > 0 ? '#16a34a' : '#94a3b8'};">${fmtUSD(v.ganancia_usd || 0)}</td>
            <td>${badge}${esApartado ? '<div style="font-size:0.7rem; color:#94a3b8;">ver bloque de apartados</div>' : ''}</td>
        </tr>`;
    }).join('');
}

/**
 * Plan de pagos de un apartado según la configuración del administrador.
 * Usa las cuotas guardadas al crear el apartado; si es anterior a esa columna,
 * se aplica la configuración global vigente (window.configApartado).
 *
 * Regla: el total del apartado se divide en N cuotas; lo abonado cubre cuotas
 * completas y el saldo que falta se reparte entre las cuotas pendientes.
 *
 * @param {object} a apartado (total_usd, monto_abonado_usd, monto_pendiente_usd, cuotas)
 * @returns {{cuotas:number, cuota:number, pagadas:number, restantes:number, sugerida:number}}
 */
function planPagosApartado(a) {
    let cuotas = parseInt(a.cuotas, 10);
    if (!cuotas || cuotas < 1) {
        cuotas = parseInt((window.configApartado && window.configApartado.cuotas) || 3, 10);
    }

    const total = parseFloat(a.total_usd || 0);
    const abonado = parseFloat(a.monto_abonado_usd || 0);
    const pendiente = parseFloat(a.monto_pendiente_usd || 0);

    const cuota = total > 0 ? Math.round((total / cuotas) * 100) / 100 : 0;
    let pagadas = cuota > 0 ? Math.floor((abonado + 0.005) / cuota) : 0;
    pagadas = Math.max(0, Math.min(cuotas, pagadas));
    if (pendiente <= 0.001) pagadas = cuotas;

    const restantes = Math.max(0, cuotas - pagadas);
    const sugerida = (restantes > 0 && pendiente > 0) ? Math.round((pendiente / restantes) * 100) / 100 : 0;

    return { cuotas: cuotas, cuota: cuota, pagadas: pagadas, restantes: restantes, sugerida: sugerida };
}

function renderizarApartadosHistorial(lista) {
    return lista.map(a => {
        const pendiente = parseFloat(a.monto_pendiente_usd || 0);
        const pct = a.porcentaje_pagado || 0;
        const colorBarra = pct >= 100 ? '#16a34a' : (pct >= 50 ? '#0284c7' : '#d97706');
        const esCancelado = a.estado === 'Cancelado';
        const esCompletado = a.estado === 'Completado';
        const diasRestantes = parseInt(a.dias_restantes || 0, 10);
        const plan = planPagosApartado(a);

        let plazo = '<span style="color:#94a3b8;">—</span>';
        if (!esCancelado && !esCompletado) {
            if (diasRestantes < 0) plazo = `<span style="color:#b91c1c; font-weight:700;">Venció hace ${Math.abs(diasRestantes)} d.</span>`;
            else if (diasRestantes === 0) plazo = '<span style="color:#ea580c; font-weight:800;">Vence hoy</span>';
            else if (diasRestantes <= 3) plazo = `<span style="color:#d97706; font-weight:700;">Quedan ${diasRestantes} d.</span>`;
            else plazo = `<span style="color:#15803d; font-weight:600;">Quedan ${diasRestantes} d.</span>`;
        } else if (esCompletado) {
            plazo = `<span style="color:#15803d; font-weight:600;">${a.fecha_completado ? escaparHTML(String(a.fecha_completado).substring(0, 10)) : 'Completado'}</span>`;
        }

        let estadoBadge;
        if (esCompletado) {
            estadoBadge = (a.venta_estado === 'Entregado')
                ? '<span style="padding:4px 9px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#dcfce7; color:#15803d;">✅ 100% Pagado (Entregado)</span>'
                : '<span style="padding:4px 9px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#dbeafe; color:#1e40af;">🎉 100% Pagado (Listo para retirar)</span>';
        } else if (esCancelado) {
            estadoBadge = '<span style="padding:4px 9px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#f3f4f6; color:#6b7280;">⛔ Cancelado</span>';
        } else if (diasRestantes < 0 || a.estado === 'Vencido') {
            estadoBadge = '<span style="padding:4px 9px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#fee2e2; color:#b91c1c;">🚨 Vencido</span>';
        } else {
            estadoBadge = '<span style="padding:4px 9px; border-radius:6px; font-size:0.75rem; font-weight:700; background:#fef3c7; color:#b45309;">⏳ En Proceso</span>';
        }

        // Historial de abonos, plegado para no saturar la pantalla
        const abonos = a.abonos || [];
        const detalleAbonos = abonos.length === 0
            ? '<div style="font-size:0.8rem; color:#94a3b8; padding:8px 0;">Sin abonos registrados.</div>'
            : `<table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                   ${abonos.map(ab => `
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:5px 4px; white-space:nowrap; color:#64748b;">${escaparHTML(String(ab.fecha_abono).substring(0, 16))}</td>
                            <td style="padding:5px 4px; font-weight:700; color:#15803d; white-space:nowrap;">${fmtUSD(ab.monto_usd)}</td>
                            <td style="padding:5px 4px; color:#64748b;">${escaparHTML(ab.metodo_pago || '-')}</td>
                            <td style="padding:5px 4px; color:#94a3b8;">${escaparHTML(ab.referencia || '-')}</td>
                            <td style="padding:5px 4px;">${ab.comprobante_img ? `<a href="../${escaparHTML(ab.comprobante_img)}" target="_blank" style="color:#4f46e5; font-weight:600;">Ver recibo</a>` : ''}</td>
                        </tr>`).join('')}
               </table>`;

        const resumenEscapado = escaparHTML(`Apartado #${a.id}`);
        const botones = [];
        if (!esCancelado && pendiente > 0.001) {
            botones.push(`<button type="button" onclick="abrirModalRegistrarAbonoApartado(${a.id}, '${resumenEscapado}', ${a.total_usd}, ${a.monto_abonado_usd}, ${a.monto_pendiente_usd}, ${a.tasa_cambio}, ${plan.sugerida})"
                                style="padding:5px 11px; background:#15803d; color:#fff; border:none; border-radius:6px; font-weight:700; font-size:0.75rem; cursor:pointer;">💵 Abonar</button>`);
        }
        if (esCompletado && a.venta_estado !== 'Entregado') {
            botones.push(`<button type="button" onclick="entregarApartadoDirecto(${a.id}, '${resumenEscapado}', ${pendiente})"
                                style="padding:5px 11px; background:#0284c7; color:#fff; border:none; border-radius:6px; font-weight:700; font-size:0.75rem; cursor:pointer;">📦 Entregar</button>`);
        }
        if (!esCancelado && a.venta_estado !== 'Entregado') {
            botones.push(`<button type="button" onclick="abrirModalCancelarApartado(${a.id}, '${resumenEscapado}')"
                                style="padding:5px 11px; background:#fff; color:#b91c1c; border:1px solid #fca5a5; border-radius:6px; font-weight:700; font-size:0.75rem; cursor:pointer;">✕ Cancelar</button>`);
        }

        return `
        <div style="border:1px solid ${esCancelado ? '#e5e7eb' : '#dbeafe'}; background:${esCancelado ? '#fcfcfc' : '#f8fbff'}; border-radius:12px; padding:14px; margin-bottom:12px; ${esCancelado ? 'opacity:0.72;' : ''}">
            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="flex:1; min-width:220px;">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <strong style="color:#4338ca; font-size:1rem;">Apartado #${a.id}</strong>
                        ${estadoBadge}
                        <span style="font-size:0.78rem; color:#64748b;">· Venta #${a.venta_id}</span>
                    </div>
                    <div style="font-size:0.85rem; color:#374151; margin-top:5px;">${escaparHTML(a.productos || 'Sin productos')}</div>
                    <div style="font-size:0.75rem; color:#6b7280; margin-top:3px;">
                        📅 ${a.fecha_apartado ? escaparHTML(String(a.fecha_apartado).substring(0, 10)) : '-'}
                        · Límite: ${a.fecha_limite ? escaparHTML(String(a.fecha_limite).substring(0, 10)) : '-'}
                    </div>
                    ${(!esCancelado && plan.cuota > 0) ? `
                    <div style="font-size:0.76rem; color:#3730a3; background:#eef2ff; border:1px dashed #c7d2fe; border-radius:8px; padding:6px 9px; margin-top:7px; line-height:1.6; display:inline-block;">
                        📐 Plan: <strong>${plan.cuotas} cuotas de ${fmtUSD(plan.cuota)}</strong>
                        · Pagadas <strong>${plan.pagadas}/${plan.cuotas}</strong>
                        ${plan.restantes > 0
                            ? `· Faltan <strong>${plan.restantes}</strong> cuota${plan.restantes > 1 ? 's' : ''} de <strong>${fmtUSD(plan.sugerida)}</strong>`
                            : '· ✅ Saldo completado'}
                    </div>` : ''}
                </div>
                <div style="text-align:right; min-width:190px;">
                    <div style="font-size:1rem; font-weight:800; color:#1d4ed8;">${fmtUSD(a.total_usd)}</div>
                    <div style="font-size:0.78rem; color:#15803d; font-weight:700;">Abonado: ${fmtUSD(a.monto_abonado_usd)}</div>
                    <div style="font-size:0.78rem; color:${pendiente > 0 ? '#b91c1c' : '#15803d'}; font-weight:800;">Pendiente: ${fmtUSD(pendiente)}</div>
                    <div style="width:130px; background:#e5e7eb; border-radius:8px; height:7px; overflow:hidden; margin:6px 0 3px auto;">
                        <div style="width:${pct}%; background:${colorBarra}; height:100%;"></div>
                    </div>
                    <div style="font-size:0.72rem; color:#6b7280;">${pct}% pagado · ${plazo}</div>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-top:10px; padding-top:10px; border-top:1px dashed #e2e8f0;">
                <button type="button" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'"
                        style="background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:5px 11px; font-size:0.78rem; font-weight:700; cursor:pointer; color:#475569;">
                    📋 Ver abonos (${abonos.length})
                </button>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">${botones.join('')}</div>
            </div>
            <div style="display:none; margin-top:10px; padding-top:10px; border-top:1px dashed #e2e8f0;">${detalleAbonos}</div>
        </div>`;
    }).join('');
}

function renderizarDevolucionesHistorial(lista) {
    return lista.map(d => {
        const tipoBadge = d.tipo === 'TOTAL'
            ? '<span style="background:#f3e8ff; color:#7e22ce; border:1px solid #d8b4fe; padding:4px 10px; border-radius:12px; font-size:0.76rem; font-weight:700;">Total</span>'
            : '<span style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:4px 10px; border-radius:12px; font-size:0.76rem; font-weight:700;">Parcial</span>';

        const estadoBadge = d.estado === 'ACTIVA'
            ? '<span style="background:#e8f5e9; color:#2e7d32; border:1px solid #c8e6c9; padding:4px 10px; border-radius:12px; font-size:0.76rem; font-weight:700;">✅ Activa</span>'
            : '<span style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; padding:4px 10px; border-radius:12px; font-size:0.76rem; font-weight:700;">⛔ Anulada</span>';

        const notaAnulacion = (d.estado === 'ANULADA' && d.motivo_anulacion)
            ? `<div style="font-size:0.72rem; color:#b45309; margin-top:4px;">Anulada por ${escaparHTML(d.anulado_por || 'admin')}: ${escaparHTML(d.motivo_anulacion)}</div>`
            : '';

        const etiquetaMetodo = (typeof METODOS_REEMBOLSO_LABEL !== 'undefined' && METODOS_REEMBOLSO_LABEL[d.metodo_reembolso])
            ? METODOS_REEMBOLSO_LABEL[d.metodo_reembolso]
            : (d.metodo_reembolso || '-');

        // Solo una devolución activa se puede anular: anularla devuelve la
        // mercancía al cliente y libera otra vez el saldo del reembolso.
        const botonAnular = (d.estado === 'ACTIVA')
            ? `<button type="button" onclick="abrirAnularDevolucionHistorial(${d.id})"
                   style="padding:5px 10px; background:#fff; color:#b91c1c; border:1px solid #fca5a5; border-radius:6px; font-weight:700; font-size:0.75rem; cursor:pointer; white-space:nowrap;">⛔ Anular</button>`
            : '<span style="font-size:0.75rem; color:#cbd5e1;">—</span>';

        return `
        <tr style="${d.estado === 'ANULADA' ? 'opacity:0.62;' : ''}">
            <td style="font-size:0.8rem; white-space:nowrap;">${d.fecha_devolucion ? escaparHTML(String(d.fecha_devolucion).substring(0, 16)) : '-'}</td>
            <td><strong style="color:#4338ca;">#${d.venta_id}</strong></td>
            <td style="font-size:0.82rem; max-width:230px; white-space:normal;">${escaparHTML(d.productos || '-')}</td>
            <td>${tipoBadge}</td>
            <td style="white-space:nowrap;"><strong>${fmtUSD(d.monto_devuelto_usd)}</strong>
                <div style="font-size:0.72rem; color:#888;">Bs ${parseFloat(d.monto_devuelto_bs || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}</div></td>
            <td style="font-size:0.78rem; font-weight:600;">${escaparHTML(etiquetaMetodo)}</td>
            <td style="font-size:0.8rem; max-width:220px;">${escaparHTML(d.motivo || '-')}${notaAnulacion}</td>
            <td>${estadoBadge}</td>
            <td style="text-align:center;">${botonAnular}</td>
        </tr>`;
    }).join('');
}

function _historialBadgeVenta(estado) {
    const mapa = {
        'Pago Validado': ['#dcfce7', '#15803d', '✅ Pagado'],
        'Preparando':    ['#dbeafe', '#1e40af', '📦 Preparando'],
        'Entregado':     ['#ede9fe', '#6d28d9', '🚚 Entregado'],
        'Pendiente Pago':['#fef3c7', '#b45309', '⏳ Pendiente de pago'],
        'Apartado':      ['#cffafe', '#0e7490', '🏷️ Apartado'],
        'Cancelado':     ['#f1f5f9', '#64748b', '⛔ Cancelado']
    };
    const [bg, color, texto] = mapa[estado] || ['#f3f4f6', '#6b7280', escaparHTML(estado || '-')];
    return `<span style="background:${bg}; color:${color}; border:1px solid ${color}33; padding:4px 10px; border-radius:12px; font-size:0.76rem; font-weight:700; white-space:nowrap;">${texto}</span>`;
}

// ==================== ACCIONES SOBRE EL CLIENTE SELECCIONADO ====================

/**
 * Abre el modal de venta presencial con el cliente del historial ya elegido,
 * para no tener que buscarlo otra vez en el desplegable.
 */
function abrirVentaPresencialParaCliente() {
    const cliente = historialClienteActual;
    if (!cliente) {
        mostrarNotificacion('Primero selecciona un cliente de la lista.');
        return;
    }

    // abrirModalVentaPresencial() ya dispara la carga del desplegable de
    // clientes; se espera a esa misma carga en vez de pedirla de nuevo.
    abrirModalVentaPresencial();

    const esperar = (typeof promesaClientesParaVenta !== 'undefined' && promesaClientesParaVenta)
        ? promesaClientesParaVenta
        : Promise.resolve();

    esperar.then(() => {
        const select = document.getElementById('selectVentaCliente');
        if (!select) return;

        select.value = String(cliente.id);
        if (select.value !== String(cliente.id)) {
            mostrarNotificacion('Ese cliente ya no está en la lista. Revisa si fue eliminado.');
            return;
        }

        // Rellena el nombre del cliente con el dato del desplegable.
        if (typeof onClienteVentaSelected === 'function') onClienteVentaSelected();

        const nombreVenta = document.getElementById('inputVentaClienteNombre');
        if (nombreVenta && (!nombreVenta.value || nombreVenta.value === 'Cliente Presencial')) {
            nombreVenta.value = cliente.nombre_completo || cliente.usuario || 'Cliente Presencial';
        }
    }).catch(err => console.error('Error al preseleccionar el cliente:', err));
}

/**
 * Abre el modal de devolución con la búsqueda acotada a este cliente. Si tiene
 * alguna compra con mercancía por devolver, además deja elegida la más
 * reciente para no obligar a buscarla en la lista.
 */
function abrirDevolucionParaCliente() {
    const cliente = historialClienteActual;
    if (!cliente) {
        mostrarNotificacion('Primero selecciona un cliente de la lista.');
        return;
    }

    // abrirModalRegistrarDevolucion() ya dispara la búsqueda de ventas
    // devolubles y devuelve esa misma promesa, así que se espera aquí en vez de
    // pedir la lista otra vez.
    const abrir = (typeof abrirModalRegistrarDevolucion === 'function')
        ? abrirModalRegistrarDevolucion()
        : Promise.resolve();

    Promise.resolve(abrir).then(() => {
        const propias = (window.ventasDevolublesCache || [])
            .filter(v => v.usuario_id !== null && v.usuario_id !== undefined && Number(v.usuario_id) === Number(cliente.id));

        if (propias.length > 0) {
            // El endpoint las trae de la más reciente a la más antigua.
            const radio = document.querySelector(`input[name="ventaDevolucionElegida"][value="${propias[0].id}"]`);
            if (radio) {
                radio.checked = true;
                if (typeof seleccionarVentaDevolucion === 'function') seleccionarVentaDevolucion();
                return;
            }
        }

        // Sin compras pendientes de devolución: se deja el buscador filtrado por
        // este cliente para que se vea si hay alguna venta y por qué no aplica.
        const buscador = document.getElementById('devolucionBuscarVenta');
        const nombre = cliente.nombre_completo || cliente.usuario || '';
        if (buscador && nombre) {
            buscador.value = nombre;
            if (typeof buscarVentasDevolubles === 'function') buscarVentasDevolubles();
        }

        mostrarNotificacion(`No hay compras de ${nombre} con mercancía por devolver. Revisa la búsqueda del modal.`);
    }).catch(err => console.error('Error al preparar la devolución:', err));
}

/**
 * Abre el modal de anulación de devolución sobre la fila que se está viendo.
 *
 * El modal es el mismo del módulo Devoluciones (mismo formulario y mismo
 * `anular_devolucion.php`), pero se rellena con los datos que ya trajo el
 * historial en vez de una caché aparte, para no depender de un listado global
 * que ya no existe.
 */
function abrirAnularDevolucionHistorial(devolucionId) {
    const dev = ((historialDetalle?.devoluciones) || []).find(d => d.id === parseInt(devolucionId));
    if (!dev) {
        mostrarNotificacion('No se encontró la devolución seleccionada.');
        return;
    }
    if (dev.estado === 'ANULADA') {
        mostrarNotificacion('Esta devolución ya fue anulada anteriormente.');
        return;
    }

    const elId = document.getElementById('anularDevolucionId');
    if (elId) elId.textContent = dev.id;

    const cliente = historialClienteActual || {};
    const esEfectivo = dev.metodo_reembolso === 'efectivo';

    const resumen = document.getElementById('anularDevolucionResumen');
    if (resumen) {
        resumen.innerHTML = `
            <div><strong>Venta:</strong> #${dev.venta_id} — ${escaparHTML(cliente.nombre_completo || cliente.usuario || 'Mostrador')}</div>
            <div><strong>Mercancía devuelta:</strong> ${escaparHTML(dev.productos) || '-'}</div>
            <div><strong>Fecha:</strong> ${escaparHTML(String(dev.fecha_devolucion || '').substring(0, 16))} por ${escaparHTML(dev.registrado_por || '-')}</div>
            <div><strong>Motivo original:</strong> ${escaparHTML(dev.motivo) || '-'}</div>
            <div style="margin-top:6px; padding-top:6px; border-top:1px solid #e2e8f0;">
                Se descontará <strong>${fmtUSD(dev.monto_devuelto_usd)}</strong> de la mercancía del inventario
                (vuelve a estar en manos del cliente).
            </div>
            <div style="margin-top:6px; padding-top:6px; border-top:1px solid #e2e8f0; color:${esEfectivo ? '#b45309' : '#475569'};">
                ${esEfectivo
                    ? `⚠️ El efectivo de <strong>${fmtUSD(dev.monto_devuelto_usd)}</strong> dejará de descontarse del saldo esperado de caja.`
                    : `Este reembolso fue por <strong>${escaparHTML((typeof METODOS_REEMBOLSO_LABEL !== 'undefined' && METODOS_REEMBOLSO_LABEL[dev.metodo_reembolso]) || dev.metodo_reembolso)}</strong>, no afecta el cajón.`}
            </div>`;
    }

    const motivo = document.getElementById('anularDevolucionMotivo');
    if (motivo) motivo.value = '';

    const modal = document.getElementById('modalAnularDevolucion');
    if (modal) modal.style.display = 'flex';
}

/** Nombre de negocio para los encabezados de los reportes. */
function _historialNombreNegocio() {
    const marca = document.querySelector('.admin-brand');
    return (marca?.textContent || 'Tecnology').replace('| Admin', '').trim() || 'Tecnology';
}

/** Texto que describe los filtros activos, para imprimirlo en el reporte. */
function _historialDescripcionFiltros() {
    const v = historialVisto || {};
    const partes = [];

    const tipos = { todo: 'Todo el historial', ventas: 'Solo compras', apartados: 'Solo apartados', devoluciones: 'Solo devoluciones' };
    partes.push(tipos[v.tipo] || 'Todo el historial');

    if (v.periodo) partes.push('Últimos ' + v.periodo + ' días');
    if (v.texto) partes.push('Búsqueda: "' + v.texto + '"');

    return partes.join(' · ');
}

/** Subtotales de lo que hay en pantalla, para el pie del reporte. */
function _historialTotalesVistos() {
    const v = historialVisto || {};
    let compras = 0, ganancia = 0, abonado = 0, pendiente = 0, reembolsado = 0;

    (v.ventas || []).forEach(x => {
        if (['Pago Validado', 'Preparando', 'Entregado'].includes(x.estado)) {
            compras += parseFloat(x.total_usd || 0);
            ganancia += parseFloat(x.ganancia_usd || 0);
        }
    });
    (v.apartados || []).forEach(a => {
        abonado += parseFloat(a.monto_abonado_usd || 0);
        pendiente += parseFloat(a.monto_pendiente_usd || 0);
    });
    (v.devoluciones || []).forEach(d => {
        if (d.estado === 'ACTIVA') reembolsado += parseFloat(d.monto_devuelto_usd || 0);
    });

    return { compras, ganancia, abonado, pendiente, reembolsado };
}

/**
 * Reporte imprimible del historial completo del cliente (compras + apartados +
 * devoluciones) respetando los filtros que se están viendo en pantalla.
 */
function imprimirHistorialCliente() {
    const cliente = historialClienteActual;
    if (!cliente) {
        mostrarNotificacion('Primero selecciona un cliente de la lista.');
        return;
    }
    if (!historialVisto) return;

    const negocio = _historialNombreNegocio();
    const emitido = new Date().toLocaleString('es-VE', { dateStyle: 'full', timeStyle: 'short' });
    const t = _historialTotalesVistos();
    const v = historialVisto;

    const win = window.open('', '_blank', 'width=980,height=800');
    if (!win) {
        alert('El navegador bloqueó la ventana de impresión.\nPermite las ventanas emergentes para este sitio e inténtalo de nuevo.');
        return;
    }

    const filasVentas = (v.ventas || []).map(x => `
        <tr>
            <td>${escaparHTML(String(x.fecha_venta || '').substring(0, 16))}</td>
            <td>#${x.id}</td>
            <td>${escaparHTML(x.productos || 'Sin detalles')}</td>
            <td class="num">$${parseFloat(x.total_usd || 0).toFixed(2)}</td>
            <td class="num">Bs ${parseFloat(x.total_bs || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
            <td>${escaparHTML(x.metodo_pago || '-')}</td>
            <td class="num">$${parseFloat(x.ganancia_usd || 0).toFixed(2)}</td>
            <td>${escaparHTML(x.estado || '-')}</td>
        </tr>`).join('');

    const filasApartados = (v.apartados || []).map(a => `
        <tr>
            <td>#${a.id}</td>
            <td>${escaparHTML(String(a.fecha_apartado || '').substring(0, 10))}</td>
            <td>${escaparHTML(a.productos || 'Sin productos')}</td>
            <td class="num">$${parseFloat(a.total_usd || 0).toFixed(2)}</td>
            <td class="num">$${parseFloat(a.monto_abonado_usd || 0).toFixed(2)}</td>
            <td class="num">$${parseFloat(a.monto_pendiente_usd || 0).toFixed(2)}</td>
            <td class="num">${a.porcentaje_pagado || 0}%</td>
            <td>${escaparHTML(String(a.fecha_limite || '').substring(0, 10))}</td>
            <td>${escaparHTML(a.estado || '-')}</td>
        </tr>`).join('');

    const filasAbonos = (v.apartados || []).filter(a => (a.abonos || []).length > 0).map(a => `
        <tr>
            <td>#${a.id}</td>
            <td>${escaparHTML(String((a.abonos[0].fecha_abono) || '').substring(0, 16))}</td>
            <td>$${parseFloat(a.abonos[0].monto_usd || 0).toFixed(2)}</td>
            <td>${escaparHTML(a.abonos[0].metodo_pago || '-')}</td>
            <td>${escaparHTML(a.abonos[0].referencia || '-')}</td>
        </tr>`).join('');

    const filasDevoluciones = (v.devoluciones || []).map(d => `
        <tr>
            <td>${escaparHTML(String(d.fecha_devolucion || '').substring(0, 16))}</td>
            <td>#${d.venta_id}</td>
            <td>${escaparHTML(d.productos || '-')}</td>
            <td>${escaparHTML(d.tipo || '-')}</td>
            <td class="num">$${parseFloat(d.monto_devuelto_usd || 0).toFixed(2)}</td>
            <td class="num">Bs ${parseFloat(d.monto_devuelto_bs || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
            <td>${escaparHTML(d.metodo_reembolso || '-')}</td>
            <td>${escaparHTML(d.motivo || '-')}</td>
            <td>${escaparHTML(d.estado || '-')}</td>
        </tr>`).join('');

    // Un bloque por tipo, pero solo si el filtro activo lo está mostrando.
    const secciones = [];

    if (v.muestraVentas) {
        secciones.push(`
        <h2>Compras y ventas (${(v.ventas || []).length})</h2>
        <table>
            <thead><tr><th>Fecha</th><th>#</th><th>Productos</th><th>Total USD</th><th>Total Bs</th><th>Método</th><th>Ganancia</th><th>Estado</th></tr></thead>
            <tbody>${filasVentas || '<tr><td colspan="8" class="vacio">Sin compras en esta selección.</td></tr>'}</tbody>
            <tfoot><tr><th colspan="3">Subtotal compras pagadas</th><th class="num">$${t.compras.toFixed(2)}</th><th colspan="2"></th><th class="num">$${t.ganancia.toFixed(2)}</th><th></th></tr></tfoot>
        </table>`);
    }

    if (v.muestraApartados) {
        secciones.push(`
        <h2>Apartados y reservas (${(v.apartados || []).length})</h2>
        <table>
            <thead><tr><th>#</th><th>Fecha</th><th>Productos</th><th>Total USD</th><th>Abonado</th><th>Pendiente</th><th>% pagado</th><th>Límite</th><th>Estado</th></tr></thead>
            <tbody>${filasApartados || '<tr><td colspan="9" class="vacio">Sin apartados en esta selección.</td></tr>'}</tbody>
            <tfoot><tr><th colspan="3">Subtotal apartados</th><th></th><th class="num">$${t.abonado.toFixed(2)}</th><th class="num">$${t.pendiente.toFixed(2)}</th><th colspan="3"></th></tr></tfoot>
        </table>
        ${filasAbonos ? `
        <h3>Último abono de cada apartado</h3>
        <table>
            <thead><tr><th>Apartado</th><th>Fecha del abono</th><th>Monto</th><th>Método</th><th>Referencia</th></tr></thead>
            <tbody>${filasAbonos}</tbody>
        </table>` : ''}`);
    }

    if (v.muestraDevoluciones) {
        secciones.push(`
        <h2>Devoluciones (${(v.devoluciones || []).length})</h2>
        <table>
            <thead><tr><th>Fecha</th><th>Venta</th><th>Mercancía devuelta</th><th>Tipo</th><th>Monto USD</th><th>Monto Bs</th><th>Método</th><th>Motivo</th><th>Estado</th></tr></thead>
            <tbody>${filasDevoluciones || '<tr><td colspan="9" class="vacio">Sin devoluciones en esta selección.</td></tr>'}</tbody>
            <tfoot><tr><th colspan="4">Total reembolsado</th><th class="num">$${t.reembolsado.toFixed(2)}</th><th colspan="4"></th></tr></tfoot>
        </table>`);
    }

    win.document.write(`<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historial de ${escaparHTML(cliente.nombre_completo || cliente.usuario || 'Cliente')} - ${escaparHTML(negocio)}</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, sans-serif; color: #1e293b; margin: 24px; line-height: 1.5; font-size: 12px; }
    .report-header { border-bottom: 2px solid #0f172a; padding-bottom: 14px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; }
    .company-name { font-size: 1.7rem; font-weight: 800; color: #2563eb; }
    .report-title { font-size: 1.2rem; font-weight: 700; margin-top: 4px; }
    .report-meta { font-size: 0.82rem; color: #64748b; text-align: right; }
    .client-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; }
    .client-box strong { font-size: 1.05rem; }
    .filtros { font-size: 0.8rem; color: #475569; margin-top: 4px; }
    .kpis { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
    .kpi { flex: 1; min-width: 130px; border: 1px solid #e2e8f0; border-left-width: 4px; border-radius: 8px; padding: 9px 12px; }
    .kpi-label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 700; }
    .kpi-value { font-size: 1.05rem; font-weight: 800; }
    h2 { font-size: 1rem; color: #1e293b; margin: 22px 0 8px 0; padding-bottom: 5px; border-bottom: 1px solid #cbd5e1; }
    h3 { font-size: 0.88rem; color: #475569; margin: 16px 0 6px 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th { background: #f1f5f9; font-weight: 700; padding: 7px; border: 1px solid #cbd5e1; text-align: left; font-size: 10.5px; text-transform: uppercase; }
    td { padding: 6px 7px; border: 1px solid #e2e8f0; vertical-align: top; word-break: break-word; }
    tr:nth-child(even) td { background: #f8fafc; }
    tfoot th { background: #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    .vacio { text-align: center; color: #94a3b8; font-style: italic; }
    .report-footer { margin-top: 34px; border-top: 1px solid #e2e8f0; padding-top: 10px; font-size: 0.75rem; color: #94a3b8; display: flex; justify-content: space-between; }
    @media print { body { margin: 0; padding: 12px; } .no-print { display: none; } }
</style>
</head>
<body>
    <div class="report-header">
        <div>
            <div class="company-name">${escaparHTML(negocio)}</div>
            <div class="report-title">📋 Historial del Cliente</div>
        </div>
        <div class="report-meta">
            <div><strong>Fecha de emisión:</strong> ${escaparHTML(emitido)}</div>
            <div><strong>Sistema:</strong> ERP Control de Gestión</div>
        </div>
    </div>

    <div class="client-box">
        <strong>${escaparHTML(cliente.nombre_completo || 'Sin nombre')}</strong>
        <div class="filtros">
            Usuario: ${escaparHTML(cliente.usuario || '-')} · Cédula: ${escaparHTML(cliente.cedula || '-')} · Teléfono: ${escaparHTML(cliente.telefono || '-')} · Email: ${escaparHTML(cliente.email || '-')}
        </div>
        <div class="filtros"><strong>Detalle incluido:</strong> ${escaparHTML(_historialDescripcionFiltros())}</div>
    </div>

    <div class="kpis">
        <div class="kpi" style="border-left-color:#2563eb;"><div class="kpi-label">Total comprado</div><div class="kpi-value" style="color:#1d4ed8;">$${t.compras.toFixed(2)}</div></div>
        <div class="kpi" style="border-left-color:#16a34a;"><div class="kpi-label">Ganancia</div><div class="kpi-value" style="color:#15803d;">$${t.ganancia.toFixed(2)}</div></div>
        <div class="kpi" style="border-left-color:#d97706;"><div class="kpi-label">Saldo por cobrar</div><div class="kpi-value" style="color:#b45309;">$${t.pendiente.toFixed(2)}</div></div>
        <div class="kpi" style="border-left-color:#9333ea;"><div class="kpi-label">Reembolsado</div><div class="kpi-value" style="color:#7e22ce;">$${t.reembolsado.toFixed(2)}</div></div>
    </div>

    ${secciones.join('\n')}

    <div class="report-footer">
        <div>Documento interno confidencial - ${escaparHTML(negocio)}</div>
        <div>Generado desde el módulo Historial de Clientes</div>
    </div>

    <script>window.onload = function () { window.print(); };</script>
</body>
</html>`);
    win.document.close();
}

/**
 * Exporta a CSV lo mismo que muestra la pantalla: datos del cliente, resumen y
 * los tres bloques de movimientos con los filtros aplicados.
 */
function exportarHistorialClienteCSV() {
    const cliente = historialClienteActual;
    if (!cliente) {
        mostrarNotificacion('Primero selecciona un cliente de la lista.');
        return;
    }
    if (!historialVisto) return;

    const v = historialVisto;
    const t = _historialTotalesVistos();
    const lineas = [];

    // Las comas y comillas se neutralizan para no romper las columnas en Excel.
    const celda = valor => '"' + String(valor === null || valor === undefined ? '' : valor)
        .replace(/"/g, '""') + '"';
    // Cada llamada agrega una fila al CSV.
    const agregar = (...celdas) => lineas.push(celdas.map(celda).join(','));

    const dosDec = n => parseFloat(n || 0).toFixed(2);

    agregar('HISTORIAL DE CLIENTE');
    agregar('Cliente', cliente.nombre_completo || cliente.usuario || '');
    agregar('Usuario', cliente.usuario || '');
    agregar('Cedula', cliente.cedula || '');
    agregar('Telefono', cliente.telefono || '');
    agregar('Email', cliente.email || '');
    agregar('Registrado', cliente.fecha_registro ? String(cliente.fecha_registro).substring(0, 10) : '');
    agregar('Filtro aplicado', _historialDescripcionFiltros());
    agregar('Generado', new Date().toLocaleString('es-VE', { dateStyle: 'short', timeStyle: 'short' }));
    agregar('');

    agregar('RESUMEN');
    agregar('Compras pagadas (USD)', dosDec(t.compras));
    agregar('Ganancia (USD)', dosDec(t.ganancia));
    agregar('Apartados abonados (USD)', dosDec(t.abonado));
    agregar('Saldo por cobrar (USD)', dosDec(t.pendiente));
    agregar('Reembolsado (USD)', dosDec(t.reembolsado));
    agregar('');

    if (v.muestraVentas) {
        agregar('COMPRAS Y VENTAS (' + (v.ventas || []).length + ')');
        agregar('Fecha', 'Venta', 'Productos', 'Total USD', 'Total Bs', 'Metodo', 'Referencia', 'Ganancia USD', 'Estado');
        (v.ventas || []).forEach(x => {
            agregar(String(x.fecha_venta || '').substring(0, 16), '#' + x.id, x.productos || 'Sin detalles',
                dosDec(x.total_usd), dosDec(x.total_bs), x.metodo_pago || '', x.referencia_pago || '',
                dosDec(x.ganancia_usd), x.estado || '');
        });
        agregar('', '', 'Subtotal compras pagadas', dosDec(t.compras), '', '', '', dosDec(t.ganancia), '');
        agregar('');
    }

    if (v.muestraApartados) {
        agregar('APARTADOS Y RESERVAS (' + (v.apartados || []).length + ')');
        agregar('Apartado', 'Fecha', 'Venta', 'Productos', 'Total USD', 'Abonado USD', 'Pendiente USD', '% pagado', 'Fecha limite', 'Estado');
        (v.apartados || []).forEach(a => {
            agregar('#' + a.id, String(a.fecha_apartado || '').substring(0, 10), '#' + a.venta_id,
                a.productos || 'Sin productos', dosDec(a.total_usd), dosDec(a.monto_abonado_usd),
                dosDec(a.monto_pendiente_usd), (a.porcentaje_pagado || 0) + '%',
                String(a.fecha_limite || '').substring(0, 10), a.estado || '');
        });
        agregar('', 'Subtotal apartados', '', '', '', dosDec(t.abonado), dosDec(t.pendiente), '', '', '');
        agregar('');

        const conAbonos = (v.apartados || []).filter(a => (a.abonos || []).length > 0);
        if (conAbonos.length > 0) {
            agregar('ABONOS DE LOS APARTADOS');
            agregar('Apartado', 'Fecha abono', 'Monto USD', 'Monto Bs', 'Metodo', 'Referencia');
            conAbonos.forEach(a => {
                (a.abonos || []).forEach(ab => {
                    agregar('#' + a.id, String(ab.fecha_abono || '').substring(0, 16), dosDec(ab.monto_usd),
                        dosDec(ab.monto_bs), ab.metodo_pago || '', ab.referencia || '');
                });
            });
            agregar('');
        }
    }

    if (v.muestraDevoluciones) {
        agregar('DEVOLUCIONES (' + (v.devoluciones || []).length + ')');
        agregar('Fecha', 'Devolucion', 'Venta', 'Mercancia devuelta', 'Tipo', 'Monto USD', 'Monto Bs', 'Metodo', 'Motivo', 'Estado');
        (v.devoluciones || []).forEach(d => {
            agregar(String(d.fecha_devolucion || '').substring(0, 16), '#' + d.id, '#' + d.venta_id,
                d.productos || '', d.tipo || '', dosDec(d.monto_devuelto_usd), dosDec(d.monto_devuelto_bs),
                d.metodo_reembolso || '', d.motivo || '', d.estado || '');
        });
        agregar('', 'Total reembolsado', '', '', '', dosDec(t.reembolsado), '', '', '', '');
    }

    // El BOM hace que Excel respete los acentos y la coma decimal.
    const csv = '\uFEFF' + lineas.join('\r\n') + '\r\n';
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    const nombreArchivo = (cliente.nombre_completo || cliente.usuario || 'cliente')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')   // quita acentos para el nombre del archivo
        .replace(/[^a-zA-Z0-9]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '');

    link.setAttribute('href', url);
    link.setAttribute('download', `Historial_${nombreArchivo}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    mostrarNotificacion(`Historial de ${cliente.nombre_completo || cliente.usuario} exportado a Excel.`);
}