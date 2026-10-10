// ==================== CARRITO DE COMPRAS ====================

let carrito = [];

// Reglas de apartados definidas por el administrador.
// Se actualiza desde api/get_config.php (lo carga catalogo.js).
window.configApartado = window.configApartado || { cuotas: 3, dias_limite: 15 };

/** Cuotas a pagar y plazo (días) vigentes para los apartados. */
function configApartadoCliente() {
    const cfg = window.configApartado || {};
    return {
        cuotas: parseInt(cfg.cuotas, 10) || 3,
        dias_limite: parseInt(cfg.dias_limite, 10) || 15
    };
}

// Cargar carrito guardado en localStorage al iniciar
function cargarCarritoStorage() {
    const guardado = localStorage.getItem('miCarrito');
    if (guardado) {
        try {
            carrito = JSON.parse(guardado);
            actualizarContadorCarrito();
        } catch (e) {
            console.error("Error al cargar carrito:", e);
            carrito = [];
        }
    }
}

// Guardar carrito en localStorage
function guardarCarritoStorage() {
    localStorage.setItem('miCarrito', JSON.stringify(carrito));
    actualizarContadorCarrito();
    if (typeof renderizarCarrito === 'function') {
        renderizarCarrito();
    }
}

// Actualizar el contador del carrito en el header
function actualizarContadorCarrito() {
    const totalItems = carrito.reduce((sum, item) => sum + item.cantidad, 0);
    const cartCount = document.getElementById('cartCount');
    if (cartCount) {
        cartCount.innerText = totalItems;
        // Animación pequeña si hay ítems
        if (totalItems > 0) {
            cartCount.classList.add('pop');
            setTimeout(() => cartCount.classList.remove('pop'), 300);
        }
    }
}

// Agregar producto al carrito (cantidad variable)
function agregarAlCarrito(productoId, cantidadAgregar = 1) {
    const producto = productos.find(p => p.id === productoId);
    
    // Validar que exista y tenga stock
    if (!producto) {
        console.error('Producto no encontrado');
        return;
    }
    
    if (producto.stock <= 0) {
        mostrarNotificacion(`❌ "${producto.nombre}" está agotado.`);
        return;
    }

    if (cantidadAgregar <= 0) return;
    
    // Buscar si ya está en el carrito
    const itemExistente = carrito.find(item => item.id === productoId);
    const cantidadActualEnCarrito = itemExistente ? itemExistente.cantidad : 0;
    
    // Verificar que no supere el stock disponible
    if (cantidadActualEnCarrito + cantidadAgregar > producto.stock) {
        mostrarNotificacion(`⚠️ Solo hay ${producto.stock} disponibles. Ya tienes ${cantidadActualEnCarrito} en el carrito.`);
        return;
    }

    if (itemExistente) {
        itemExistente.cantidad += cantidadAgregar;
    } else {
        carrito.push({
            id: producto.id,
            nombre: producto.nombre,
            precio_usd: producto.precio_usd,
            cantidad: cantidadAgregar,
            stock_maximo: producto.stock
        });
    }
    
    guardarCarritoStorage();
    // Feedback visual suave en lugar de alert pesado
    mostrarNotificacion(`✅ ${cantidadAgregar} "${producto.nombre}" agregado`);
}

// Mostrar notificación temporal
function mostrarNotificacion(mensaje) {
    let notify = document.getElementById('notification-toast');
    if (!notify) {
        notify = document.createElement('div');
        notify.id = 'notification-toast';
        document.body.appendChild(notify);
    }
    notify.innerText = mensaje;
    notify.classList.add('show');
    setTimeout(() => notify.classList.remove('show'), 3000);
}

// Vaciar carrito completo
function vaciarCarrito() {
    if (carrito.length === 0) return;
    if (confirm('¿Estás seguro de que quieres vaciar el carrito?')) {
        carrito = [];
        guardarCarritoStorage();
    }
}

// Eliminar un producto específico del carrito
function eliminarDelCarrito(productoId) {
    carrito = carrito.filter(item => item.id !== productoId);
    guardarCarritoStorage();
}

// Mostrar/Ocultar el panel del carrito
function toggleCartPanel() {
    const panel = document.getElementById('cartPanel');
    if (!panel) return;
    
    const isOpen = !panel.classList.contains('hidden');
    if (isOpen) {
        panel.classList.add('hidden');
    } else {
        renderizarCarrito();
        panel.classList.remove('hidden');
    }
}

