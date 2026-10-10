/**
 * manual.js - Módulo de Ayuda y Manuales de Usuario (Cliente y Administrador)
 * Incluye visualización en modal interactivo y descarga directa en PDF con jsPDF.
 */

function abrirModalAyuda(tipoInicial = 'cliente', esAdmin = false) {
    let modal = document.getElementById('manual-help-modal');
    if (!modal) {
        crearModalAyudaHTML();
        modal = document.getElementById('manual-help-modal');
    }

    const tabsContainer = document.getElementById('manual-tabs-container');
    const headerTitle = document.getElementById('manual-modal-title');
    const headerSub = document.getElementById('manual-modal-subtitle');

    if (esAdmin) {
        if (tabsContainer) tabsContainer.style.display = 'flex';
        if (headerTitle) headerTitle.innerText = '❓ Centro de Ayuda y Manuales del Sistema';
        if (headerSub) headerSub.innerText = 'Guías paso a paso para compras, pagos y administración del sistema.';
        cambiarTabManual(tipoInicial);
    } else {
        if (tabsContainer) tabsContainer.style.display = 'none';
        if (headerTitle) headerTitle.innerText = '❓ Manual de Usuario - Guía del Cliente';
        if (headerSub) headerSub.innerText = 'Guía paso a paso para realizar tus compras, pagos y seguimiento.';
        cambiarTabManual('cliente');
    }

    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function cerrarModalAyuda() {
    const modal = document.getElementById('manual-help-modal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function cambiarTabManual(tipo) {
    const tabCliente = document.getElementById('tab-manual-cliente');
    const tabAdmin = document.getElementById('tab-manual-admin');
    const contentCliente = document.getElementById('content-manual-cliente');
    const contentAdmin = document.getElementById('content-manual-admin');

    if (!contentCliente || !contentAdmin) return;

    if (tipo === 'admin') {
        if (tabCliente) { tabCliente.classList.remove('active'); tabCliente.style.background = 'transparent'; tabCliente.style.color = 'var(--text)'; }
        if (tabAdmin) { tabAdmin.classList.add('active'); tabAdmin.style.background = 'var(--primary)'; tabAdmin.style.color = '#fff'; }
        contentCliente.style.display = 'none';
        contentAdmin.style.display = 'block';
    } else {
        if (tabAdmin) { tabAdmin.classList.remove('active'); tabAdmin.style.background = 'transparent'; tabAdmin.style.color = 'var(--text)'; }
        if (tabCliente) { tabCliente.classList.add('active'); tabCliente.style.background = 'var(--primary)'; tabCliente.style.color = '#fff'; }
        contentAdmin.style.display = 'none';
        contentCliente.style.display = 'block';
    }
}

function crearModalAyudaHTML() {
    const modalDiv = document.createElement('div');
    modalDiv.id = 'manual-help-modal';
    modalDiv.className = 'manual-help-modal hidden';
    modalDiv.innerHTML = `
        <div class="manual-modal-overlay" onclick="cerrarModalAyuda()"></div>
        <div class="manual-modal-card">
            <button class="manual-close-btn" onclick="cerrarModalAyuda()">&times;</button>
            
            <div class="manual-header">
                <h2 id="manual-modal-title">❓ Centro de Ayuda y Manuales de Usuario</h2>
                <p id="manual-modal-subtitle">Guías paso a paso para compras, pagos y administración del sistema.</p>
            </div>

            <div class="manual-tabs" id="manual-tabs-container">
                <button type="button" id="tab-manual-cliente" class="manual-tab-btn active" onclick="cambiarTabManual('cliente')">
                    🛍️ Manual para Clientes
                </button>
                <button type="button" id="tab-manual-admin" class="manual-tab-btn" onclick="cambiarTabManual('admin')">
                    🛡️ Manual de Administrador
                </button>
            </div>

            <!-- CONTENIDO CLIENTE -->
            <div id="content-manual-cliente" class="manual-body-content">
                <div class="manual-download-banner">
                    <div>
                        <strong>📄 ¿Prefieres guardar el manual?</strong>
                        <p>Descarga la versión oficial en PDF para consultar sin conexión.</p>
                    </div>
                    <button class="btn-download-pdf" onclick="descargarManualClientePDF()">
                        📥 Descargar PDF (Cliente)
                    </button>
                </div>

                <div class="manual-section">
                    <h3>1. 🔍 Navegación y Búsqueda de Productos</h3>
                    <ul>
                        <li>Usa la barra superior de búsqueda para encontrar productos por nombre o modelo.</li>
                        <li>Filtra por categorías o ordénalos de menor a mayor precio.</li>
                        <li>Haz clic en cualquier producto para ver su <strong>Ficha Técnica</strong>, imágenes ampliadas y stock disponible.</li>
                        <li>Haz clic en el corazón 🤍 para guardar tus productos en <strong>Favoritos</strong>.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>2. 🛒 Carrito de Compras y Tasa Oficial (BCV)</h3>
                    <ul>
                        <li>Presiona <strong>"Agregar al carrito"</strong> para incluir unidades del producto.</li>
                        <li>Los precios se calculan automáticamente tanto en USD ($) como en Bolívares (Bs) a la <strong>Tasa Oficial BCV del día</strong>.</li>
                        <li>Revisa el total a pagar antes de hacer clic en <strong>"Finalizar compra"</strong>.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>3. 💳 Métodos de Pago en Bolívares y Divisas</h3>
                    <ul>
                        <li>Elige tu método de pago preferido: <strong>Pago Móvil</strong>, <strong>Transferencia Bancaria</strong> o <strong>Zelle / Divisas</strong>.</li>
                        <li>Copia los datos de la cuenta receptora mostrados en pantalla.</li>
                        <li>Realiza el pago en tu banco o aplicación correspondiente por el monto exacto indicado en Bs o USD.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>4. 📤 Subida de Comprobantes de Pago</h3>
                    <ul>
                        <li>Una vez completado el pago, ve a <strong>Mi Cuenta ➔ Mis Pedidos</strong>.</li>
                        <li>Ubica tu pedido y haz clic en <strong>"Pagar / Subir Comprobante"</strong>.</li>
                        <li>Adjunta la imagen o captura de tu comprobante (PNG, JPG o PDF) e ingresa los dígitos de la referencia bancaria.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>5. 🚚 Seguimiento de Pedidos</h3>
                    <ul>
                        <li><strong>🟡 Pendiente de Pago:</strong> Esperando que realices la transferencia o adjuntes comprobante.</li>
                        <li><strong>🔵 Pago Subido:</strong> El pago está en revisión por el administrador.</li>
                        <li><strong>🟢 Pago Validado:</strong> ¡Pago verificado exitosamente! Tu pedido está en preparación o listo para despacho.</li>
                    </ul>
                </div>
            </div>

            <!-- CONTENIDO ADMINISTRADOR -->
            <div id="content-manual-admin" class="manual-body-content" style="display:none;">
                <div class="manual-download-banner">
                    <div>
                        <strong>📄 Manual Oficial del Sistema</strong>
                        <p>Descarga la guía completa del administrador para gestión de tienda en PDF.</p>
                    </div>
                    <button class="btn-download-pdf" onclick="descargarManualAdminPDF()">
                        📥 Descargar PDF (Admin)
                    </button>
                </div>

                <div class="manual-section">
                    <h3>1. 📦 Gestión de Inventario y Publicación</h3>
                    <ul>
                        <li>Ingresa a la sección <strong>"➕ Publicar"</strong> para registrar nuevos artículos con título, precio en USD, imágenes y ficha técnica.</li>
                        <li>Configura el <strong>Stock Mínimo</strong> deseado. El sistema te alertará dinámicamente cuando el inventario esté por agotarse.</li>
                        <li>En la tabla de <strong>Inventario</strong>, puedes editar datos o despublicar cualquier producto existente.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>2. 🧾 Compras y Abastecimiento a Proveedores</h3>
                    <ul>
                        <li>Para sumar stock a un producto existente, ve a <strong>"🧾 Compras"</strong>.</li>
                        <li>Selecciona el proveedor y los productos abastecidos para registrar la entrada y actualizar el inventario automáticamente.</li>
                        <li>En <strong>Compras, Proveedores, Clientes, Historial de Clientes y Cierre de Caja</strong> puedes filtrar por <strong>rango de fechas y horas</strong> además del buscador de texto, con botón <strong>"🧹 Limpiar filtros"</strong> para volver a la lista completa.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>3. 📈 Tasa del Día (BCV) y Cuentas Bancarias</h3>
                    <ul>
                        <li>Actualiza la <strong>Tasa Oficial del Dólar (BCV)</strong> en la pestaña correspondiente. El cambio se refleja de inmediato en toda la tienda.</li>
                        <li>En <strong>"Mi Cuenta"</strong>, configura los números de teléfono para Pago Móvil, cuenta Zelle, cédula y cuentas bancarias visibles para los clientes.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>4. ✅ Módulo de Pagos Pendientes (Validación de Comprobantes)</h3>
                    <ul>
                        <li>Accede a <strong>"✅ Pagos Pendientes"</strong> para revisar las transferencias y pagos móviles enviados por los usuarios.</li>
                        <li>Inspecciona el comprobante adjunto. Si todo es correcto, presiona <strong>"Aprobar Pago"</strong> para descontar el stock e informar al cliente.</li>
                        <li>Si hay algún error en el pago, haz clic en <strong>"Rechazar Pago"</strong> e ingresa el motivo del rechazo.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>5. 🛡️ Copias de Seguridad (Backup de la Base de Datos)</h3>
                    <ul>
                        <li>En la sección <strong>"🛡️ Seguridad / BD"</strong>, puedes generar y descargar un respaldo completo SQL de la base de datos con 1 solo clic.</li>
                        <li>También puedes restaurar información previa importando el archivo SQL correspondiente.</li>
                    </ul>
                </div>

                <div class="manual-section">
                    <h3>6. ⚙️ Configuración de Apartados (cuotas y plazo)</h3>
                    <ul>
                        <li>Dentro del módulo <strong>"⚙️ Mi Cuenta"</strong> encontrarás la sección <strong>"🏷️ Configuración de Apartados"</strong>.</li>
                        <li>Define <strong>cuántas cuotas</strong> debe pagar el cliente (1 a 12) y el <strong>límite de tiempo de pago en días</strong> (1 a 90). Guarda con el botón <strong>"💾 Guardar Configuración"</strong>.</li>
                        <li>La cuota sugerida se calcula como <strong>total del apartado ÷ número de cuotas</strong>. Los apartados nuevos toman estas reglas; los existentes conservan su plan original.</li>
                        <li>El plan se muestra en cada apartado del Historial de Clientes, en la venta presencial, en la tienda en línea y en el portal <strong>Mis Pedidos</strong> del cliente, y el modal de abonos se prellena con la cuota que falta pagar.</li>
                    </ul>
                </div>
            </div>

        </div>
    `;
    document.body.appendChild(modalDiv);
}

/**
 * Genera y descarga el PDF del Manual para Clientes usando jsPDF
 */
function descargarManualClientePDF() {
    if (!window.jspdf) {
        alert("Cargando motor PDF, por favor intente en un instante.");
        return;
    }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    // Colores del Manual
    const primaryColor = [67, 97, 238];
    const textColor = [33, 37, 41];
    const lightGray = [240, 243, 248];

    // Encabezado
    doc.setFillColor(...primaryColor);
    doc.rect(0, 0, 210, 30, 'F');

    doc.setFont("helvetica", "bold");
    doc.setFontSize(16);
    doc.setTextColor(255, 255, 255);
    doc.text("MANUAL DE USUARIO - GUÍA DEL CLIENTE", 15, 18);

    doc.setFontSize(9);
    doc.setFont("helvetica", "normal");
    doc.text("Plataforma de Comercio Electrónico | Guía Oficial", 15, 25);

    let y = 42;

    function agregarSeccion(titulo, items) {
        if (y > 260) {
            doc.addPage();
            y = 25;
        }

        doc.setFillColor(...lightGray);
        doc.roundedRect(15, y - 5, 180, 10, 2, 2, 'F');

        doc.setFont("helvetica", "bold");
        doc.setFontSize(11);
        doc.setTextColor(...primaryColor);
        doc.text(titulo, 18, y + 1);

        y += 12;

        doc.setFont("helvetica", "normal");
        doc.setFontSize(9.5);
        doc.setTextColor(...textColor);

        items.forEach(item => {
            if (y > 270) {
                doc.addPage();
                y = 25;
            }
            const lines = doc.splitTextToSize(`• ${item}`, 170);
            doc.text(lines, 20, y);
            y += lines.length * 5 + 2;
        });

        y += 4;
    }

    agregarSeccion("1. Búsqueda y Selección de Productos", [
        "Ingresa a la tienda principal y utiliza el buscador en la parte superior para encontrar tus productos deseados.",
        "Puedes filtrar los artículos por categorías o por orden de precio.",
        "Haz clic sobre cualquier producto para consultar su ficha técnica, especificaciones completas e imágenes secundarias.",
        "Guarda tus productos preferidos haciendo clic en el icono de corazón para agregarlos a tus Favoritos."
    ]);

    agregarSeccion("2. Carrito de Compras y Tasa Oficial BCV", [
        "Agrega los productos seleccionados al carrito haciendo clic en 'Agregar al Carrito'.",
        "El sistema calcula el total de tu pedido simultáneamente en Dólares ($) y en Bolívares (Bs) según la Tasa Oficial del BCV del día.",
        "Puedes modificar las cantidades o eliminar productos antes de proceder a la pantalla de pago."
    ]);

    agregarSeccion("3. Proceso de Pago en Bolívares y Divisas", [
        "Al presionar 'Finalizar compra', se desplegará el módulo interactivo de pago.",
        "Selecciona tu opción preferida: Pago Móvil, Transferencia Bancaria o Zelle.",
        "El sistema te proporcionará los datos de la cuenta destino (Teléfono, Cédula/RIF, Banco o Correo Zelle).",
        "Realiza la transferencia en tu banco y toma una foto o captura del comprobante emitido."
    ]);

    agregarSeccion("4. Subida de Comprobante y Estado del Pedido", [
        "Accede a tu panel personal en 'Mi Cuenta' -> 'Mis Pedidos'.",
        "Ubica la orden realizada y presiona el botón 'Subir Comprobante'.",
        "Adjunta la captura del pago en formato JPG, PNG o PDF e ingresa la referencia de la transacción.",
        "El estado de tu pedido cambiará a 'Pago Subido' y será verificado a la brevedad por el administrador."
    ]);

    // Pie de página
    const totalPages = doc.internal.getNumberOfPages();
    for (let i = 1; i <= totalPages; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(120, 120, 120);
        doc.text(`Manual del Cliente - Página ${i} de ${totalPages}`, 105, 287, { align: "center" });
    }

    doc.save("Manual_Usuario_Cliente.pdf");
}

/**
 * Genera y descarga el PDF del Manual para Administradores usando jsPDF
 */
function descargarManualAdminPDF() {
    if (!window.jspdf) {
        alert("Cargando motor PDF, por favor intente en un instante.");
        return;
    }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    const primaryColor = [247, 37, 133];
    const darkHeader = [30, 41, 59];
    const textColor = [33, 37, 41];
    const lightGray = [241, 245, 249];

    // Encabezado Admin
    doc.setFillColor(...darkHeader);
    doc.rect(0, 0, 210, 32, 'F');

    doc.setFont("helvetica", "bold");
    doc.setFontSize(16);
    doc.setTextColor(255, 255, 255);
    doc.text("MANUAL DE ADMINISTRACIÓN DEL SISTEMA", 15, 18);

    doc.setFontSize(9);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(200, 210, 230);
    doc.text("Guía Operativa para Control de Inventario, Tasas, Pagos y Respaldos BD", 15, 26);

    let y = 44;

    function agregarSeccionAdmin(titulo, items) {
        if (y > 260) {
            doc.addPage();
            y = 25;
        }

        doc.setFillColor(...lightGray);
        doc.roundedRect(15, y - 5, 180, 10, 2, 2, 'F');

        doc.setFont("helvetica", "bold");
        doc.setFontSize(11);
        doc.setTextColor(...primaryColor);
        doc.text(titulo, 18, y + 1);

        y += 12;

        doc.setFont("helvetica", "normal");
        doc.setFontSize(9.5);
        doc.setTextColor(...textColor);

        items.forEach(item => {
            if (y > 270) {
                doc.addPage();
                y = 25;
            }
            const lines = doc.splitTextToSize(`• ${item}`, 170);
            doc.text(lines, 20, y);
            y += lines.length * 5 + 2;
        });

        y += 4;
    }

    agregarSeccionAdmin("1. Publicación de Productos e Inventario", [
        "Ingresa al módulo 'Publicar' para registrar nuevos productos en la tienda.",
        "Ingresa Nombre, Precio en USD, Categoría, Ficha Técnica e Imágenes (la primera será la imagen principal y puedes agregar varias).",
        "Define el 'Stock Mínimo' de alerta. El sistema monitorea en tiempo real e indica en el panel cuando un artículo requiere reposición.",
        "Desde la tabla de Inventario puedes editar datos o despublicar cualquier producto sin eliminar el historial de ventas."
    ]);

    agregarSeccionAdmin("2. Compras a Proveedores y Abastecimiento", [
        "Para sumar inventario recibido, accede a la sección 'Compras'.",
        "Selecciona el proveedor registrado y especifica las unidades compradas con su costo unitario.",
        "El stock del producto se actualizará de forma transparente en el inventario activo.",
        "Compras, Proveedores, Clientes, Historial de Clientes y Cierre de Caja incluyen filtros de búsqueda por rango de fechas y de horas, además del texto libre."
    ]);

    agregarSeccionAdmin("3. Control de Tasa Oficial (BCV) y Datos Bancarios", [
        "En la pestaña 'Tasa del Día', actualiza la tasa oficial BCV ($1 USD = X Bs). Todos los precios en Bolívares de la tienda se recalculan al instante.",
        "En la sección 'Mi Cuenta', actualiza los datos bancarios visibles a los clientes: Pago Móvil (Teléfono, Cédula, Banco) y Cuenta Zelle."
    ]);

    agregarSeccionAdmin("4. Verificación y Aprobación de Pagos de Clientes", [
        "Accede al módulo 'Pagos Pendientes'. Se mostrará un badge rojo con la cantidad de pagos por revisar.",
        "Inspecciona el comprobante adjuntado por el cliente y verifica la referencia con tu estado de cuenta bancario.",
        "Haz clic en 'Aprobar Pago' para confirmar la compra y descontar automáticamente las unidades del inventario.",
        "En caso de inconsistencias, presiona 'Rechazar Pago' para notificar al cliente el motivo."
    ]);

    agregarSeccionAdmin("5. Reportes Financieros y Seguridad (Respaldos SQL)", [
        "Revisa el resumen ejecutivo con Ingresos Totales, Ganancia Neta, Margen de Ganancia y Valor Total del Inventario.",
        "Accede al módulo 'Seguridad / BD' para realizar copias de seguridad de la base de datos.",
        "Presiona 'Descargar Respaldo BD' para obtener un archivo .sql con toda la información de productos, clientes y ventas."
    ]);

    agregarSeccionAdmin("6. Configuración de Apartados (cuotas y plazo)", [
        "En 'Mi Cuenta' se encuentra la sección 'Configuración de Apartados'.",
        "Se define cuántas cuotas paga el cliente (1 a 12) y el límite de pago en días (1 a 90), y se guarda la configuración.",
        "La cuota sugerida es el total del apartado dividido entre el número de cuotas.",
        "Los apartados nuevos toman las reglas vigentes; los existentes conservan su plan. El plan aparece en el historial, en Mis Pedidos y en el modal de abonos."
    ]);

    // Pie de página
    const totalPages = doc.internal.getNumberOfPages();
    for (let i = 1; i <= totalPages; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(120, 120, 120);
        doc.text(`Manual de Administración - Página ${i} de ${totalPages}`, 105, 287, { align: "center" });
    }

    doc.save("Manual_Usuario_Administrador.pdf");
}