function renderizarCarrito() {
    const container = document.getElementById('cartItemsContainer');
    const totals = document.getElementById('cartTotals');

    if (!container || !totals) return;

    if (carrito.length === 0) {
        container.innerHTML = `
            <div class="empty-cart-msg">
                <p>🛒 Tu carrito está vacío</p>
                <small>¡Agrega algunos productos tecnológicos!</small>
            </div>`;
        totals.innerHTML = '';
        return;
    }

    let totalItems = 0;
    let totalUSD = 0;

    const listHtml = carrito.map(item => {
        const subtotal = item.precio_usd * item.cantidad;
        totalItems += item.cantidad;
        totalUSD += subtotal;

        return `
            <div class="cart-item">
                <div class="cart-item-info">
                    <div class="cart-item-name">${item.nombre}</div>
                    <div class="cart-item-details">
                        <span>${item.cantidad} x $${item.precio_usd.toFixed(2)}</span>
                        <span class="cart-item-subtotal">$${subtotal.toFixed(2)}</span>
                    </div>
                </div>
                <div class="cart-item-actions">
                    <button class="btn-remove" onclick="eliminarDelCarrito(${item.id})" title="Eliminar">🗑</button>
                </div>
            </div>
        `;
    }).join('');

    const totalBs = (totalUSD * tasaBCV).toFixed(2);
    const porcentajeApartado = 30;
    const cfgApartado = configApartadoCliente();
    const montoApartadoUSD = (totalUSD * porcentajeApartado / 100).toFixed(2);
    const montoApartadoBs  = (totalUSD * porcentajeApartado / 100 * tasaBCV).toFixed(2);

    container.innerHTML = listHtml;
    totals.innerHTML = `
        <div class="totals-row"><span>Items:</span> <strong>${totalItems}</strong></div>
        <div class="totals-row"><span>Total USD:</span> <strong class="price-usd">$${totalUSD.toFixed(2)}</strong></div>
        <div class="totals-row"><span>Total Bs:</span> <strong class="price-bs">Bs ${totalBs}</strong></div>
        <div class="totals-separator" style="border-top:1px dashed #c7d2fe; margin:8px 0;"></div>
        <div class="totals-row apartado-preview" style="background:#eef2ff; border-radius:8px; padding:8px 10px; margin-top:4px;">
            <div style="font-size:0.78rem; color:#4338ca; font-weight:700; margin-bottom:4px;">🏷️ Si apartas, pagas solo el ${porcentajeApartado}% inicial:</div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:1.05rem; font-weight:800; color:#3730a3;">Bs ${montoApartadoBs}</span>
                <span style="font-size:0.82rem; color:#6b7280;">$${montoApartadoUSD} USD</span>
            </div>
            <div style="font-size:0.72rem; color:#7c3aed; margin-top:3px;">El resto en ${cfgApartado.cuotas} cuota${cfgApartado.cuotas > 1 ? 's' : ''} · Plazo máx: ${cfgApartado.dias_limite} días</div>
        </div>
    `;

    // Actualizar el texto del botón de apartar con el monto exacto
    const btnApartar = document.getElementById('btnApartarCompra');
    if (btnApartar) {
        btnApartar.innerHTML = `🏷️ Apartar pedido &mdash; Pago inicial Bs ${montoApartadoBs} (${porcentajeApartado}%)`;
    }
}

// Variables globales para el pago
let currentVentaId = null;
let currentTotalUsd = 0;
let currentTotalBs = 0;
let paymentConfig = null;
let currentPaymentMethod = null;

// Al final de carrito.js
// Al final de carrito.js
async function cargarMetodosPago() {
    try {
        const response = await fetch('api/get_metodos_pago.php');
        const result = await response.json();
        if (result.success) {
            paymentConfig = {
                pago_movil: result.pago_movil,
                transferencia: result.transferencia,
                zelle: result.zelle
            };
        }
    } catch (e) {
        console.error("Error al cargar métodos de pago:", e);
    }
}

let currentEsApartado = false;
let currentApartadoData = null;

async function procesarCompra(esApartado = false) {
    if (carrito.length === 0) {
        alert("🛒 El carrito está vacío.");
        return;
    }

    if (typeof usuarioLogueado === 'undefined' || !usuarioLogueado) {
        mostrarNotificacion("🔒 Debes iniciar sesión para finalizar tu compra o apartar.");
        setTimeout(() => {
            window.location.href = 'auth/login.php';
        }, 1500);
        return;
    }

    if (esApartado) {
        const cfg = configApartadoCliente();
        const confirmar = confirm(
            "🏷️ APARTADO DE PRODUCTOS:\n\n" +
            "• Pagarás al menos el 30% inicial para reservar tu producto.\n" +
            `• Tendrás ${cfg.dias_limite} días continuos para pagar el resto en ${cfg.cuotas} cuota${cfg.cuotas > 1 ? 's' : ''}.\n` +
            "• El producto se mantendrá reservado en stock para ti.\n" +
            "• Podrás retirarlo en tienda una vez completado el 100%.\n\n" +
            "¿Deseas continuar con el apartado?"
        );
        if (!confirmar) return;
    }

    // Mostrar loader en el botón o modal si es necesario
    const panel = document.getElementById('cartPanel');
    
    try {
        const response = await fetch('api/procesar_compra.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                carrito: carrito,
                es_apartado: esApartado,
                porcentaje_apartado: 30,
                dias_limite: configApartadoCliente().dias_limite
            })
        });

        const textResponse = await response.text();
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseErr) {
            console.error('Error parseando JSON:', textResponse);
            alert('❌ Error en el servidor al procesar la orden.');
            return;
        }

        if (result.success) {
            currentVentaId = result.venta_id;
            currentTotalUsd = result.total_usd;
            currentTotalBs = result.total_bs;
            currentEsApartado = !!result.es_apartado;
            currentApartadoData = result.es_apartado ? result : null;

            paymentConfig = {
                pago_movil: result.pago_movil,
                transferencia: result.transferencia,
                zelle: result.zelle
            };

            // Cerrar el panel del carrito si está abierto
            if (panel && !panel.classList.contains('hidden')) {
                panel.classList.add('hidden');
            }

            // Abrir el modal de selección de método de pago
            abrirPaymentModal();
        } else {
            alert('❌ Error: ' + (result.message || 'Error al registrar la orden.'));
        }
    } catch (error) {
        console.error('Error al procesar compra:', error);
        alert('❌ Error de conexión al procesar la compra. Intenta nuevamente.');
    }
}

// ----------------- LÓGICA DEL MODAL DE PAGO -----------------

function abrirPaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (!modal) return;

    // Cambiar el label dinámicamente según el tipo de compra
    const labelEl = document.querySelector('#paymentMethodsContainer .payment-total');
    if (labelEl) {
        if (currentEsApartado) {
            labelEl.innerHTML = `Pago inicial requerido (30%): <strong id="paymentTotalAmount">Bs 0.00</strong>`;
        } else {
            labelEl.innerHTML = `Total a pagar: <strong id="paymentTotalAmount">Bs 0.00</strong>`;
        }
    }
    
    // Configurar el monto total
    if (currentEsApartado && currentApartadoData) {
        const montoMinUSD = currentApartadoData.monto_minimo_inicial_usd || 0;
        const montoMinBs = currentApartadoData.monto_minimo_inicial_bs || 0;
        document.getElementById('paymentTotalAmount').innerHTML = `
            <div style="font-size:0.85rem; color:#4338ca; font-weight:700;">🏷️ APARTADO (Inicial mín. ${currentApartadoData.porcentaje_apartado}%):</div>
            <div style="font-size:1.3rem; color:#15803d; font-weight:800;">Bs ${parseFloat(montoMinBs).toFixed(2)} <span style="font-size:0.9rem; color:#6b7280;">($${parseFloat(montoMinUSD).toFixed(2)} USD)</span></div>
            <div style="font-size:0.75rem; color:#6b7280; margin-top:2px;">Total de la compra: $${parseFloat(currentTotalUsd).toFixed(2)} · Plazo: 15 días (hasta ${currentApartadoData.fecha_limite})</div>
        `;
    } else {
        document.getElementById('paymentTotalAmount').innerText = `Bs ${parseFloat(currentTotalBs).toFixed(2)}`;
    }
    
    // Asegurarse de mostrar la vista de opciones (Pago Móvil / Transferencia) y ocultar el formulario
    document.getElementById('paymentMethodsContainer').classList.remove('hidden');
    document.getElementById('paymentDetailsContainer').classList.add('hidden');
    
    // Limpiar formulario y vista previa de comprobante
    document.getElementById('paymentForm').reset();
    const previewDiv = document.getElementById('fileUploadPreview');
    if (previewDiv) {
        previewDiv.innerHTML = '';
        previewDiv.style.display = 'none';
    }
    
    modal.classList.remove('hidden');
}

function cerrarPaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal) modal.classList.add('hidden');
}

function volverMetodosPago() {
    document.getElementById('paymentDetailsContainer').classList.add('hidden');
    document.getElementById('paymentMethodsContainer').classList.remove('hidden');
}

function seleccionarMetodoPago(metodo) {
    currentPaymentMethod = metodo;
    const hiddenMetodo = document.getElementById('inputMetodoPago');
    if (hiddenMetodo) hiddenMetodo.value = metodo;

    const detailsContainer = document.getElementById('paymentDetailsContainer');
    const methodsContainer = document.getElementById('paymentMethodsContainer');
    const instructions = document.getElementById('paymentInstructions');
    const title = document.getElementById('selectedPaymentTitle');
    const telGroup = document.getElementById('grupoTelefonoOrigen');
    
    methodsContainer.classList.add('hidden');
    detailsContainer.classList.remove('hidden');

    if (metodo === 'pago_movil') {
        title.innerText = '📱 Datos de Pago Móvil';
        if (telGroup) telGroup.style.display = 'block';
        document.getElementById('telefonoOrigen').required = true;
        
        const config = (paymentConfig && paymentConfig.pago_movil) ? paymentConfig.pago_movil : {};
        instructions.innerHTML = `
            <p><strong>Banco:</strong> ${config.banco || 'No definido'}</p>
            <p><strong>Teléfono:</strong> ${config.telefono || 'No definido'}</p>
            <p><strong>Cédula:</strong> ${config.cedula || 'No definido'}</p>
            <p><strong>Titular:</strong> ${config.titular || 'No definido'}</p>
            <p style="margin-top: 10px; font-size: 0.8rem;">Ingresa tu banco, teléfono y número de referencia.</p>
        `;
    } else if (metodo === 'transferencia') {
        title.innerText = '🏦 Datos de Transferencia';
        if (telGroup) telGroup.style.display = 'block';
        document.getElementById('telefonoOrigen').required = false;
        
        const config = (paymentConfig && paymentConfig.transferencia) ? paymentConfig.transferencia : {};
        instructions.innerHTML = `
            <p><strong>Banco:</strong> ${config.banco || 'No definido'}</p>
            <p><strong>Cuenta:</strong> ${config.cuenta || 'No definido'}</p>
            <p><strong>Titular:</strong> ${config.titular || 'No definido'}</p>
            <p><strong>Cédula/RIF:</strong> ${config.cedula || 'No definido'}</p>
            <p style="margin-top: 10px; font-size: 0.8rem;">Ingresa tu banco de origen y número de referencia.</p>
        `;
    } else if (metodo === 'zelle') {
        title.innerText = '🌐 Datos de Zelle';
        if (telGroup) telGroup.style.display = 'none';
        document.getElementById('telefonoOrigen').required = false;
        
        const config = (paymentConfig && paymentConfig.zelle) ? paymentConfig.zelle : {};
        instructions.innerHTML = `
            <p><strong>Correo / Zelle:</strong> ${config.correo || 'No definido'}</p>
            <p><strong>Titular:</strong> ${config.titular || 'No definido'}</p>
            <p style="margin-top: 10px; font-size: 0.8rem;">Envía el pago por Zelle e ingresa tu banco de origen y la referencia de la transferencia.</p>
        `;
    }
}

async function enviarComprobantePago(e) {
    e.preventDefault();
    
    if (!currentVentaId) {
        alert("❌ No se encontró el identificador de la orden de compra. Por favor intenta procesar la compra nuevamente.");
        return;
    }

    if (!currentPaymentMethod) {
        alert('Selecciona un método de pago para continuar.');
        return;
    }

    const bancoOrigen    = document.getElementById('bancoOrigen').value.trim();
    const prefijo = document.getElementById('prefijoTelefonoOrigen') ? document.getElementById('prefijoTelefonoOrigen').value : '';
    const numeroTel = document.getElementById('telefonoOrigen').value.trim();
    const telefonoOrigen = numeroTel ? prefijo + numeroTel : '';
    const referencia     = document.getElementById('referenciaPago').value.trim();

    if (!bancoOrigen || !referencia) {
        alert('Por favor selecciona el Banco de origen e ingresa la referencia del pago.');
        return;
    }

    if (referencia.length < 6 || referencia.length > 22) {
        alert('El número de referencia debe tener entre 6 y 22 caracteres.');
        return;
    }

    if (currentPaymentMethod === 'pago_movil' && !telefonoOrigen) {
        alert('Ingresa el número de teléfono de origen para verificar tu Pago Móvil.');
        return;
    }

    const btn = document.getElementById('btnSubmitPayment');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Enviando información de pago...';
    btn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('venta_id', currentVentaId);
        formData.append('metodo_pago', currentPaymentMethod);
        formData.append('banco_origen', bancoOrigen);
        formData.append('telefono_origen', telefonoOrigen);
        formData.append('referencia', referencia);
        formData.append('monto', currentTotalBs);

        const response = await fetch('api/subir_comprobante.php', {
            method: 'POST',
            body: formData
        });

        const textResponse = await response.text();
        let result;
        try {
            result = JSON.parse(textResponse);
        } catch (parseErr) {
            console.error('Error parseando JSON:', textResponse);
            alert('❌ Error en el servidor al enviar el comprobante de pago.');
            return;
        }

        if (result.success) {
            cerrarPaymentModal();
            mostrarNotificacion('✅ ' + result.message);

            carrito = [];
            guardarCarritoStorage();

            if (typeof cargarProductos === 'function') cargarProductos();
            window.location.href = 'customer/mis_pedidos.php';
        } else {
            alert('❌ Error: ' + (result.message || 'Error al enviar la información de pago.'));
        }
    } catch (error) {
        console.error('Error al enviar pago:', error);
        alert('❌ Error de conexión al enviar la información de pago. Intenta nuevamente.');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}


// ----------------- GENERAR PDF -----------------
async function generarReciboPDF(ventaId = 'N/A') {
    if (!ventaId || ventaId === 'N/A') {
        console.warn('No hay orden válida para generar el recibo.');
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
    doc.text("TECNOLOGY", 15, 22);

    doc.setFontSize(10);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(...lightTextColor);
    doc.text("COMPROBANTE DIGITAL DE PEDIDO", 15, 29);

    doc.setFont("helvetica", "bold");
    doc.setFontSize(14);
    doc.setTextColor(...textColor);
    doc.text(`ORDEN #${ventaId}`, 195, 22, { align: "right" });

    doc.setFontSize(9);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(...lightTextColor);
    doc.text(`Fecha: ${new Date().toLocaleDateString('es-VE')} ${new Date().toLocaleTimeString('es-VE')}`, 195, 29, { align: "right" });

    doc.setDrawColor(226, 232, 240);
    doc.line(15, 40, 195, 40);

    // Tabla de productos
    let y = 52;
    doc.setFillColor(241, 245, 249);
    doc.rect(15, y, 180, 8, 'F');

    doc.setFont("helvetica", "bold");
    doc.setFontSize(9);
    doc.setTextColor(...textColor);
    doc.text("PRODUCTO", 18, y + 5.5);
    doc.text("CANT.", 115, y + 5.5, { align: "center" });
    doc.text("PRECIO UNIT.", 145, y + 5.5);
    doc.text("SUBTOTAL", 192, y + 5.5, { align: "right" });

    y += 12;
    doc.setFont("helvetica", "normal");
    doc.setTextColor(...textColor);

    let totalUSD = 0;
    carrito.forEach(item => {
        const subtotal = item.precio_usd * item.cantidad;
        totalUSD += subtotal;

        let nombre = item.nombre;
        if (nombre.length > 40) nombre = nombre.substring(0, 37) + '...';

        doc.text(nombre, 18, y);
        doc.text(item.cantidad.toString(), 115, y, { align: "center" });
        doc.text(`$${item.precio_usd.toFixed(2)}`, 145, y);
        doc.text(`$${subtotal.toFixed(2)}`, 192, y, { align: "right" });

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

    const totalBs = totalUSD * tasaBCV;

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
    doc.text("Gracias por su compra en Tecnology. Conserva este comprobante para tu orden.", 105, 285, { align: "center" });

    doc.save(`Recibo_Pedido_Tecnology_${ventaId}.pdf`);
}

// Inicializar
cargarCarritoStorage();
cargarMetodosPago();

// Previsualizar archivo de comprobante seleccionado
function previsualizarComprobante(input) {
    const previewDiv = document.getElementById('fileUploadPreview');
    const uploadText = document.querySelector('.file-upload-text');
    if (!previewDiv || !input.files || !input.files[0]) return;

    const file = input.files[0];
    const uploadArea = document.getElementById('fileUploadArea');

    // Mostrar nombre del archivo
    if (uploadText) uploadText.textContent = `📄 ${file.name}`;
    if (uploadArea) uploadArea.style.borderColor = 'var(--primary)';

    // Si es imagen, mostrar miniatura
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
            previewDiv.innerHTML = `<img src="${e.target.result}" 
                style="max-width:100%; max-height:120px; border-radius:8px; margin-top:8px; object-fit:contain;">`;
            previewDiv.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        // PDF
        previewDiv.innerHTML = `<div style="padding:10px; font-size:0.85rem; color:var(--text-light);">📄 PDF adjunto correctamente</div>`;
        previewDiv.style.display = 'block';
    }
}