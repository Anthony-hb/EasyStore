// ==================== LÓGICA ADMINISTRATIVA ====================

let productos = [];
let tasaBCV = 1;
let proveedoresData = [];
let productosPedidoProveedor = [];

function iniciarFormularioCompra() {
    cargarProveedores();
    cargarProductosParaCompras();
    cargarCompras();
}

function cargarCompras() {
    const tbody = document.getElementById('comprasHistorialBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#aaa; padding:20px;">Cargando...</td></tr>';
    fetch('get_compras.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#aaa; padding:20px;">No hay compras registradas.</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(c => {
                const fecha = c.fecha_compra ? new Date(c.fecha_compra).toLocaleString('es-VE', {dateStyle:'short', timeStyle:'short'}) : '—';
                const estadoColor = c.estado === 'Recibido' ? '#16a34a' : c.estado === 'Anulado' ? '#dc2626' : '#d97706';
                return `<tr data-fecha="${c.fecha_compra || ''}">
                    <td>#${c.id}</td>
                    <td><strong>${c.proveedor_nombre || '—'}</strong></td>
                    <td>${c.numero_factura || '—'}</td>
                    <td>${fecha}</td>
                    <td style="font-size:0.88rem; max-width:200px; word-break:break-word;">${c.productos || '—'}</td>
                    <td><strong>$${parseFloat(c.total_usd).toFixed(2)}</strong></td>
                </tr>`;
            }).join('');
            // Se reaplican los filtros de texto/fecha vigentes sobre las filas nuevas.
            aplicarFiltrosTabla('comprasHistorialBody');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:red;">Error al cargar el historial de compras.</td></tr>';
        });
}

function actualizarDatalistProductos() {
    const list = document.getElementById('productosList');
    if (list) {
        list.innerHTML = '';
        const nombres = [...new Set(productos.map(p => p.nombre).filter(Boolean))];
        nombres.forEach(nombre => {
            const option = document.createElement('option');
            option.value = nombre;
            list.appendChild(option);
        });
    }

    // El select de publicar producto NO se llena aqui: ahora depende del proveedor
    // elegido (ver cargarProductosDelProveedor), para no traer todo el catalogo.
}

// ==================== PUBLICAR PRODUCTO: Proveedor > Producto > Marca ====================
// El formulario va en cascada para cargar solo lo necesario y no repetir datos:
//   1. Se elige el proveedor.
//   2. El campo Producto se llena con lo que se le compro a ESE proveedor.
//   3. Al elegir el producto, el campo Marca se llena con las marcas con las que
//      se le compro a ese proveedor.
// Los resultados se guardan en cache para no volver a pedirlos en cada visita.

let cacheProductosPorProveedor = {};
let productosProveedorActual = [];
let cargandoProductosProveedor = 0;

function etiquetaProveedor(p) {
    const nombre = [p.nombre, p.apellido].filter(Boolean).join(' ').trim();
    return p.nombre_negocio + (nombre ? ' - ' + nombre : '');
}

// Llena el select de proveedor del formulario de publicacion.
function poblarSelectProveedorProducto() {
    const select = document.getElementById('proveedorProd');
    if (!select) return;

    const actual = select.value;
    if (!proveedoresData.length) {
        select.innerHTML = '<option value="">-- Sin proveedores --</option>';
        return;
    }

    // value= es un atributo: escapar con escaparAtributo, que si escapa comillas.
    select.innerHTML = '<option value="">Selecciona un proveedor</option>' + proveedoresData
        .map(p => `<option value="${escaparAtributo(p.id)}">${escaparHTML(etiquetaProveedor(p))}</option>`)
        .join('');

    if (actual && proveedoresData.some(p => String(p.id) === String(actual))) {
        select.value = actual;
    }
}

// Descarga los productos de un proveedor (con cache) y llena el select Producto.
function cargarProductosDelProveedor(proveedorId) {
    const selectProducto = document.getElementById('nombreProd');
    const info = document.getElementById('infoProductosProveedor');

    if (!selectProducto) return Promise.resolve([]);

    const limpiar = () => {
        productosProveedorActual = [];
        if (info) { info.textContent = ''; info.style.display = 'none'; }
        selectProducto.innerHTML = '<option value="">Elige primero un proveedor</option>';
    };

    if (!proveedorId || Number(proveedorId) <= 0) {
        limpiar();
        return Promise.resolve([]);
    }

    // Si ya se habia pedido este proveedor, se usa la copia guardada.
    if (cacheProductosPorProveedor[proveedorId]) {
        const cached = cacheProductosPorProveedor[proveedorId];
        productosProveedorActual = cached;
        pintarProductosDelProveedor(cached);
        return Promise.resolve(cached);
    }

    const token = ++cargandoProductosProveedor;
    selectProducto.innerHTML = '<option value="">Cargando productos...</option>';

    return fetch('get_productos_proveedor.php?proveedor_id=' + encodeURIComponent(proveedorId))
        .then(res => res.json())
        .then(data => {
            // Si el usuario cambio de proveedor mientras cargaba, se descarta.
            if (token !== cargandoProductosProveedor) return [];

            if (!data.success) {
                limpiar();
                mostrarNotificacion(data.error || 'Error al cargar los productos del proveedor', true);
                return [];
            }

            cacheProductosPorProveedor[proveedorId] = data.productos || [];
            productosProveedorActual = cacheProductosPorProveedor[proveedorId];
            pintarProductosDelProveedor(productosProveedorActual);
            return productosProveedorActual;
        })
        .catch(err => {
            console.error('Error cargando productos del proveedor:', err);
            if (token === cargandoProductosProveedor) {
                limpiar();
                mostrarNotificacion('Error de red al cargar los productos del proveedor', true);
            }
            return [];
        });
}

function pintarProductosDelProveedor(lista) {
    const selectProducto = document.getElementById('nombreProd');
    const info = document.getElementById('infoProductosProveedor');
    if (!selectProducto) return;

    if (!lista.length) {
        selectProducto.innerHTML = '<option value="">-- Este proveedor no tiene compras --</option>';
        if (info) {
            info.style.display = 'block';
            info.textContent = 'A este proveedor todavia no se le ha comprado ningun producto. Registra una compra primero.';
        }
        actualizarMarcasProductoPublicar();
        return;
    }

    selectProducto.innerHTML = '<option value="">Selecciona un producto</option>' + lista
        .map(p => {
            const etiqueta = p.nombre + (p.publicado ? ' (ya publicado)' : '');
            return `<option value="${escaparAtributo(p.nombre)}">${escaparHTML(etiqueta)}</option>`;
        })
        .join('');

    if (info) {
        const pendientes = lista.filter(p => !p.publicado).length;
        info.style.display = 'block';
        info.innerHTML = pendientes > 0
            ? `📦 ${lista.length} producto(s) comprados a este proveedor · <strong>${pendientes}</strong> sin publicar`
            : `📦 ${lista.length} producto(s) comprados a este proveedor · todos ya están publicados`;
    }

    actualizarMarcasProductoPublicar();
}

// Al cambiar el proveedor se recargan los productos y se limpian los campos.
function onProveedorProductoChanged() {
    const proveedorId = document.getElementById('proveedorProd')?.value || '';
    const form = document.getElementById('adminForm');
    if (form) delete form.dataset.editId;

    // Se vacia la lista actual antes de limpiar, para que el campo Marca no
    // siga mostrando las marcas del producto del proveedor anterior.
    productosProveedorActual = [];
    limpiarCamposPublicacion();
    return cargarProductosDelProveedor(proveedorId);
}

// Vacia los datos del producto para que no queden los del proveedor anterior.
function limpiarCamposPublicacion() {
    const ids = ['categoriaProd', 'subcategoriaProd', 'precioCompraProd', 'precioProd',
                 'stockMinProd', 'imagenProd', 'descProd', 'fichaProd'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const ivaSel = document.getElementById('ivaProd');
    if (ivaSel) ivaSel.value = '16';
    const stock = document.getElementById('stockProd');
    if (stock) stock.value = '0';

    const avisoImagen = document.getElementById('previewMainImg');
    if (avisoImagen) {
        avisoImagen.innerHTML = '';
        avisoImagen.style.display = 'none';
    }
    const avisoStatus = document.getElementById('imgPreviewMainStatus');
    if (avisoStatus) {
        avisoStatus.innerHTML = '';
        avisoStatus.style.display = 'none';
    }
    const archivoImagen = document.getElementById('imagenProdFile');
    if (archivoImagen) archivoImagen.value = '';

    actualizarMarcasProductoPublicar();
}

// Opciones del select de marca del producto a publicar.
// Vienen de las marcas con las que se le compro a ESE proveedor.
function actualizarMarcasProductoPublicar(marcaPreferida) {
    const select = document.getElementById('marcaProd');
    const info = document.getElementById('infoMarcasProducto');
    if (!select) return;

    const nombreProducto = document.getElementById('nombreProd')?.value || '';
    const producto = productosProveedorActual.find(p => p.nombre === nombreProducto);

    if (!producto) {
        select.innerHTML = '<option value="">Elige primero un producto</option>';
        if (info) { info.style.display = 'none'; info.textContent = ''; }
        return;
    }

    const marcas = Array.isArray(producto.marcas) ? producto.marcas.filter(Boolean) : [];

    // La marca ya guardada en el producto tiene prioridad: es la que tiene ahora.
    const guardada = String(producto.marca || '').trim();
    const preferida = String(marcaPreferida || '').trim();

    let orden = [...marcas];
    for (const m of [guardada, preferida]) {
        if (!m) continue;
        if (orden.some(x => x.toLowerCase() === m.toLowerCase())) continue;
        orden.unshift(m);
    }

    let html = '<option value="">-- Sin marca --</option>';
    orden.forEach(m => {
        html += `<option value="${escaparAtributo(m)}">${escaparHTML(m)}</option>`;
    });
    // Siempre se puede escribir una marca que todavia no se ha comprado.
    html += '<option value="__otra__">+ Otra marca...</option>';
    select.innerHTML = html;

    // Se preselecciona: la marca guardada, o la de la ultima compra.
    let elegida = guardada;
    if (!elegida && preferida) elegida = preferida;
    if (!elegida && orden.length) elegida = orden[0];
    if (elegida) {
        const existe = orden.find(x => x.toLowerCase() === elegida.toLowerCase());
        select.value = existe || elegida;
    } else {
        select.value = '';
    }

    if (info) {
        if (marcas.length) {
            info.style.display = 'block';
            info.innerHTML = `🏷️ Marcas con las que se le compró este producto: <strong>${escaparHTML(marcas.join(', '))}</strong>`;
        } else {
            info.style.display = 'block';
            info.textContent = '🏷️ Este producto se compró sin marca registrada. Puedes escribir una nueva.';
        }
    }
}

// Al elegir "+ Otra marca..." se pide el nombre y queda como opcion.
function onMarcaProductoSelected() {
    const select = document.getElementById('marcaProd');
    if (!select || select.value !== '__otra__') return;

    const marca = window.prompt('Nombre de la nueva marca:');
    if (marca === null) {
        select.value = '';
        return;
    }

    const limpia = marca.trim().slice(0, 100);
    if (limpia === '') {
        select.value = '';
        return;
    }

    // Si ya estaba en la lista solo se selecciona; si no, se agrega como opcion.
    const existe = Array.from(select.options).find(o => o.value.toLowerCase() === limpia.toLowerCase());
    if (existe) {
        select.value = existe.value;
    } else {
        const nueva = document.createElement('option');
        nueva.value = limpia;
        nueva.textContent = limpia;
        select.insertBefore(nueva, Array.from(select.options).find(o => o.value === '__otra__'));
        select.value = limpia;
    }
}

function onProductoNuevoSelected() {
    const select = document.getElementById('nombreProd');
    if (!select) return;
    const nombre = select.value;
    if (!nombre) {
        actualizarMarcasProductoPublicar();
        return;
    }

    // Se busca primero en lo cargado del proveedor (trae datos actualizados) y
    // si no, en el catalogo general.
    const producto = productosProveedorActual.find(p => p.nombre === nombre)
        || productos.find(p => p.nombre === nombre);
    if (!producto) {
        actualizarMarcasProductoPublicar();
        return;
    }

    const form = document.getElementById('adminForm');
    if (form && producto.id) {
        form.dataset.editId = producto.id;
    }

    const setValue = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value;
    };

    setValue('categoriaProd', producto.categoria || '');
    setValue('subcategoriaProd', producto.subcategoria || '');
    setValue('precioCompraProd', parseFloat(producto.precio_compra || 0).toFixed(2));
    setValue('precioProd', parseFloat(producto.precio_usd || 0).toFixed(2));
    seleccionarIvaProducto(producto.iva_alicuota ?? 16);
    setValue('stockProd', producto.stock || 0);
    setValue('stockMinProd', producto.stock_minimo || 0);
    const imagenesProducto = imagenesGuardadasProducto(producto);
    setValue('imagenProd', imagenesProducto.join(', '));
    setValue('descProd', producto.descripcion || '');
    setValue('fichaProd', producto.ficha_tecnica || '');
    // La marca se despliega al final, cuando ya se sabe el producto.
    actualizarMarcasProductoPublicar();
    calcularMargenGananciaForm();
}

// Descarta la cache de productos por proveedor (tras crear, editar o borrar uno).
function invalidarCacheProductosProveedor() {
    cacheProductosPorProveedor = {};
    productosProveedorActual = [];
    // El selector de productos del Kardex tambien queda desactualizado.
    productosKardexCargados = false;
}

function cargarProductosParaCompras() {
    return fetch('get_productos_admin.php')
        .then(res => res.json())
        .then(data => {
            productos = Array.isArray(data) ? data : [];
            actualizarDatalistProductos();
            return productos;
        })
        .catch(err => {
            console.error('Error cargando productos para compras:', err);
            const detalles = document.getElementById('compraDetallesBody');
            if (detalles) detalles.innerHTML = '<tr><td colspan="6" style="text-align:center; color:red;">Error al cargar productos.</td></tr>';
            return [];
        });
}

function resetCompraForm() {
    const proveedorSelect = document.getElementById('selectProveedor');
    if (proveedorSelect) proveedorSelect.value = '';
    const facturaInput = document.getElementById('inputFactura');
    if (facturaInput) facturaInput.value = '';
    const fechaCompraInput = document.getElementById('inputFechaCompra');
    if (fechaCompraInput) {
        const ahora = new Date();
        const tzoffset = ahora.getTimezoneOffset() * 60000;
        const localISOTime = (new Date(ahora.getTime() - tzoffset)).toISOString().slice(0, 16);
        fechaCompraInput.value = localISOTime;
    }
    const detalles = document.getElementById('compraDetallesBody');
    if (detalles) {
        detalles.innerHTML = '<tr id="compraDetallesVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>';
    }
    const infoMarcas = document.getElementById('infoMarcasProveedor');
    if (infoMarcas) {
        infoMarcas.style.display = 'none';
        infoMarcas.textContent = '';
    }
    const totalDisplay = document.getElementById('compraTotalDisplay');
    if (totalDisplay) totalDisplay.textContent = '$0.00 USD';
    const igtfInput = document.getElementById('inputCompraIgtf');
    if (igtfInput) igtfInput.value = '0.00';
}


function abrirModalCompra() {
    resetCompraForm();
    const modal = document.getElementById('modalCompra');
    if (modal) modal.style.display = 'flex';

    const proveedorSelect = document.getElementById('selectProveedor');
    if (proveedorSelect) proveedorSelect.innerHTML = '<option value="">Cargando proveedores...</option>';

    cargarProductosParaCompras().catch(err => {
        console.error('Error cargando productos para compras:', err);
        mostrarNotificacion('No se pudieron cargar los productos al abrir el formulario', true);
    });

    cargarProveedores().catch(err => {
        console.error('Error cargando proveedores:', err);
        mostrarNotificacion('No se pudieron cargar los proveedores al abrir el formulario', true);
    });
}

function cerrarModalCompra() {
    const modal = document.getElementById('modalCompra');
    if (modal) modal.style.display = 'none';
}

function abrirModalDetalleCompra() {
    // Replaced by inline agregarFilaDetalleCompra to avoid nested modals
    agregarFilaDetalleCompra();
}

function cerrarModalDetalleCompra() {
    const modal = document.getElementById('modalDetalleCompra');
    if (modal) modal.style.display = 'none';
}

function actualizarProductoModal(selectElement) {
    const productoId = parseInt(selectElement.value || 0, 10);
    const producto = productos.find(p => p.id === productoId);
    if (!producto) {
        document.getElementById('detalleNombre').value = '';
        document.getElementById('detallePrecio').value = '0.00';
        return;
    }
    document.getElementById('detalleNombre').value = producto.nombre;
    document.getElementById('detallePrecio').value = parseFloat(producto.precio_compra || 0).toFixed(2);
}

function guardarDetalleCompraModal() {
    const select = document.getElementById('detalleProductoSelect');
    const productoId = parseInt(select?.value || 0, 10);
    const cantidad = parseInt(document.getElementById('detalleCantidad')?.value || 0, 10);
    const precio = parseFloat(document.getElementById('detallePrecio')?.value || 0);

    if (!productoId) {
        mostrarNotificacion('Selecciona un producto', true);
        return;
    }
    if (!cantidad || cantidad < 1) {
        mostrarNotificacion('Ingresa una cantidad válida', true);
        return;
    }
    if (precio < 0) {
        mostrarNotificacion('Ingresa un precio válido', true);
        return;
    }

    const producto = productos.find(p => p.id === productoId);
    const nombre = producto?.nombre || '';
    insertarFilaDetalleCompra(productoId, nombre, cantidad, precio, '');
    cerrarModalDetalleCompra();
}

// Devuelve el proveedor seleccionado en el formulario de compras.
function getProveedorCompraSeleccionado() {
    const id = parseInt(document.getElementById('selectProveedor')?.value || 0, 10);
    return proveedoresData.find(p => Number(p.id) === id) || null;
}

// Separa "SanDisk, Kingston, HP" en ["SanDisk", "Kingston", "HP"].
// Los proveedores guardan las marcas separadas por comas.
function parseMarcasProveedor(texto) {
    return String(texto || '')
        .split(',')
        .map(m => m.trim())
        .filter(m => m !== '');
}

// Opciones del <select> de marca de una linea de compra.
// Siempre incluye "Otra / nueva marca" para poder escribir una que aun no este
// en la ficha del proveedor.
function opcionesSelectMarca(marcaSeleccionada) {
    const marcas = parseMarcasProveedor(getProveedorCompraSeleccionado()?.marca_producto);
    let html = '<option value="">-- Sin marca --</option>';
    marcas.forEach(m => {
        const sel = (String(marcaSeleccionada || '').trim().toLowerCase() === m.toLowerCase()) ? ' selected' : '';
        // value= es un atributo: usar escaparAtributo, que si escapa las comillas.
        html += `<option value="${escaparAtributo(m)}"${sel}>${escaparHTML(m)}</option>`;
    });
    // Una marca escrita a mano no esta todavia en la ficha del proveedor, pero
    // debe aparecer como opcion para que el select no se quede en blanco.
    const elegida = String(marcaSeleccionada || '').trim();
    if (elegida && !marcas.some(m => m.toLowerCase() === elegida.toLowerCase())) {
        html += `<option value="${escaparAtributo(elegida)}" selected>${escaparHTML(elegida)}</option>`;
    }

    html += '<option value="__otra__">+ Otra marca...</option>';
    return html;
}

// Muestra las marcas del proveedor elegido y refresca las lineas ya agregadas.
function actualizarMarcasDelDetalleCompra() {
    const proveedor = getProveedorCompraSeleccionado();
    const info = document.getElementById('infoMarcasProveedor');

    if (info) {
        const marcas = proveedor ? parseMarcasProveedor(proveedor.marca_producto) : [];
        if (!proveedor) {
            info.style.display = 'none';
            info.textContent = '';
        } else if (marcas.length) {
            info.style.display = 'block';
            info.innerHTML = `🏷️ Marcas de este proveedor: <strong>${escaparHTML(marcas.join(', '))}</strong>`;
        } else {
            info.style.display = 'block';
            info.textContent = '🏷️ Este proveedor no tiene marcas registradas. Puedes escribir una nueva al agregar el producto.';
        }
    }

    document.querySelectorAll('#compraDetallesBody .detalle-marca').forEach(select => {
        const anterior = select.value;
        select.innerHTML = opcionesSelectMarca(anterior === '__otra__' ? '' : anterior);
    });
}

// Al elegir "+ Otra marca..." se pide el nombre y se deja como opcion nueva
// para el resto de la compra.
function agregarMarcaPersonalizada(select) {
    const marca = window.prompt('Nombre de la nueva marca:');
    if (marca === null) {
        select.value = '';
        return;
    }

    const limpia = marca.trim();
    if (limpia === '') {
        select.value = '';
        return;
    }

    // Si la marca ya viene en la ficha del proveedor, solo se selecciona.
    const marcas = parseMarcasProveedor(getProveedorCompraSeleccionado()?.marca_producto);
    const existente = marcas.find(m => m.toLowerCase() === limpia.toLowerCase());
    select.innerHTML = opcionesSelectMarca(existente || limpia);
    select.value = existente || limpia;

    // Se agrega tambien al resto de las lineas de esta misma compra.
    document.querySelectorAll('#compraDetallesBody .detalle-marca').forEach(otro => {
        if (otro === select) return;
        const yaEsta = Array.from(otro.options).some(o => o.value.toLowerCase() === limpia.toLowerCase());
        if (yaEsta) {
            otro.value = Array.from(otro.options).find(o => o.value.toLowerCase() === limpia.toLowerCase()).value;
        } else {
            const nueva = document.createElement('option');
            nueva.value = limpia;
            nueva.textContent = limpia;
            otro.appendChild(nueva);
        }
    });
}

function insertarFilaDetalleCompra(productoId, nombre, cantidad, precio, marca) {
    const tbody = document.getElementById('compraDetallesBody');
    if (!tbody) return;
    // Ocultar fila vacía placeholder
    const vaciRow = document.getElementById('compraDetallesVacio');
    if (vaciRow) vaciRow.style.display = 'none';
    const subtotal = (cantidad * precio).toFixed(2);
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="detalle-producto" placeholder="Nombre del producto" value="${nombre}" style="width:160px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarDetalleCompra(this)" /></td>
        <td><select class="detalle-marca" onchange="if (this.value === '__otra__') agregarMarcaPersonalizada(this)" style="width:130px; padding:6px; border:1px solid #ddd; border-radius:6px; background:#fff;">${opcionesSelectMarca(marca)}</select></td>
        <td><input type="number" class="detalle-cantidad" min="1" value="${cantidad}" style="width:70px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalCompra(this)" /></td>
        <td><input type="number" class="detalle-precio" min="0" step="0.01" value="${precio.toFixed(2)}" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalCompra(this)" /></td>
        <td class="detalle-subtotal" style="font-weight:600; color:#2563eb; padding:6px;">$${subtotal}</td>
        <td><button type="button" class="btn-secondary" style="padding:6px 10px; font-size:0.82rem; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;" onclick="quitarFilaDetalleCompra(this)">✕</button></td>
    `;
    tbody.appendChild(tr);
    recalcularTotalCompra();
}

function agregarFilaDetalleCompra() {
    const tbody = document.getElementById('compraDetallesBody');
    if (!tbody) return;

    // Ocultar fila vacía placeholder
    const vaciRow = document.getElementById('compraDetallesVacio');
    if (vaciRow) vaciRow.style.display = 'none';

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="detalle-producto" list="productosList" placeholder="Nombre del producto" style="width:160px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarDetalleCompra(this)" /></td>
        <td><select class="detalle-marca" onchange="if (this.value === '__otra__') agregarMarcaPersonalizada(this)" style="width:130px; padding:6px; border:1px solid #ddd; border-radius:6px; background:#fff;">${opcionesSelectMarca('')}</select></td>
        <td><input type="number" class="detalle-cantidad" min="1" value="1" style="width:70px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalCompra(this)" /></td>
        <td><input type="number" class="detalle-precio" min="0" step="0.01" value="0.00" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalCompra(this)" /></td>
        <td class="detalle-subtotal" style="font-weight:600; color:#2563eb; padding:6px;">$0.00</td>
        <td><button type="button" class="btn-secondary" style="padding:6px 10px; font-size:0.82rem; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;" onclick="quitarFilaDetalleCompra(this)">✕</button></td>
    `;

    tbody.appendChild(tr);
    recalcularTotalCompra();
}

function actualizarDetalleCompra(inputElement) {
    const tr = inputElement.closest('tr');
    if (!tr) return;

    const productoInput = tr.querySelector('.detalle-producto');
    const precioInput = tr.querySelector('.detalle-precio');

    if (!productoInput || !precioInput) return;

    const nombre = productoInput.value.trim();

    if (!nombre) {
        precioInput.value = '0.00';
        actualizarSubtotalCompra(inputElement);
        return;
    }

    const producto = productos.find(p => String(p.nombre || '').trim().toLowerCase() === nombre.toLowerCase());

    if (producto) {
        productoInput.value = producto.nombre;
        if (precioInput.value === '0.00' || precioInput.value === '') {
            precioInput.value = parseFloat(producto.precio_compra || 0).toFixed(2);
        }
    }

    actualizarSubtotalCompra(inputElement);
}

function actualizarSubtotalCompra(inputElement) {
    const tr = inputElement.closest('tr');
    if (!tr) return;
    const cantidad = parseFloat(tr.querySelector('.detalle-cantidad')?.value || 0);
    const precio = parseFloat(tr.querySelector('.detalle-precio')?.value || 0);
    const subtotalCell = tr.querySelector('.detalle-subtotal');
    if (subtotalCell) subtotalCell.textContent = `$${(cantidad * precio).toFixed(2)}`;
    recalcularTotalCompra();
}

function recalcularTotalCompra() {
    const rows = Array.from(document.querySelectorAll('#compraDetallesBody tr:not(#compraDetallesVacio)'));
    const total = rows.reduce((sum, row) => {
        const cant = parseFloat(row.querySelector('.detalle-cantidad')?.value || 0);
        const prec = parseFloat(row.querySelector('.detalle-precio')?.value || 0);
        return sum + (cant * prec);
    }, 0);
    const display = document.getElementById('compraTotalDisplay');
    if (display) display.textContent = `$${total.toFixed(2)} USD`;
}

function quitarFilaDetalleCompra(button) {
    const tr = button.closest('tr');
    if (tr) tr.remove();
    // Mostrar fila vacía si no quedan productos
    const tbody = document.getElementById('compraDetallesBody');
    if (tbody && tbody.querySelectorAll('tr:not(#compraDetallesVacio)').length === 0) {
        const vaciRow = document.getElementById('compraDetallesVacio');
        if (vaciRow) vaciRow.style.display = '';
    }
    recalcularTotalCompra();
}

function enviarCompra() {
    const proveedorId = parseInt(document.getElementById('selectProveedor')?.value || 0, 10);
    const numeroFactura = document.getElementById('inputFactura')?.value.trim();
    const fechaCompra = document.getElementById('inputFechaCompra')?.value || null;

    if (!proveedorId) {
        mostrarNotificacion('Selecciona un proveedor antes de registrar la compra', true);
        return;
    }

    const rows = Array.from(document.querySelectorAll('#compraDetallesBody tr:not(#compraDetallesVacio)'));
    const detalles = rows.map(row => {
        const inputProducto = row.querySelector('.detalle-producto');
        const nombre = inputProducto?.value?.trim() || '';
        const marcaValor = row.querySelector('.detalle-marca')?.value || '';
        const marca = (marcaValor === '__otra__') ? '' : marcaValor.trim();
        const cantidad = parseInt(row.querySelector('.detalle-cantidad')?.value || 0, 10);
        const precio = parseFloat(row.querySelector('.detalle-precio')?.value || 0);

        let productoId = 0;
        if (nombre) {
            const productoPorNombre = productos.find(p => String(p.nombre || '').trim().toLowerCase() === nombre.toLowerCase());
            if (productoPorNombre) productoId = productoPorNombre.id;
        }

        return { producto_id: productoId, nombre_producto: nombre, marca_producto: marca, cantidad, precio_unitario: precio };
    }).filter(item => (item.producto_id > 0 || item.nombre_producto) && item.cantidad > 0 && item.precio_unitario >= 0);

    if (detalles.length === 0) {
        mostrarNotificacion('Agrega al menos un producto válido a la compra');
        return;
    }

    fetch('save_compra.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            proveedor_id: proveedorId,
            numero_factura: numeroFactura,
            fecha_compra: fechaCompra,
            tasa_cambio: tasaBCV,
            igtf_usd: parseFloat(document.getElementById('inputCompraIgtf')?.value || 0) || 0,
            detalles
        })
    })
    .then(async res => {
        const text = await res.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (parseErr) {
            const displayText = text.length > 200 ? text.slice(0, 200) + '...' : text;
            throw new Error('Respuesta inválida del servidor: ' + displayText);
        }
        return result;
    })
    .then(result => {
        if (result.success) {
            mostrarNotificacion('✅ Compra registrada correctamente');
            cerrarModalCompra();
            resetCompraForm();
            // La compra crea productos y marcas nuevas para ese proveedor.
            invalidarCacheProductosProveedor();
            cargarInventario();
            cargarCompras();
            cargarProductosParaCompras();
        } else {
            mostrarNotificacion(result.error || 'Error al registrar la compra', true);
        }
    })
    .catch(err => {
        console.error('Error guardando compra:', err);
        mostrarNotificacion(err.message || 'Error de conexión al registrar la compra', true);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    cargarConfig();
    cargarInventario();
    cargarAlertasStock();
    cargarStats();

    // Verificar badge de pagos pendientes al cargar
    fetch('get_pagos_pendientes.php')
        .then(r => r.json())
        .then(d => { if (d.success) actualizarBadgePagosPendientes(d.data.length); })
        .catch(() => {});
    
    // Inicializar el botón de nuevo producto si existe
    const navBtnNuevo = document.getElementById('adminNavBtnNuevo');
    if (navBtnNuevo) {
        navBtnNuevo.addEventListener('click', () => {
            const form = document.getElementById('adminForm');
            if (form) {
                delete form.dataset.editId;
                form.reset();
                const stockInput = document.getElementById('stockProd');
                if (stockInput) {
                    stockInput.value = '0';
                    stockInput.readOnly = true;
                    stockInput.style.backgroundColor = '#e5e7eb';
                    stockInput.style.cursor = 'not-allowed';
                    stockInput.title = 'El stock inicial se gestiona mediante Compras o Abastecimientos';
                }
                const h2 = document.getElementById('admin-form-title');
                if (h2) h2.innerText = '📦 Publicar nuevo producto';
                const btn = document.querySelector('#adminForm button[type="submit"]');
                if (btn) btn.innerText = 'Confirmar Publicación';
            }
            mostrarSeccionAdmin('nuevo');
        });
    }
});

function cargarConfig() {
    fetch('../api/get_config.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.config.tasa_dolar) {
                tasaBCV = parseFloat(data.config.tasa_dolar);
                actualizarVistasTasa();
            }
        });
}

function mostrarSeccionAdmin(seccion) {
    document.querySelectorAll('.admin-panel-content').forEach(sec => sec.classList.add('hidden'));
    const seccionActiva = document.getElementById(`admin-seccion-${seccion}`);
    if (seccionActiva) seccionActiva.classList.remove('hidden');

    document.querySelectorAll('.admin-nav-btn').forEach(btn => btn.classList.remove('active'));
    const btnActivo = document.querySelector(`.admin-nav-btn[data-section="${seccion}"]`);
    if (btnActivo) btnActivo.classList.add('active');

    // Compras ya no tiene entrada propia en el menú: vive dentro de Proveedores,
    // así que al abrirlo se resalta ese módulo en la barra lateral.
    if (seccion === 'compras') {
        const btnProveedores = document.querySelector('.admin-nav-btn[data-section="proveedores"]');
        if (btnProveedores) btnProveedores.classList.add('active');
    }

    // Panel de Control (dashboard): al entrar se refrescan los KPIs y las alertas
    if (seccion === 'inicio') {
        cargarStats();
        cargarAlertasStock();
    }

    if (seccion === 'inventario') volverAInventarioLista();
    if (seccion === 'nuevo') asegurarProveedoresParaPublicar();
    if (seccion === 'historial') {
        // Al cambiar de seccion se vuelve siempre al indice de clientes:
        // si se habia abierto un detalle, se cierra para no dejarlo colgando.
        if (typeof mostrarVistasHistorial === 'function') {
            mostrarVistasHistorial(false);
            historialDetalle = null;
            historialClienteActual = null;
        }
        cargarIndiceHistorial();
    }
    if (seccion === 'pedidos') cargarPedidos();
    if (seccion === 'clientes') cargarClientes();
    if (seccion === 'pagos') cargarPagosPendientes();
    if (seccion === 'proveedores') cargarProveedores();
    if (seccion === 'compras') volverAComprasLista();
    if (seccion === 'caja') cargarSeccionCaja();
    if (seccion === 'fiscal') cargarLibroIva();
    if (seccion === 'micuenta') cargarPerfilAdmin();
}

// (Proveedores y formulario de compras eliminado - módulo revertido)

// El formulario de publicacion arranca con el select de proveedor: hace falta
// la lista de proveedores aunque el usuario no entre a la seccion de proveedores.
function asegurarProveedoresParaPublicar() {
    if (proveedoresData.length) {
        poblarSelectProveedorProducto();
        return Promise.resolve(proveedoresData);
    }
    return cargarProveedores().then(lista => {
        poblarSelectProveedorProducto();
        return lista;
    });
}

function cargarInventario() {
    console.log("📦 Cargando inventario...");
    fetch('get_productos_admin.php')
    .then(res => {
        if(!res.ok) throw new Error("Error en la respuesta del servidor");
        return res.json();
    })
    .then(data => {
        console.log("✅ Datos recibidos:", data);
        if (Array.isArray(data)) {
            productos = data;
            actualizarDatalistProductos();
            poblacionCategoriasFiltroMatriz(data);
            poblacionProveedoresFiltroMatriz(data);

            const tbody = document.getElementById('inventoryTableBody');
            if (!tbody) {
                console.error("❌ No se encontró el elemento inventoryTableBody");
                return;
            }
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">No hay productos en la base de datos</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(p => {
                const codigoDisplay = p.codigo_producto || p.id;
                const estadoPub = Number(p.publicado);
                
                const costo = parseFloat(p.precio_compra) || 0;
                const venta = parseFloat(p.precio_usd) || 0;
                const ganancia = venta - costo;
                const margenPct = venta > 0 ? ((ganancia / venta) * 100) : 0;
                
                const stockVal = parseInt(p.stock, 10) || 0;
                const stockMin = parseInt(p.stock_minimo, 10) || 5;

                let stockBadge = '';
                if (stockVal <= 0) {
                    stockBadge = `<span style="background:#fef2f2; color:#dc2626; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.8rem;">🔴 0 (Agotado)</span>`;
                } else if (stockVal <= stockMin) {
                    stockBadge = `<span style="background:#fffbe6; color:#d97706; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.8rem;">⚠️ ${stockVal} (Stock Bajo)</span>`;
                } else {
                    stockBadge = `<span style="background:#f0fdf4; color:#16a34a; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.8rem;">🟢 ${stockVal}</span>`;
                }

                let margenBadgeColor = margenPct >= 30 ? '#16a34a' : (margenPct >= 15 ? '#d97706' : '#dc2626');

                let actionsContent = '';
                if (estadoPub === 1) {
                    actionsContent = `
                        <div class="inventory-actions" style="display:flex; gap:5px;">
                            <button class="btn-table-edit" style="background:#eab308; color:black; border:none; padding:4px 8px; border-radius:4px; cursor:pointer;" onclick="togglePublicacion(${p.id}, 2)">Ocultar</button>
                            <button class="btn-table-edit" onclick="abrirEditarProducto(${p.id})" style="padding:4px 8px; border-radius:4px; cursor:pointer;">Editar</button>
                            <button class="btn-table-delete" onclick="eliminarProducto(${p.id})" style="padding:4px 8px; border-radius:4px; cursor:pointer;">Eliminar</button>
                        </div>
                    `;
                } else if (estadoPub === 2) {
                    actionsContent = `
                        <div class="inventory-actions" style="display:flex; gap:5px;">
                            <button class="btn-table-edit" style="background:#22c55e; color:white; border:none; padding:4px 8px; border-radius:4px; cursor:pointer;" onclick="togglePublicacion(${p.id}, 1)">Publicar</button>
                            <button class="btn-table-edit" onclick="abrirEditarProducto(${p.id})" style="padding:4px 8px; border-radius:4px; cursor:pointer;">Editar</button>
                            <button class="btn-table-delete" onclick="eliminarProducto(${p.id})" style="padding:4px 8px; border-radius:4px; cursor:pointer;">Eliminar</button>
                        </div>
                    `;
                } else {
                    actionsContent = `<span style="color:#94a3b8; font-size:0.85rem; font-style:italic;">No publicado</span>`;
                }

                const provNombre = (p.proveedor_nombre || '').toLowerCase();
                return `
                <tr data-id="${p.id}" data-codigo="${(codigoDisplay+'').toLowerCase()}" data-nombre="${(p.nombre+'').toLowerCase()}" data-categoria="${(p.categoria+'').toLowerCase()}" data-stock="${stockVal}" data-precio="${venta}" data-margen="${margenPct}" data-proveedor="${provNombre}">
                    <td><strong>#${p.id}</strong></td>
                    <td><strong>${codigoDisplay}</strong></td>
                    <td>
                        <div style="font-weight:600; display:flex; align-items:center; gap:8px;">${p.nombre}</div>
                        <div style="font-size:0.8rem; color:var(--text-light);">${p.categoria}</div>
                    </td>
                    <td><strong>$${venta.toFixed(2)}</strong></td>
                    <td>$${costo.toFixed(2)}</td>
                    <td><strong style="color:${margenBadgeColor};">${margenPct.toFixed(1)}%</strong></td>
                    <td>${stockBadge}</td>
                    <td>${actionsContent}</td>
                </tr>`;
            }).join('');
            filtrarMatrizInventario();
        }
    })
    .catch(err => {
        console.error("❌ Error cargando inventario:", err);
        const tbody = document.getElementById('inventoryTableBody');
        if(tbody) tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:red;">Error al cargar datos</td></tr>';
    });
}

function crearProductoAdmin(event) {
    event.preventDefault();
    const form = document.getElementById('adminForm');
    const id = form.dataset.editId;
    
    const precioCompraInput = document.getElementById('precioCompraProd');
    // Si la marca quedo en "+ Otra marca..." sin confirmar, se envia vacia.
    const marcaSeleccionada = document.getElementById('marcaProd').value;
    const productoData = {
        id,
        nombre: document.getElementById('nombreProd').value.trim(),
        descripcion: document.getElementById('descProd').value.trim(),
        precio_compra: precioCompraInput ? (parseFloat(precioCompraInput.value) || 0) : 0,
        precio_usd: parseFloat(document.getElementById('precioProd').value) || 0,
        iva_alicuota: parseFloat(document.getElementById('ivaProd')?.value) || 0,
        stock: parseInt(document.getElementById('stockProd').value, 10) || 0,
        stock_minimo: parseInt(document.getElementById('stockMinProd').value, 10) || 0,
        categoria: document.getElementById('categoriaProd').value.trim(),
        subcategoria: document.getElementById('subcategoriaProd').value.trim() || null,
        marca: (marcaSeleccionada === '__otra__' ? '' : marcaSeleccionada).trim() || null,
        imagenes: obtenerImagenesFormulario(),
        ficha_tecnica: document.getElementById('fichaProd').value.trim()
    };

    // La primera imagen cargada es la que se muestra como principal.
    productoData.imagen = productoData.imagenes[0] || '';

    const url = id ? 'update_producto.php' : 'add_producto.php';
    
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(productoData)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(id ? '✅ Producto actualizado' : '✅ Producto agregado');
            form.reset();
            eliminarImagenPrincipal();
            delete form.dataset.editId;
            // El producto ya no esta "sin publicar": se refresca la lista del
            // proveedor para que el estado del select quede al dia.
            invalidarCacheProductosProveedor();
            const stockInput = document.getElementById('stockProd');
            if (stockInput) {
                stockInput.value = '0';
                stockInput.readOnly = true;
                stockInput.style.backgroundColor = '#e5e7eb';
                stockInput.style.cursor = 'not-allowed';
                stockInput.title = 'El stock inicial se gestiona mediante Compras o Abastecimientos';
            }
            mostrarSeccionAdmin('inventario');
        } else {
            alert('❌ Error: ' + (data.error || 'Error desconocido'));
        }
    });
    return false;
}

function abrirEditarProducto(id) {
    const p = productos.find(prod => prod.id === id);
    if (!p) return;

    mostrarSeccionAdmin('nuevo');

    const form = document.getElementById('adminForm');
    form.dataset.editId = p.id;
    document.getElementById('admin-form-title').innerText = '✏️ Editar Producto: ' + p.nombre;
    document.querySelector('#adminForm button[type="submit"]').innerText = 'Guardar Cambios';

    document.getElementById('descProd').value = p.descripcion;
    const precioCompraInput = document.getElementById('precioCompraProd');
    if (precioCompraInput) {
        precioCompraInput.value = p.precio_compra || 0;
    }
    document.getElementById('precioProd').value = p.precio_usd;
    seleccionarIvaProducto(p.iva_alicuota ?? 16);

    // Bloquear la edición del stock
    const stockInput = document.getElementById('stockProd');
    if (stockInput) {
        stockInput.value = p.stock ?? 0;
        stockInput.readOnly = true;
        stockInput.style.backgroundColor = '#e5e7eb';
        stockInput.style.cursor = 'not-allowed';
        stockInput.title = 'El stock está bloqueado y no se puede editar directamente';
    }

    document.getElementById('stockMinProd').value = p.stock_minimo;
    document.getElementById('categoriaProd').value = p.categoria;
    document.getElementById('subcategoriaProd').value = p.subcategoria || '';
    document.getElementById('imagenProd').value = imagenesGuardadasProducto(p).join(', ');
    document.getElementById('fichaProd').value = p.ficha_tecnica || '';
    actualizarPreviewImagenPrincipal();

    // El select de Producto depende del proveedor, asi que primero se elige el
    // proveedor del que se compro este producto y luego se selecciona el producto.
    // p.proveedor_id viene del ultimo proveedor que le compro este producto.
    const selectProveedor = document.getElementById('proveedorProd');
    const proveedorId = p.proveedor_id || '';
    if (selectProveedor) selectProveedor.value = String(proveedorId);

    cargarProductosDelProveedor(proveedorId).then(() => {
        const selectProducto = document.getElementById('nombreProd');
        if (selectProducto && p.nombre) {
            selectProducto.value = p.nombre;
            onProductoNuevoSelected();
        }
    });
}

function togglePublicacion(id, estado) {
    fetch('toggle_publicado.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id, publicado: estado })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            cargarInventario();
        } else {
            alert('❌ Error al actualizar estado de publicación: ' + (data.error || 'Error desconocido'));
        }
    })
    .catch(err => {
        console.error("Error al cambiar estado de publicación:", err);
        alert('❌ Error de conexión al cambiar publicación.');
    });
}

function eliminarProducto(id) {
    if (!confirm('¿Estás seguro de que deseas eliminar este producto?')) return;
    fetch('delete_producto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion(data.message || '✅ Producto eliminado correctamente');
            invalidarCacheProductosProveedor();
            cargarInventario();
            cargarStats();
            cargarAlertasStock();
        } else {
            mostrarNotificacion(data.error || '❌ Error al eliminar el producto', true);
        }
    })
    .catch(err => {
        console.error("Error eliminando producto:", err);
        mostrarNotificacion('❌ Error de conexión al eliminar el producto', true);
    });
}

function actualizarTasaManual() {
    const input = document.getElementById('inputTasa'); 
    const nuevaTasa = parseFloat(input.value);
    if (isNaN(nuevaTasa) || nuevaTasa <= 0) return;

    fetch('update_config.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tasa_dolar: nuevaTasa })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            tasaBCV = nuevaTasa;
            actualizarVistasTasa();
            alert("✅ Tasa actualizada.");
            input.value = ""; 
        }
    });
}

function actualizarVistasTasa() {
    const msgAdmin = document.getElementById('tasaActualMsg');
    if (msgAdmin) msgAdmin.innerText = `Tasa actual: 1 USD = ${tasaBCV.toFixed(2)} Bs`;
}

function cargarAlertasStock() {
    fetch('get_alertas_stock.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.total > 0) {
                const container = document.getElementById('stock-alerts-container');
                const list = document.getElementById('stock-alerts-list');
                
                if (container && list) {
                    list.innerHTML = data.alertas.map(p => `
                        <li>El producto <strong>${p.nombre}</strong> (ID: ${p.id}) tiene un stock de <strong>${p.stock}</strong> (Mínimo: ${p.stock_minimo}).</li>
                    `).join('');
                    container.style.display = 'block';
                }
            } else {
                const container = document.getElementById('stock-alerts-container');
                if (container) container.style.display = 'none';
            }
        })
        .catch(err => console.error("Error cargando alertas de stock:", err));
}

// ==================== NUEVAS FUNCIONES DE REGISTRO ====================

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

function cargarAbastecimientos() {
    fetch('get_abastecimientos.php')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const tbody = document.getElementById('abastecimientoTableBody');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No hay registros de abastecimiento</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(a => {
                const costoTotal = a.cantidad * a.precio_compra_unitario;
                const ventaTotal = a.cantidad * a.precio_venta_unitario;
                const ganancia = ventaTotal - costoTotal;
                
                return `
                <tr>
                    <td>${new Date(a.fecha_abastecimiento).toLocaleDateString()}</td>
                    <td><strong>${a.nombre_producto}</strong></td>
                    <td>${a.cantidad}</td>
                    <td>$${parseFloat(a.precio_compra_unitario).toFixed(2)}</td>
                    <td>$${parseFloat(a.precio_venta_unitario).toFixed(2)}</td>
                    <td><span style="color:#2ecc71; font-weight:700;">$${ganancia.toFixed(2)}</span></td>
                </tr>
                `;
            }).join('');
        } else {
            console.error("Error en abastecimiento:", data.error);
        }
    }).catch(err => console.error("Fallo fetch abastecimiento:", err));
}

// ==================== MÓDULO DE PEDIDOS AL PROVEEDOR ====================

function cargarPedidos() {
    const tbody = document.getElementById('pedidosTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:20px;">Cargando pedidos...</td></tr>';
    fetch('get_pedidos.php')
        .then(res => res.json())
        .then(data => {
            const resumenEl = document.getElementById('pedidosResumenStock');
            if (resumenEl && data.resumen) {
                resumenEl.textContent = `⚠️ Productos por reponer: ${data.resumen.productos_reponer} (${data.resumen.agotados} agotados)`;
            }
            if (!data.success || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:20px;">No hay pedidos registrados. Crea el primero con "➕ Agregar Pedido".</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(pe => {
                const fecha = pe.fecha_pedido ? new Date(pe.fecha_pedido).toLocaleString('es-VE', {dateStyle: 'short', timeStyle: 'short'}) : '—';
                const totalUSD = parseFloat(pe.total_usd || 0).toFixed(2);
                const proveedorResumen = escaparAtributo(`${pe.proveedor_nombre || 'Sin proveedor'} #${pe.id}`);
                return `<tr>
                    <td><strong>#${pe.id}</strong></td>
                    <td><strong>${escaparHTML(pe.proveedor_nombre || '—')}</strong></td>
                    <td style="font-size:0.84rem; max-width:280px; word-break:break-word;">${escaparHTML(pe.productos || '—')}</td>
                    <td><strong style="color:#2563eb;">$${totalUSD}</strong></td>
                    <td>${fecha}</td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button type="button" class="btn-primary" style="padding:5px 12px; font-size:0.8rem; margin-right:4px;" onclick="abrirModalEditarPedido(${pe.id})">✏️ Editar</button>
                        <button type="button" class="btn-secondary" style="background:#dc2626; color:white; border:none; padding:5px 12px; font-size:0.8rem; border-radius:6px;" onclick="eliminarPedido(${pe.id}, '${proveedorResumen}')">🗑️ Eliminar</button>
                    </td>
                </tr>`;
            }).join('');
            actualizarPaginacionTabla('pedidosTableBody');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#dc2626; padding:20px;">Error al cargar los pedidos.</td></tr>';
        });
}

function pedidoAhoraLocalInput() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// Convierte "2026-10-09 14:30:00" (MySQL) al formato del input datetime-local
function pedidoFechaAInput(valor) {
    if (!valor) return pedidoAhoraLocalInput();
    const s = String(valor).replace(' ', 'T');
    return s.length >= 16 ? s.slice(0, 16) : s;
}

function resetPedidoForm() {
    const idField = document.getElementById('pedidoId');
    if (idField) idField.value = '';
    const prov = document.getElementById('selectPedidoProveedor');
    if (prov) prov.value = '';
    const fecha = document.getElementById('pedidoFecha');
    if (fecha) fecha.value = pedidoAhoraLocalInput();
    const tbody = document.getElementById('pedidoItemsBody');
    if (tbody) tbody.innerHTML = '<tr id="pedidoItemsVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>';
    const total = document.getElementById('pedidoTotalDisplay');
    if (total) total.textContent = '$0.00 USD';
    const ayuda = document.getElementById('pedidoAyudaStock');
    if (ayuda) { ayuda.style.display = 'none'; ayuda.innerHTML = ''; }
    const info = document.getElementById('pedidoInfoProductos');
    if (info) { info.style.display = 'none'; info.textContent = ''; }
    const datalist = document.getElementById('productosPedidoList');
    if (datalist) datalist.innerHTML = '';
    productosPedidoProveedor = [];
}

function poblarSelectPedidoProveedor() {
    const select = document.getElementById('selectPedidoProveedor');
    if (!select) return;
    if (!proveedoresData.length) {
        select.innerHTML = '<option value="">-- Sin proveedores registrados --</option>';
        return;
    }
    select.innerHTML = '<option value="">Seleccionar proveedor</option>' + proveedoresData.map(p =>
        `<option value="${p.id}">${escaparHTML(p.nombre_negocio || p.nombre || 'Proveedor')}${p.nombre ? ' — ' + escaparHTML(p.nombre) + ' ' + escaparHTML(p.apellido || '') : ''}</option>`
    ).join('');
}

function pedidoProveedorSeleccionado() {
    const provSelect = document.getElementById('selectPedidoProveedor');
    const proveedorId = parseInt(provSelect?.value || 0, 10);
    // Al cambiar de proveedor se limpian las filas: los productos cargados
    // pertenecian al proveedor anterior.
    const tbody = document.getElementById('pedidoItemsBody');
    if (tbody) tbody.innerHTML = '<tr id="pedidoItemsVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>';
    recalcularTotalPedido();
    return cargarProductosPedidoProveedor(proveedorId);
}

// Carga UNICAMENTE los productos registrados con el proveedor elegido y deja
// disponibles sus nombres en el datalist del modal, para que no salgan todos.
function cargarProductosPedidoProveedor(proveedorId) {
    const datalist = document.getElementById('productosPedidoList');
    const info = document.getElementById('pedidoInfoProductos');

    if (!proveedorId || proveedorId <= 0) {
        productosPedidoProveedor = [];
        if (datalist) datalist.innerHTML = '';
        if (info) { info.style.display = 'none'; info.textContent = ''; }
        cargarAyudaStockPedido();
        return Promise.resolve([]);
    }

    if (info) {
        info.style.display = 'block';
        info.textContent = 'Cargando productos del proveedor...';
    }

    return fetch('get_productos_proveedor.php?proveedor_id=' + encodeURIComponent(proveedorId))
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                productosPedidoProveedor = [];
                if (datalist) datalist.innerHTML = '';
                if (info) { info.style.display = 'block'; info.textContent = data.error || 'Error al cargar los productos.'; }
                cargarAyudaStockPedido();
                return [];
            }
            productosPedidoProveedor = data.productos || [];
            if (datalist) {
                datalist.innerHTML = '';
                [...new Set(productosPedidoProveedor.map(p => p.nombre).filter(Boolean))].forEach(nombre => {
                    const option = document.createElement('option');
                    option.value = nombre;
                    datalist.appendChild(option);
                });
            }
            if (info) {
                info.style.display = 'block';
                info.textContent = productosPedidoProveedor.length
                    ? `📦 ${productosPedidoProveedor.length} producto(s) registrados con este proveedor`
                    : 'Este proveedor todavía no tiene productos registrados. Registra una compra primero.';
            }
            cargarAyudaStockPedido();
            return productosPedidoProveedor;
        })
        .catch(() => {
            productosPedidoProveedor = [];
            if (datalist) datalist.innerHTML = '';
            if (info) { info.style.display = 'block'; info.textContent = 'Error de red al cargar los productos.'; }
            cargarAyudaStockPedido();
            return [];
        });
}

function cargarAyudaStockPedido() {
    const cont = document.getElementById('pedidoAyudaStock');
    if (!cont) return;
    if (!productosPedidoProveedor.length) { cont.style.display = 'none'; cont.innerHTML = ''; return; }
    const bajos = productosPedidoProveedor.filter(p => {
        const stock = parseInt(p.stock || 0, 10);
        const min = parseInt(p.stock_minimo || 0, 10);
        return stock === 0 || stock < min;
    });
    if (!bajos.length) { cont.style.display = 'none'; cont.innerHTML = ''; return; }
    cont.style.display = 'block';
    cont.innerHTML = '<strong>🔔 Productos por reponer:</strong> ' + bajos.slice(0, 8).map(p => {
        const stock = parseInt(p.stock || 0, 10);
        const min = parseInt(p.stock_minimo || 0, 10);
        const falta = Math.max(min - stock, 1);
        return `<button type="button" onclick="agregarSugerenciaPedido(${p.id}, ${falta})" style="margin:2px 4px 2px 0; padding:4px 10px; background:#fff; border:1px solid #f59e0b; color:#92400e; border-radius:16px; font-size:0.8rem; cursor:pointer;">${escaparHTML(p.nombre)} · falta ${falta}</button>`;
    }).join('');
}

function agregarSugerenciaPedido(productoId, cantidad) {
    const producto = productosPedidoProveedor.find(p => parseInt(p.id, 10) === parseInt(productoId, 10));
    if (!producto) return;
    insertarFilaPedido(productoId, producto.nombre || '', cantidad, parseFloat(producto.precio_compra || 0), 'unidad');
}

function abrirModalPedido() {
    resetPedidoForm();
    document.getElementById('pedidoModalTitulo').textContent = '📋 Agregar Pedido al Proveedor';
    const btn = document.getElementById('btnGuardarPedido');
    if (btn) btn.textContent = '✅ Guardar Pedido';
    if (!proveedoresData.length) {
        cargarProveedores();
    } else {
        poblarSelectPedidoProveedor();
    }
    const modal = document.getElementById('modalPedido');
    if (modal) modal.style.display = 'flex';
}

function abrirModalEditarPedido(id) {
    fetch('get_pedido.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                mostrarNotificacion(data.error || 'No se pudo cargar el pedido', true);
                return;
            }
            const pedido = data.pedido;
            resetPedidoForm();
            document.getElementById('pedidoId').value = pedido.id;
            document.getElementById('pedidoModalTitulo').textContent = `✏️ Editar Pedido #${pedido.id}`;
            const fechaField = document.getElementById('pedidoFecha');
            if (fechaField) fechaField.value = pedidoFechaAInput(pedido.fecha_pedido);
            const btn = document.getElementById('btnGuardarPedido');
            if (btn) btn.textContent = '💾 Guardar Cambios';

            const fijarProveedor = () => {
                if (proveedoresData.length) {
                    poblarSelectPedidoProveedor();
                    document.getElementById('selectPedidoProveedor').value = pedido.proveedor_id || '';
                }
            };
            if (proveedoresData.length) {
                fijarProveedor();
            } else {
                cargarProveedores().then(fijarProveedor);
            }

            const modal = document.getElementById('modalPedido');
            if (modal) modal.style.display = 'flex';

            // Se cargan los productos del proveedor y enseguida se rellenan las filas.
            return cargarProductosPedidoProveedor(pedido.proveedor_id || 0).then(() => {
                const tbody = document.getElementById('pedidoItemsBody');
                if (tbody) tbody.innerHTML = '';
                (pedido.items || []).forEach(it => {
                    insertarFilaPedido(it.producto_id, it.nombre_producto, parseInt(it.cantidad, 10) || 1, parseFloat(it.precio_unitario_usd) || 0, it.unidad_medida);
                });
            });
        })
        .catch(() => mostrarNotificacion('Error al cargar el pedido', true));
}

function cerrarModalPedido() {
    const modal = document.getElementById('modalPedido');
    if (modal) modal.style.display = 'none';
}

function insertarFilaPedido(productoId, nombre, cantidad, precio, unidad) {
    const tbody = document.getElementById('pedidoItemsBody');
    if (!tbody) return;
    const vaci = document.getElementById('pedidoItemsVacio');
    if (vaci) vaci.style.display = 'none';
    const subtotal = (cantidad * precio).toFixed(2);
    const unidadSel = ['unidad', 'caja', 'paquete'].includes(unidad) ? unidad : 'unidad';
    const tr = document.createElement('tr');
    tr.dataset.productoId = productoId || '';
    tr.innerHTML = `
        <td><input type="text" class="pedido-producto" list="productosPedidoList" placeholder="Nombre del producto" value="${escaparAtributo(nombre)}" style="width:170px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarPedidoProducto(this)" /></td>
        <td><input type="number" class="pedido-cantidad" min="1" value="${cantidad || 1}" style="width:70px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalPedido(this)" /></td>
        <td>
            <select class="pedido-unidad" style="padding:6px; border:1px solid #ddd; border-radius:6px;">
                <option value="unidad"${unidadSel === 'unidad' ? ' selected' : ''}>Unidad</option>
                <option value="caja"${unidadSel === 'caja' ? ' selected' : ''}>Caja</option>
                <option value="paquete"${unidadSel === 'paquete' ? ' selected' : ''}>Paquete</option>
            </select>
        </td>
        <td><input type="number" class="pedido-precio" min="0" step="0.01" value="${(parseFloat(precio) || 0).toFixed(2)}" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalPedido(this)" /></td>
        <td class="pedido-subtotal" style="font-weight:600; color:#2563eb; padding:6px;">$${subtotal}</td>
        <td><button type="button" class="btn-secondary" style="padding:6px 10px; font-size:0.82rem; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;" onclick="quitarFilaPedido(this)">✕</button></td>
    `;
    tbody.appendChild(tr);
    recalcularTotalPedido();
}

function agregarFilaPedido() {
    const provSelect = document.getElementById('selectPedidoProveedor');
    const proveedorId = parseInt(provSelect?.value || 0, 10);
    if (!proveedorId) {
        mostrarNotificacion('Primero selecciona un proveedor para cargar sus productos', true);
        return;
    }
    insertarFilaPedido(0, '', 1, 0);
}

function actualizarPedidoProducto(inputElement) {
    const tr = inputElement.closest('tr');
    if (!tr) return;
    const nombre = inputElement.value.trim();
    const precioInput = tr.querySelector('.pedido-precio');
    if (!nombre) {
        if (precioInput) precioInput.value = '0.00';
        delete tr.dataset.productoId;
        actualizarSubtotalPedido(inputElement);
        return;
    }
    const producto = productosPedidoProveedor.find(p => String(p.nombre || '').trim().toLowerCase() === nombre.toLowerCase());
    if (producto) {
        inputElement.value = producto.nombre;
        tr.dataset.productoId = producto.id;
        if (precioInput && (precioInput.value === '0.00' || precioInput.value === '')) {
            precioInput.value = parseFloat(producto.precio_compra || 0).toFixed(2);
        }
    }
    actualizarSubtotalPedido(inputElement);
}

function actualizarSubtotalPedido(inputElement) {
    const tr = inputElement.closest('tr');
    if (!tr) return;
    const cantidad = parseFloat(tr.querySelector('.pedido-cantidad')?.value || 0);
    const precio = parseFloat(tr.querySelector('.pedido-precio')?.value || 0);
    const subtotalCell = tr.querySelector('.pedido-subtotal');
    if (subtotalCell) subtotalCell.textContent = `$${(cantidad * precio).toFixed(2)}`;
    recalcularTotalPedido();
}

function recalcularTotalPedido() {
    const rows = Array.from(document.querySelectorAll('#pedidoItemsBody tr:not(#pedidoItemsVacio)'));
    const total = rows.reduce((sum, row) => {
        const cant = parseFloat(row.querySelector('.pedido-cantidad')?.value || 0);
        const prec = parseFloat(row.querySelector('.pedido-precio')?.value || 0);
        return sum + (cant * prec);
    }, 0);
    const display = document.getElementById('pedidoTotalDisplay');
    if (display) display.textContent = `$${total.toFixed(2)} USD`;
}

function quitarFilaPedido(button) {
    const tr = button.closest('tr');
    if (tr) tr.remove();
    const tbody = document.getElementById('pedidoItemsBody');
    if (tbody && tbody.querySelectorAll('tr:not(#pedidoItemsVacio)').length === 0) {
        const vaci = document.getElementById('pedidoItemsVacio');
        if (vaci) vaci.style.display = '';
    }
    recalcularTotalPedido();
}

function enviarPedido() {
    const idField = document.getElementById('pedidoId');
    const id = idField ? parseInt(idField.value, 10) || 0 : 0;

    const proveedorTab = document.getElementById('selectPedidoProveedor');
    const proveedorId = parseInt(proveedorTab?.value || 0, 10);
    if (!proveedorId) {
        mostrarNotificacion('Selecciona un proveedor antes de guardar el pedido', true);
        return;
    }

    const fechaField = document.getElementById('pedidoFecha');
    const fechaPedido = fechaField ? fechaField.value : '';
    if (!fechaPedido) {
        mostrarNotificacion('Indica la fecha del pedido', true);
        return;
    }

    const rows = Array.from(document.querySelectorAll('#pedidoItemsBody tr:not(#pedidoItemsVacio)'));
    const items = rows.map(row => ({
        producto_id: parseInt(row.dataset.productoId || 0, 10) || 0,
        nombre_producto: row.querySelector('.pedido-producto')?.value?.trim() || '',
        cantidad: parseInt(row.querySelector('.pedido-cantidad')?.value || 0, 10),
        unidad_medida: row.querySelector('.pedido-unidad')?.value || 'unidad',
        precio_unitario: parseFloat(row.querySelector('.pedido-precio')?.value || 0)
    })).filter(i => i.nombre_producto && i.cantidad > 0 && i.precio_unitario >= 0);

    if (!items.length) {
        mostrarNotificacion('Agrega al menos un producto válido al pedido', true);
        return;
    }

    fetch('save_pedido.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, proveedor_id: proveedorId, fecha_pedido: fechaPedido, items })
    })
    .then(res => res.json())
    .then(result => {
        if (result.success) {
            mostrarNotificacion(id ? '✅ Pedido actualizado' : '✅ Pedido registrado');
            cerrarModalPedido();
            cargarPedidos();
        } else {
            mostrarNotificacion(result.error || 'Error al guardar el pedido', true);
        }
    })
    .catch(() => mostrarNotificacion('Error de conexión al guardar el pedido', true));
}

function eliminarPedido(id, resumen) {
    if (!confirm(`¿Eliminar el pedido ${resumen}? Esta acción no se puede deshacer.`)) return;
    fetch('eliminar_pedido.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                mostrarNotificacion('🗑️ Pedido eliminado');
                cargarPedidos();
            } else {
                mostrarNotificacion(data.error || 'Error al eliminar el pedido', true);
            }
        })
        .catch(() => mostrarNotificacion('Error de conexión al eliminar el pedido', true));
}

function cargarReportes() {
    fetch('get_reportes.php')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('reportesContainer');
            if (!container) return;
            if (!data.success) {
                container.innerHTML = '<div class="alert-box">No se pudieron cargar los reportes.</div>';
                return;
            }

            const estadosHtml = (data.ventas_por_estado || []).map(item => `
                <li><strong>${item.estado || 'Sin estado'}</strong>: ${item.total} ventas</li>
            `).join('');

            const productosHtml = (data.top_productos || []).map(item => `
                <li><strong>${item.nombre_producto}</strong>: ${item.unidades} unidades · $${parseFloat(item.ingresos || 0).toFixed(2)}</li>
            `).join('');

            const trendHtml = (data.ventas_ultimos_7_dias || []).map(item => `
                <li>${item.fecha}: ${item.ventas} ventas · $${parseFloat(item.ingresos || 0).toFixed(2)}</li>
            `).join('');

            container.innerHTML = `
                <div class="admin-card" style="padding:20px; border-radius:18px;">
                    <h3>📈 Ventas por estado</h3>
                    <ul>${estadosHtml || '<li>No hay datos</li>'}</ul>
                </div>
                <div class="admin-card" style="padding:20px; border-radius:18px;">
                    <h3>🏆 Productos más vendidos</h3>
                    <ul>${productosHtml || '<li>No hay datos</li>'}</ul>
                </div>
                <div class="admin-card" style="padding:20px; border-radius:18px;">
                    <h3>📅 Últimos 7 días</h3>
                    <ul>${trendHtml || '<li>No hay datos</li>'}</ul>
                </div>
            `;
        })
        .catch(err => {
            console.error('Error al cargar reportes:', err);
        });
}

// ==================== LIBRO DE IVA (CONTROL INTERNO) ====================

/** Fija el rango del libro al mes calendario actual. */
function libroIvaMesActual() {
    const hoy = new Date();
    setLibroIvaRango(new Date(hoy.getFullYear(), hoy.getMonth(), 1), hoy);
    cargarLibroIva();
}

/** Fija el rango del libro al mes calendario anterior. */
function libroIvaMesAnterior() {
    const hoy = new Date();
    setLibroIvaRango(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1), new Date(hoy.getFullYear(), hoy.getMonth(), 0));
    cargarLibroIva();
}

/** Escribe las fechas en los inputs respetando la zona horaria local. */
function setLibroIvaRango(desde, hasta) {
    const fmt = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const dEl = document.getElementById('libroIvaDesde');
    const hEl = document.getElementById('libroIvaHasta');
    if (dEl) dEl.value = fmt(desde);
    if (hEl) hEl.value = fmt(hasta);
}

/** Carga el libro de IVA para el rango de fechas seleccionado. */
function cargarLibroIva() {
    const desdeEl = document.getElementById('libroIvaDesde');
    const hastaEl = document.getElementById('libroIvaHasta');

    // Primera carga: arranca en el mes actual.
    if (desdeEl && !desdeEl.value) {
        libroIvaMesActual();
        return;
    }

    const params = new URLSearchParams();
    if (desdeEl && desdeEl.value) params.set('desde', desdeEl.value);
    if (hastaEl && hastaEl.value) params.set('hasta', hastaEl.value);

    fetch('get_libro_iva.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                mostrarNotificacion(data.error || 'No se pudo generar el libro de IVA', true);
                return;
            }
            renderLibroIva(data);
        })
        .catch(err => {
            console.error('Error cargando libro de IVA:', err);
            mostrarNotificacion('Error de conexión al generar el libro de IVA', true);
        });
}

function renderLibroIva(data) {
    const r = data.resumen || {};
    const money = n => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const setTexto = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

    // KPIs principales
    setTexto('libroIvaKpiDebito', money(r.iva_debito));
    setTexto('libroIvaKpiCredito', money(r.iva_credito));
    setTexto('libroIvaKpiIgtf', money(r.igtf_total));

    const neto = Number(r.iva_neto || 0);
    const kpiNeto = document.getElementById('libroIvaKpiNeto');
    if (kpiNeto) {
        kpiNeto.textContent = money(Math.abs(neto));
        kpiNeto.style.color = neto < 0 ? '#16a34a' : '#4f46e5';
    }
    const kpiNetoLabel = document.getElementById('libroIvaKpiNetoLabel');
    if (kpiNetoLabel) {
        kpiNetoLabel.textContent = neto < 0 ? 'Crédito a Favor del Período' : 'IVA a Pagar del Período';
    }

    // Ventas por alícuota
    const alicBody = document.getElementById('libroIvaAlicuotaBody');
    if (alicBody) {
        const filas = data.ventas_por_alicuota || [];
        alicBody.innerHTML = filas.length ? filas.map(a => {
            const pct = Number(a.iva_alicuota || 0);
            const etiqueta = pct > 0 ? `${pct}%` : 'Exento / Exonerado (0%)';
            return `<tr>
                <td><strong>${etiqueta}</strong></td>
                <td style="text-align:right;">${money(a.base)}</td>
                <td style="text-align:right;">${money(a.iva)}</td>
                <td style="text-align:right;">${money(a.total)}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="4" style="text-align:center; color:#aaa; padding:16px;">Sin ventas desglosadas en el período.</td></tr>';
    }

    // Cuadro débito / crédito fiscal
    const cuadroBody = document.getElementById('libroIvaCuadroBody');
    if (cuadroBody) {
        const rows = [
            ['Ventas (IVA débito fiscal)', r.base_ventas, r.iva_debito],
            ['Compras (IVA crédito fiscal)', r.base_compras, r.iva_credito],
            ['Retención de IVA (informativo)', '', r.retencion_iva],
            ['IVA neto del período', '', neto],
        ];
        cuadroBody.innerHTML = rows.map((row, i) => {
            const ultimo = i === rows.length - 1;
            const estilo = ultimo ? ' style="font-weight:800; background:rgba(79,70,229,0.06);"' : '';
            return `<tr${estilo}>
                <td>${row[0]}</td>
                <td style="text-align:right;">${row[1] === '' ? '—' : money(row[1])}</td>
                <td style="text-align:right;">${row[2] === '' ? '—' : money(row[2])}</td>
            </tr>`;
        }).join('');
    }

    // Libro de ventas
    const ventasBody = document.getElementById('libroIvaVentasBody');
    if (ventasBody) {
        const filas = data.libro_ventas || [];
        ventasBody.innerHTML = filas.length ? filas.map(v => {
            const aviso = v.desglosado ? '' : ' <span title="Venta sin desglose de IVA" style="color:#d97706;">*</span>';
            return `<tr>
                <td>#${v.id}</td>
                <td>${formatearFechaHoraLibro(v.fecha)}</td>
                <td>${v.cliente || ''}</td>
                <td>${v.metodo_pago || ''}</td>
                <td>${v.estado || ''}</td>
                <td style="text-align:right;">${money(v.base)}</td>
                <td style="text-align:right;">${money(v.iva)}${aviso}</td>
                <td style="text-align:right;">${money(v.igtf)}</td>
                <td style="text-align:right;"><strong>${money(v.total)}</strong></td>
            </tr>`;
        }).join('') : '<tr><td colspan="9" style="text-align:center; color:#aaa; padding:16px;">Sin ventas en el período.</td></tr>';
    }

    // Libro de compras
    const comprasBody = document.getElementById('libroIvaComprasBody');
    if (comprasBody) {
        const filas = data.libro_compras || [];
        comprasBody.innerHTML = filas.length ? filas.map(c => `<tr>
            <td>#${c.id}</td>
            <td>${formatearFechaHoraLibro(c.fecha)}</td>
            <td>${c.proveedor || ''}</td>
            <td>${c.numero_factura || '—'}</td>
            <td style="text-align:right;">${money(c.base)}</td>
            <td style="text-align:right;">${money(c.iva_credito)}</td>
            <td style="text-align:right;">${money(c.retencion_iva)}</td>
            <td style="text-align:right;">${money(c.igtf)}</td>
            <td style="text-align:right;"><strong>${money(c.total)}</strong></td>
        </tr>`).join('') : '<tr><td colspan="9" style="text-align:center; color:#aaa; padding:16px;">Sin compras en el período.</td></tr>';
    }
}

function formatearFechaHoraLibro(valor) {
    if (!valor) return '';
    const d = new Date(String(valor).replace(' ', 'T'));
    if (isNaN(d.getTime())) return valor;
    return d.toLocaleString('es-VE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function cargarStats() {
    fetch('get_stats.php')
    .then(res => res.json())
    .then(data => {
        if (!data.success) return;
        const s = data.stats;

        // Fecha actual en el encabezado del panel
        const fechaEl = document.getElementById('admin-dash-fecha');
        if (fechaEl) {
            fechaEl.textContent = '📅 ' + new Date().toLocaleDateString('es-VE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        }

        // Tarjetas KPI
        const container = document.getElementById('admin-dashboard-stats');
        if (container) {
            const cards = [
                { emoji: '💰', color: '#4361ee', bg: 'rgba(67,97,238,0.1)',   label: 'Ingresos Totales',   value: '$' + formatearUSD(s.ingresos) },
                { emoji: '📈', color: '#2ecc71', bg: 'rgba(46,204,113,0.1)',  label: 'Ganancia Neta',      value: '$' + formatearUSD(s.ganancia), extra: formatearNumero(s.margen) + '% de margen' },
                { emoji: '🛒', color: '#f72585', bg: 'rgba(247,37,133,0.1)',  label: 'Ventas',             value: formatearNumero(s.num_ventas) },
                { emoji: '📦', color: '#ff9f1c', bg: 'rgba(255,159,28,0.1)',  label: 'Valor Inventario',   value: '$' + formatearUSD(s.valor_inventario) },
                { emoji: '⚠️', color: '#e63946', bg: 'rgba(230,57,70,0.1)',   label: 'Alertas Stock',      value: formatearNumero(s.alertas) },
                { emoji: '💸', color: '#b91c1c', bg: 'rgba(185,28,28,0.1)',   label: 'Pérdidas ($)',       value: '$' + formatearUSD(s.perdidas_usd) },
                { emoji: '📉', color: '#e76f51', bg: 'rgba(231,111,81,0.1)',  label: 'Unidades Perdidas',  value: formatearNumero(s.perdidas_unidades) },
                { emoji: '👥', color: '#10b981', bg: 'rgba(16,185,129,0.1)',  label: 'Clientes',           value: formatearNumero(s.clientes) },
            ];
            container.innerHTML = cards.map(c => `
                <div class="stat-card" style="display:flex; align-items:center; gap:15px; border-top:4px solid ${c.color};">
                    <div style="font-size:1.9rem; width:58px; height:58px; display:flex; align-items:center; justify-content:center; border-radius:15px; background:${c.bg};">${c.emoji}</div>
                    <div style="min-width:0;">
                        <div style="font-size:0.78rem; color:var(--text-light); font-weight:700; text-transform:uppercase; letter-spacing:0.4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${c.label}</div>
                        <div style="font-size:1.35rem; font-weight:800; color:${c.color};">${c.value}</div>
                        ${c.extra ? `<div style="font-size:0.72rem; font-weight:700; color:${c.color};">${c.extra}</div>` : ''}
                    </div>
                </div>
            `).join('');
        }

        // Gráficas del panel: una principal (ventas de la semana) + dos secundarias al lado
        const chartContainer = document.getElementById('admin-dashboard-charts');
        if (chartContainer) {
            chartContainer.innerHTML =
                '<div class="admin-dash-chart-main">' +
                    tarjetaGraficaDashboard('📈', 'Ingresos últimos 7 días', graficaBarrasSemana(s.ventas_semana || [])) +
                '</div>' +
                '<div class="admin-dash-chart-side">' +
                    tarjetaGraficaDashboard('🏆', 'Top productos más vendidos', graficaTopProductos(s.top_productos || [])) +
                    tarjetaGraficaDashboard('💸', 'Pérdidas por tipo', graficaPerdidasPorTipo(s.perdidas_por_tipo || [])) +
                '</div>';
        }
    }).catch(err => console.error("Error cargando stats:", err));
}

function formatearUSD(n) {
    return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatearNumero(n) {
    return Number(n || 0).toLocaleString('es-VE');
}

function tarjetaGraficaDashboard(emoji, titulo, contenido) {
    return `
    <div class="admin-dash-chart-card">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
            <span style="font-size:1.2rem;">${emoji}</span>
            <h3 class="admin-dash-chart-title" style="margin:0; font-size:0.95rem; font-weight:800;">${titulo}</h3>
        </div>
        ${contenido}
    </div>`;
}

function graficaBarrasSemana(dias) {
    const max = Math.max(1, ...dias.map(d => Number(d.ingresos) || 0));
    const barras = dias.map(d => {
        const ingresos = Number(d.ingresos) || 0;
        const altura = ingresos > 0 ? Math.max(12, Math.round((ingresos / max) * 100)) : 4;
        return `
        <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:6px; min-width:0;" title="${d.label}: $${formatearUSD(ingresos)} (${d.ventas || 0} ventas)">
            <div class="admin-dash-chart-sub" style="font-size:0.6rem; font-weight:800;">$${formatearUSD(ingresos)}</div>
            <div style="width:70%; max-width:52px; height:${altura}px; background:linear-gradient(180deg,#818cf8,#4f46e5); border-radius:8px 8px 0 0; transition:height .4s;"></div>
            <div class="admin-dash-chart-sub" style="font-size:0.7rem; font-weight:700;">${d.label}</div>
        </div>`;
    }).join('');
    return `<div style="display:flex; align-items:flex-end; gap:6px; height:200px; padding-top:8px;">${barras}</div>`;
}

function graficaTopProductos(items) {
    if (!items.length) return '<div class="admin-dash-chart-sub" style="font-size:0.85rem; padding:10px 0;">Aún no hay productos vendidos.</div>';
    const max = Math.max(1, ...items.map(i => Number(i.unidades) || 0));
    const total = items.reduce((acc, i) => acc + (Number(i.unidades) || 0), 0);
    return items.map(i => {
        const unidades = Number(i.unidades) || 0;
        const ancho = Math.max(8, Math.round((unidades / max) * 100));
        const pct = total > 0 ? Math.round((unidades / total) * 100) : 0;
        return `
        <div style="margin-bottom:13px;">
            <div style="display:flex; justify-content:space-between; gap:8px; margin-bottom:5px;">
                <span class="admin-dash-chart-title" style="font-size:0.82rem; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escaparHTML(i.nombre)}</span>
                <span class="admin-dash-chart-sub" style="font-size:0.72rem; font-weight:700; flex-shrink:0;">${pct}% · ${formatearNumero(unidades)} unid · $${formatearUSD(i.ventas_usd)}</span>
            </div>
            <div class="admin-dash-chart-track">
                <div style="width:${ancho}%; height:100%; background:linear-gradient(90deg,#ff9f1c,#f72585); border-radius:8px;"></div>
            </div>
        </div>`;
    }).join('');
}

function graficaPerdidasPorTipo(items) {
    const total = items.reduce((acc, t) => acc + (Number(t.valor) || 0), 0);
    if (!items.length || total <= 0) return '<div class="admin-dash-chart-sub" style="font-size:0.85rem; padding:10px 0;">No hay pérdidas activas registradas.</div>';
    const max = Math.max(...items.map(t => Number(t.valor) || 0), 1);
    const colores = ['#dc2626', '#e76f51', '#ea580c', '#d97706', '#b45309'];
    return items.map((t, idx) => {
        const valor = Number(t.valor) || 0;
        const ancho = Math.max(8, Math.round((valor / max) * 100));
        const color = colores[idx % colores.length];
        const pct = total > 0 ? Math.round((valor / total) * 100) : 0;
        return `
        <div style="margin-bottom:13px;">
            <div style="display:flex; justify-content:space-between; gap:8px; margin-bottom:5px;">
                <span class="admin-dash-chart-title" style="font-size:0.82rem; font-weight:700;">${escaparHTML(t.etiqueta)}</span>
                <span class="admin-dash-chart-sub" style="font-size:0.72rem; font-weight:700; flex-shrink:0;">${pct}% · ${formatearNumero(t.unidades)} unid · $${formatearUSD(valor)}</span>
            </div>
            <div class="admin-dash-chart-track-red">
                <div style="width:${ancho}%; height:100%; background:${color}; border-radius:8px;"></div>
            </div>
        </div>`;
    }).join('');
}

function cargarClientes() {
    fetch('get_clientes.php')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const tbody = document.getElementById('clientesTableBody');
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No hay clientes registrados</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(c => `
                <tr data-fecha="${c.fecha_registro || ''}">
                    <td><strong>${c.nombre_completo || c.usuario}</strong></td>
                    <td>${c.cedula || 'N/A'}</td>
                    <td>${c.usuario}</td>
                    <td>${c.email || 'N/A'}</td>
                    <td>${c.telefono || 'N/A'}</td>
                    <td>${new Date(c.fecha_registro).toLocaleDateString()}</td>
                </tr>
            `).join('');
            // Se reaplican los filtros de texto/fecha vigentes sobre las filas nuevas.
            aplicarFiltrosTabla('clientesTableBody');
        }
    }).catch(err => console.error("Fallo fetch clientes:", err));
}

// ==================== PAGOS PENDIENTES ====================

function cargarPagosPendientes() {
    // OJO: el id de esta tabla en index.php es 'pagosTableBody'. Antes se
    // buscaba 'pagosPendientesBody', que no existe en ninguna parte del HTML,
    // y el "if (!tbody) return" de aqui abortaba la funcion antes del fetch:
    // la tabla se quedaba eternamente en "Cargando...".
    const tbody = document.getElementById('pagosTableBody');
    if (!tbody) {
        console.error('cargarPagosPendientes: no se encontro el elemento #pagosTableBody');
        return;
    }
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;">⏳ Cargando...</td></tr>';

    fetch('get_pagos_pendientes.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; color:#c53030;">Error al cargar los pagos pendientes: ' + escaparHTML(data.error || 'respuesta invalida del servidor') + '</td></tr>';
                return;
            }

            const lista = Array.isArray(data.data) ? data.data : [];

            actualizarBadgePagosPendientes(lista.length);

            // Sin este return, la lista vacia se sobreescribia justo debajo
            // con '' y el mensaje de "no hay pagos" nunca se llegaba a ver.
            if (lista.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; color:var(--text-light);">No hay pagos pendientes de verificacion</td></tr>';
                return;
            }

            tbody.innerHTML = lista.map(p => {
                const fecha = new Date(p.fecha_pago).toLocaleString();
                const metodoLabel = p.metodo_pago === 'pago_movil' ? '📱 Pago Móvil'
                    : p.metodo_pago === 'zelle' ? '🌐 Zelle'
                    : p.metodo_pago === 'biopago' ? '⚡ Biopago'
                    : '🏦 Transferencia';

                // Botón/preview del comprobante
                let comprobanteHtml = '<span style="color:var(--text-light);font-size:0.8rem;">Sin archivo</span>';
                if (p.receipt_path) {
                    const ext = p.receipt_path.split('.').pop().toLowerCase();
                    if (['jpg','jpeg','png','webp'].includes(ext)) {
                        comprobanteHtml = `
                            <a href="../${escaparAtributo(p.receipt_path)}" target="_blank" title="Ver comprobante ampliado">
                                <img src="../${escaparAtributo(p.receipt_path)}" 
                                     style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:2px solid var(--primary);cursor:pointer;"
                                     onerror="this.parentElement.innerHTML='<span style=color:red;font-size:0.8rem;>Sin imagen</span>'">
                            </a>`;
                    } else {
                        comprobanteHtml = `<a href="../${escaparAtributo(p.receipt_path)}" target="_blank" class="btn-table-edit" style="font-size:0.8rem;">📄 Ver PDF</a>`;
                    }
                }

                return `
                    <tr id="pago-row-${p.id}">
                        <td><strong>#${p.venta_id}</strong></td>
                        <td>
                            <div style="font-weight:600;">${escaparHTML(p.cliente_nombre || 'Desconocido')}</div>
                            <div style="font-size:0.75rem;color:var(--text-light);">${escaparHTML(p.cliente_email || '')}</div>
                        </td>
                        <td>${metodoLabel}</td>
                        <td>${escaparHTML(p.banco_origen || '—')}</td>
                        <td>${escaparHTML(p.telefono_origen || '—')}</td>
                        <td><strong style="color:var(--primary);">${escaparHTML(p.referencia || '—')}</strong></td>
                        <td><strong>Bs ${parseFloat(p.monto).toFixed(2)}</strong></td>
                        <td style="text-align:center;">${comprobanteHtml}</td>
                        <td style="font-size:0.8rem;white-space:nowrap;">${fecha}</td>
                        <td style="text-align:center;">
                    <button class="btn-table-edit" onclick="validarPago(${p.venta_id})" style="margin-bottom:6px; width:100%;">Válido</button>
                    <button class="btn-table-delete" onclick="rechazarPago(${p.venta_id})" style="width:100%;">No válido</button>
                </td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => {
            console.error('Error cargando pagos pendientes:', err);
            if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:red;">Error al cargar los datos</td></tr>';
        });
}

function validarPago(ventaId) {
    if (!confirm('¿Validar este pago y descontar stock?')) return;
    fetch('validar_pago.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ venta_id: ventaId, accion: 'validar' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion('✅ Pago validado correctamente');
            cargarPagosPendientes();
            cargarStats();
        } else {
            alert('❌ Error: ' + (data.error || data.message || 'No se pudo validar el pago.'));
        }
    })
    .catch(err => {
        console.error('Error validando pago:', err);
        alert('❌ Error de conexión al validar el pago.');
    });
}

function rechazarPago(ventaId) {
    const modalHtml = `
    <div id="modalRechazo" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;">
        <div style="background:white; padding:20px; border-radius:8px; width:400px; max-width:90%; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-top:0;">Motivo de rechazo</h3>
            <p style="font-size:0.9rem; color:#666;">Selecciona el motivo por el cual el pago no es válido:</p>
            <select id="motivoRechazoSelect" style="width:100%; padding:10px; margin-bottom:15px; border-radius:4px; border:1px solid #ccc;">
                <option value="Monto incorrecto">Monto incorrecto</option>
                <option value="Referencia no coincide">Referencia no coincide</option>
                <option value="Banco origen incorrecto">Banco origen incorrecto</option>
                <option value="Pago no reflejado en cuenta">Pago no reflejado en cuenta</option>
                <option value="Comprobante ilegible o falso">Comprobante ilegible o falso</option>
                <option value="Otro">Otro (Escribir abajo)</option>
            </select>
            <input type="text" id="motivoRechazoOtro" placeholder="Escribe otro motivo..." style="width:100%; padding:10px; margin-bottom:20px; border-radius:4px; border:1px solid #ccc; display:none; box-sizing:border-box;" />
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="cerrarModalRechazo()" style="padding:8px 16px; border:1px solid #ccc; background:#fff; border-radius:4px; cursor:pointer;">Cancelar</button>
                <button type="button" onclick="confirmarRechazoPago(${ventaId})" style="padding:8px 16px; border:none; background:#dc2626; color:#fff; border-radius:4px; cursor:pointer; font-weight:bold;">Rechazar Pago</button>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    document.getElementById('motivoRechazoSelect').addEventListener('change', function(e) {
        if (e.target.value === 'Otro') {
            document.getElementById('motivoRechazoOtro').style.display = 'block';
        } else {
            document.getElementById('motivoRechazoOtro').style.display = 'none';
        }
    });
}

function cerrarModalRechazo() {
    const modal = document.getElementById('modalRechazo');
    if (modal) modal.remove();
}

function confirmarRechazoPago(ventaId) {
    const select = document.getElementById('motivoRechazoSelect');
    const inputOtro = document.getElementById('motivoRechazoOtro');
    let motivo = select.value;

    if (motivo === 'Otro') {
        motivo = inputOtro.value.trim();
        if (!motivo) {
            alert('Por favor, escribe el motivo del rechazo.');
            return;
        }
    }

    cerrarModalRechazo();

    fetch('validar_pago.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ venta_id: ventaId, accion: 'rechazar', razon: motivo })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion('✅ Pago marcado como no válido. Se notificó al cliente.');
            cargarPagosPendientes();
        } else {
            alert('❌ Error: ' + (data.error || data.message || 'No se pudo marcar como no válido.'));
        }
    })
    .catch(err => {
        console.error('Error marcando pago no válido:', err);
        alert('❌ Error de conexión al marcar pago como no válido.');
    });
}

function actualizarBadgePagosPendientes(total) {
    const btn = document.getElementById('adminNavBtnPagos');
    if (!btn) return;
    if (total > 0) {
        btn.innerHTML = `✅ Pagos Pendientes <span style="background:var(--danger);color:white;border-radius:50%;padding:2px 7px;font-size:0.75rem;margin-left:6px;">${total}</span>`;
    } else {
        btn.innerHTML = '📝 Pagos Pendientes';
    }
}

// ==================== MÓDULO DE PROVEEDORES ====================

function cargarProveedores() {
    return fetch('get_proveedores.php')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                proveedoresData = data.proveedores;
                // La lista de productos por proveedor depende de las compras: si
                // cambiaron los proveedores, lo cacheado ya no sirve.
                invalidarCacheProductosProveedor();
                const tbody = document.getElementById('proveedoresTableBody');
                if (data.proveedores.length === 0) {
                    if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; color:var(--text-light);">No hay proveedores registrados</td></tr>';
                    const select = document.getElementById('selectProveedor');
                    if (select) select.innerHTML = '<option value="">-- Sin proveedores --</option>';
                    poblarSelectProveedorProducto();
                    return data.proveedores;
                }
                const select = document.getElementById('selectProveedor');
                if (select) {
                    select.innerHTML = '<option value="">Seleccionar proveedor</option>' + data.proveedores.map(p => `<option value="${p.id}">${p.nombre_negocio} - ${p.nombre || ''} ${p.apellido || ''}</option>`).join('');
                }
                poblarSelectProveedorProducto();
                if (tbody) {
                    tbody.innerHTML = data.proveedores.map(p => {
                        // La columna de fecha puede venir como fecha_registro o,
                        // en bases antiguas, como fecha_creacion.
                        const fechaRegistro = p.fecha_registro || p.fecha_creacion || '';
                        const fechaRegistroTexto = fechaRegistro ? new Date(fechaRegistro).toLocaleDateString() : '—';
                        return `
                        <tr data-fecha="${fechaRegistro}">
                            <td>#${p.id}</td>
                            <td><strong>${p.nombre_negocio}</strong></td>
                            <td>${p.nombre || ''} ${p.apellido || ''}</td>
                            <td>${p.documento_identidad || '-'}</td>
                            <td>${p.telefono || '-'}</td>
                            <td>${p.tipo_producto || '-'}</td>
                            <td>${p.marca_producto || '-'}</td>
                            <td>${p.banco || '-'}</td>
                            <td style="white-space:nowrap;">${fechaRegistroTexto}</td>
                            <td>
                                <button class="btn-primary" style="padding:4px 8px; font-size:0.8rem; margin-right:5px;" onclick="editarProveedor(${p.id})">Editar</button>
                                <button class="btn-secondary" style="background:var(--danger); color:white; border:none; padding:4px 8px; font-size:0.8rem; border-radius:4px;" onclick="eliminarProveedor(${p.id})">Eliminar</button>
                            </td>
                        </tr>
                    `;
                    }).join('');
                    // Se reaplican los filtros de texto/fecha vigentes sobre las filas nuevas.
                    aplicarFiltrosTabla('proveedoresTableBody');
                }
                return data.proveedores;
            } else {
                mostrarNotificacion(data.error || 'Error al cargar proveedores', true);
                return [];
            }
        })
        .catch(err => {
            console.error(err);
            mostrarNotificacion('Error de red al cargar proveedores', true);
            return [];
        });
}

function abrirModalProveedor() {
    console.log('abrirModalProveedor invoked');
    document.getElementById('provId').value = '';
    document.getElementById('provNegocio').value = '';
    document.getElementById('provNombre').value = '';
    document.getElementById('provApellido').value = '';
    document.getElementById('provDocumento').value = '';
    document.getElementById('provEmail').value = '';
    document.getElementById('provTelefono').value = '';
    document.getElementById('provDireccion').value = '';
    document.getElementById('provBanco').value = '';
    document.getElementById('provCuenta').value = '';
    document.getElementById('provTelefonoPago').value = '';
    document.getElementById('provTipoProducto').value = '';
    document.getElementById('provMarcaProducto').value = '';
    
    document.getElementById('modalProveedorTitulo').textContent = 'Añadir Proveedor';
    const modal = document.getElementById('modalProveedor');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.visibility = 'visible';
        modal.style.opacity = '1';
        modal.style.position = 'fixed';
        modal.style.display = 'flex';
        modal.style.removeProperty('display');
        modal.style.display = 'flex';
    } else {
        console.error('modalProveedor element not found');
    }
}

function cerrarModalProveedor() {
    const modal = document.getElementById('modalProveedor');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

function editarProveedor(id) {
    const p = proveedoresData.find(x => x.id == id);
    if (!p) return;
    
    document.getElementById('provId').value = p.id;
    document.getElementById('provNegocio').value = p.nombre_negocio;
    document.getElementById('provNombre').value = p.nombre || '';
    document.getElementById('provApellido').value = p.apellido || '';
    document.getElementById('provDocumento').value = p.documento_identidad || '';
    
    const emailCompleto = p.email || '';
    if (emailCompleto.endsWith('@gmail.com')) {
        document.getElementById('provEmail').value = emailCompleto.substring(0, emailCompleto.length - 10);
    } else {
        document.getElementById('provEmail').value = emailCompleto;
    }
    
    // Separar teléfono
    const tel = p.telefono || '';
    if (tel.length >= 11) {
        document.getElementById('prefijoProvTelefono').value = tel.substring(0, 4);
        document.getElementById('provTelefono').value = tel.substring(4);
    } else {
        document.getElementById('provTelefono').value = tel;
    }
    
    document.getElementById('provDireccion').value = p.direccion || '';
    document.getElementById('provTipoProducto').value = p.tipo_producto || '';
    document.getElementById('provMarcaProducto').value = p.marca_producto || '';
    document.getElementById('provBanco').value = p.banco || '';
    document.getElementById('provCuenta').value = p.cuenta_bancaria || '';
    
    // Separar teléfono pago
    const telPago = p.telefono_pago_movil || '';
    if (telPago.length >= 11) {
        document.getElementById('prefijoProvTelefonoPago').value = telPago.substring(0, 4);
        document.getElementById('provTelefonoPago').value = telPago.substring(4);
    } else {
        document.getElementById('provTelefonoPago').value = telPago;
    }
    
    document.getElementById('modalProveedorTitulo').textContent = 'Editar Proveedor #' + p.id;
    const modal = document.getElementById('modalProveedor');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function guardarProveedor() {
    const data = {
        id: document.getElementById('provId').value,
        nombre_negocio: document.getElementById('provNegocio').value,
        nombre: document.getElementById('provNombre').value,
        apellido: document.getElementById('provApellido').value,
        documento_identidad: document.getElementById('provDocumento').value,
        email: document.getElementById('provEmail').value ? (document.getElementById('provEmail').value.includes('@') ? document.getElementById('provEmail').value : document.getElementById('provEmail').value + '@gmail.com') : '',
        telefono: (document.getElementById('prefijoProvTelefono') ? document.getElementById('prefijoProvTelefono').value : '') + document.getElementById('provTelefono').value,
        direccion: document.getElementById('provDireccion').value,
        tipo_producto: document.getElementById('provTipoProducto').value.trim(),
        marca_producto: document.getElementById('provMarcaProducto').value.trim(),
        banco: document.getElementById('provBanco').value,
        cuenta_bancaria: document.getElementById('provCuenta').value,
        telefono_pago_movil: (document.getElementById('prefijoProvTelefonoPago') ? document.getElementById('prefijoProvTelefonoPago').value : '') + document.getElementById('provTelefonoPago').value
    };

    if (!data.nombre_negocio) {
        mostrarNotificacion('El nombre del negocio es obligatorio', true);
        return;
    }

    fetch('save_proveedor.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            mostrarNotificacion(res.message);
            cerrarModalProveedor();
            cargarProveedores();
        } else {
            mostrarNotificacion(res.error || 'Error al guardar', true);
        }
    })
    .catch(err => {
        console.error(err);
        mostrarNotificacion('Error de red', true);
    });
}

function eliminarProveedor(id) {
    if (!confirm('¿Seguro que deseas eliminar este proveedor?')) return;
    
    fetch('delete_proveedor.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: id })
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            mostrarNotificacion(res.message);
            cargarProveedores();
        } else {
            mostrarNotificacion(res.error || 'Error al eliminar', true);
        }
    })
    .catch(err => {
        console.error(err);
        mostrarNotificacion('Error de red', true);
    });
}

function togglePublicacion(id, estado) {
    fetch('toggle_publicado.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id, publicado: estado })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion(estado === 1 ? '✅ Producto publicado en el catálogo' : '✅ Producto ocultado del catálogo');
            // El estado de publicacion aparece en el select de productos del
            // formulario, asi que lo cacheado queda desactualizado.
            invalidarCacheProductosProveedor();
            cargarInventario();
        } else {
            alert('Error: ' + (data.error || 'No se pudo cambiar el estado de publicación.'));
        }
    })
    .catch(err => {
        console.error('Error changing publication state:', err);
        mostrarNotificacion('Error de red al cambiar estado de publicación', true);
    });
}

function abrirModalHistorialProductos() {
    const modal = document.getElementById('modalHistorialProductos');
    if (modal) modal.style.display = 'flex';
    
    const tbody = document.getElementById('historialProductosBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:20px;">Cargando...</td></tr>';
    
    fetch('get_productos_admin.php')
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#aaa; padding:20px;">No hay productos registrados.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(p => {
                const fechaRaw = p.fecha_creacion || p.fecha_registro;
                const fecha = fechaRaw ? new Date(fechaRaw).toLocaleString('es-VE', {dateStyle:'medium', timeStyle:'short'}) : '—';
                return `<tr>
                    <td><strong>#${p.id}</strong></td>
                    <td>${p.nombre}</td>
                    <td>${p.categoria}</td>
                    <td>$${(p.precio_usd || 0).toFixed(2)}</td>
                    <td>${p.stock} uds.</td>
                    <td><strong>${fecha}</strong></td>
                </tr>`;
            }).join('');
        })
        .catch(err => {
            console.error('Error cargando historial de productos:', err);
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:red; padding:20px;">Error al cargar el historial.</td></tr>';
        });
}

function cerrarModalHistorialProductos() {
    const modal = document.getElementById('modalHistorialProductos');
    if (modal) modal.style.display = 'none';
}

// ==================== GESTIÓN DE IMÁGENES Y GALERÍA ====================

function obtenerRutaPreview(url) {
    if (!url) return '';
    url = url.trim();
    if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) {
        return url;
    }
    if (url.startsWith('uploads/')) {
        return '../' + url;
    }
    return url;
}

// Devuelve la lista de imágenes cargadas en el campo de imágenes del producto.
function obtenerImagenesFormulario() {
    const input = document.getElementById('imagenProd');
    if (!input) return [];
    return input.value.split(',').map(u => u.trim()).filter(u => u);
}

// Une imagen principal y galería en una sola lista sin repetir,
// respetando el orden (la primera es la imagen principal).
function imagenesGuardadasProducto(producto) {
    const lista = [];
    const agregar = (url) => {
        const limpia = String(url || '').trim();
        if (limpia && !lista.includes(limpia)) lista.push(limpia);
    };
    if (producto) {
        agregar(producto.imagen);
        if (Array.isArray(producto.imagenes)) producto.imagenes.forEach(agregar);
    }
    return lista;
}

function actualizarPreviewImagenPrincipal() {
    const container = document.getElementById('previewMainImg');
    const status = document.getElementById('imgPreviewMainStatus');
    if (status) {
        status.innerText = '';
        status.style.display = 'none';
    }
    if (!container) return;

    const urls = obtenerImagenesFormulario();
    if (urls.length === 0) {
        container.innerHTML = '';
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex';
    container.innerHTML = urls.map((url, idx) => `
        <div style="position: relative; display: inline-block;">
            <img src="${obtenerRutaPreview(url)}" alt="Imagen ${idx + 1}" style="height: 90px; width: 120px; object-fit: cover; border-radius: 6px; border: ${idx === 0 ? '2px solid #1a56db' : '1px solid #ccc'}; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" />
            ${idx === 0 ? '<span style="position: absolute; bottom: 4px; left: 4px; background: #1a56db; color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px;">Principal</span>' : ''}
            <button type="button" onclick="eliminarImagenPrincipalIndex(${idx})" title="Quitar imagen" style="position: absolute; top: -6px; right: -6px; background: #ff4d4d; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 11px; cursor: pointer; display: flex; align-items: center; justify-content: center;">✕</button>
        </div>
    `).join('');
}

function eliminarImagenPrincipalIndex(index) {
    const input = document.getElementById('imagenProd');
    if (!input) return;
    const urls = obtenerImagenesFormulario();
    urls.splice(index, 1);
    input.value = urls.join(', ');
    actualizarPreviewImagenPrincipal();
}

function eliminarImagenPrincipal() {
    const input = document.getElementById('imagenProd');
    const fileInput = document.getElementById('imagenProdFile');
    if (input) input.value = '';
    if (fileInput) fileInput.value = '';
    actualizarPreviewImagenPrincipal();
}

function subirImagenProducto(fileInput, targetInputId, containerId) {
    if (!fileInput.files || fileInput.files.length === 0) return;

    const files = Array.from(fileInput.files);
    const total = files.length;
    let subidas = 0;
    const nuevasUrls = [];

    const targetInput = document.getElementById(targetInputId);
    const status = document.getElementById('imgPreviewMainStatus');
    const mostrarStatus = (texto, color) => {
        if (!status) return;
        status.innerText = texto;
        status.style.color = color || '#2e7d32';
        status.style.display = 'block';
    };

    mostrarStatus('⏳ Subiendo imágenes (0/' + total + ')...', '#b45309');

    const finalizar = () => {
        if (targetInput && nuevasUrls.length) {
            const actuales = targetInput.value.split(',').map(u => u.trim()).filter(u => u);
            targetInput.value = actuales.concat(nuevasUrls).join(', ');
        }
        actualizarPreviewImagenPrincipal();
        if (nuevasUrls.length === total) {
            mostrarStatus('✅ ' + total + (total === 1 ? ' imagen subida' : ' imágenes subidas') + ' correctamente');
        } else {
            mostrarStatus('⚠️ Se subieron ' + nuevasUrls.length + ' de ' + total + ' imágenes', '#b45309');
        }
        fileInput.value = '';
    };

    files.forEach(file => {
        const formData = new FormData();
        formData.append('file', file);

        fetch('upload_imagen.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            subidas++;
            if (data.success) {
                nuevasUrls.push(data.url);
            } else {
                console.error('Error al subir una de las imágenes:', data.error);
            }
            mostrarStatus('⏳ Subiendo imágenes (' + subidas + '/' + total + ')...', '#b45309');
            if (subidas === total) finalizar();
        })
        .catch(err => {
            subidas++;
            console.error('Error al subir imagen:', err);
            mostrarStatus('⏳ Subiendo imágenes (' + subidas + '/' + total + ')...', '#b45309');
            if (subidas === total) finalizar();
        });
    });
}

// ==================== VENTAS PRESENCIALES ====================

function abrirModalVentaPresencial() {
    resetVentaPresencialForm();
    const modal = document.getElementById('modalVentaPresencial');
    if (modal) modal.style.display = 'flex';

    // Cargar productos y clientes
    cargarProductosParaCompras().then(prods => {
        const tbody = document.getElementById('ventaDetallesBody');
        if (tbody && tbody.children.length === 1 && tbody.children[0].id === 'ventaDetallesVacio') {
            agregarFilaDetalleVenta();
        }
    }).catch(err => {
        console.error('Error cargando productos para venta:', err);
    });

    cargarClientesParaVenta();
}

function cerrarModalVentaPresencial() {
    const modal = document.getElementById('modalVentaPresencial');
    if (modal) modal.style.display = 'none';
}

function resetVentaPresencialForm() {
    const clienteSelect = document.getElementById('selectVentaCliente');
    if (clienteSelect) clienteSelect.value = '';
    
    const clienteNombreInput = document.getElementById('inputVentaClienteNombre');
    if (clienteNombreInput) clienteNombreInput.value = 'Cliente Presencial';

    const fechaInput = document.getElementById('inputFechaVenta');
    if (fechaInput) {
        const ahora = new Date();
        const tzoffset = ahora.getTimezoneOffset() * 60000;
        const localISOTime = (new Date(ahora.getTime() - tzoffset)).toISOString().slice(0, 16);
        fechaInput.value = localISOTime;
    }

    const metodoPagoSelect = document.getElementById('selectVentaMetodoPago');
    if (metodoPagoSelect) metodoPagoSelect.value = 'Efectivo USD';

    const tbody = document.getElementById('ventaDetallesBody');
    if (tbody) {
        tbody.innerHTML = '<tr id="ventaDetallesVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>';
    }

    const tasaDisplay = document.getElementById('ventaTasaDisplay');
    if (tasaDisplay) tasaDisplay.textContent = `Bs ${parseFloat(tasaBCV || 0).toFixed(2)}`;

    const usdDisplay = document.getElementById('ventaTotalUsdDisplay');
    if (usdDisplay) usdDisplay.textContent = '$0.00 USD';

    const bsDisplay = document.getElementById('ventaTotalBsDisplay');
    if (bsDisplay) bsDisplay.textContent = 'Bs 0.00';

    const igtfInput = document.getElementById('inputVentaIgtf');
    if (igtfInput) igtfInput.value = '0.00';
}

// Promesa de la última carga del select de clientes del modal de venta
// presencial. El módulo de Historial la usa para preseleccionar al cliente
// que se está consultando, sin tener que abrir un segundo select a ciegas.
let promesaClientesParaVenta = null;

function cargarClientesParaVenta() {
    const select = document.getElementById('selectVentaCliente');
    if (!select) return Promise.resolve();
    select.innerHTML = '<option value="">-- Cliente de Mostrador / No Registrado --</option>';

    promesaClientesParaVenta = fetch('get_clientes.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.data)) {
                data.data.forEach(c => {
                    const option = document.createElement('option');
                    option.value = c.id;
                    const nombreStr = c.nombre_completo || c.usuario;
                    option.dataset.nombre = nombreStr;
                    option.textContent = `${nombreStr} (${c.email || c.usuario})`;
                    select.appendChild(option);
                });
            }
        })
        .catch(err => console.error("Error cargando clientes para venta:", err));

    return promesaClientesParaVenta;
}

function onClienteVentaSelected() {
    const select = document.getElementById('selectVentaCliente');
    const inputNombre = document.getElementById('inputVentaClienteNombre');
    if (!select || !inputNombre) return;

    if (select.value === '') {
        inputNombre.value = 'Cliente Presencial';
    } else {
        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.dataset.nombre) {
            inputNombre.value = selectedOption.dataset.nombre;
        }
    }
}

function agregarFilaDetalleVenta() {
    const tbody = document.getElementById('ventaDetallesBody');
    if (!tbody) return;

    const vacioRow = document.getElementById('ventaDetallesVacio');
    if (vacioRow) vacioRow.style.display = 'none';

    let optionsHTML = '<option value="">-- Seleccionar Producto --</option>';
    if (Array.isArray(productos)) {
        productos.forEach(p => {
            const stockVal = parseInt(p.stock || 0, 10);
            const precioVal = parseFloat(p.precio_usd || 0).toFixed(2);
            optionsHTML += `<option value="${p.id}" data-stock="${stockVal}" data-precio="${precioVal}">${p.nombre} (Stock: ${stockVal})</option>`;
        });
    }

    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select class="venta-producto-select" onchange="actualizarProductoVentaSelect(this)" style="width:100%; min-width:180px; padding:6px; border:1px solid #ddd; border-radius:6px;">
                ${optionsHTML}
            </select>
        </td>
        <td><span class="venta-stock-disp" style="font-weight:600; color:#64748b;">-</span></td>
        <td><input type="number" class="venta-cantidad" min="1" value="1" style="width:70px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalVenta(this)" /></td>
        <td><input type="number" class="venta-precio" min="0" step="0.01" value="0.00" style="width:90px; padding:6px; border:1px solid #ddd; border-radius:6px;" oninput="actualizarSubtotalVenta(this)" /></td>
        <td class="venta-subtotal" style="font-weight:700; color:#2563eb; padding:6px;">$0.00</td>
        <td><button type="button" style="padding:6px 10px; font-size:0.82rem; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer;" onclick="quitarFilaDetalleVenta(this)">✕</button></td>
    `;
    tbody.appendChild(tr);
    actualizarSubtotalVenta();
}

function actualizarProductoVentaSelect(selectElem) {
    const tr = selectElem.closest('tr');
    if (!tr) return;

    const stockSpan = tr.querySelector('.venta-stock-disp');
    const precioInput = tr.querySelector('.venta-precio');
    const selectedOption = selectElem.options[selectElem.selectedIndex];

    if (selectedOption && selectedOption.value !== '') {
        const stock = parseInt(selectedOption.dataset.stock || 0, 10);
        const precio = parseFloat(selectedOption.dataset.precio || 0);

        if (stockSpan) {
            if (stock <= 0) {
                stockSpan.textContent = '0 (Agotado)';
                stockSpan.style.color = '#dc2626';
            } else {
                stockSpan.textContent = stock;
                stockSpan.style.color = '#16a34a';
            }
        }
        if (precioInput) {
            precioInput.value = precio.toFixed(2);
        }
    } else {
        if (stockSpan) {
            stockSpan.textContent = '-';
            stockSpan.style.color = '#64748b';
        }
        if (precioInput) {
            precioInput.value = '0.00';
        }
    }
    actualizarSubtotalVenta(selectElem);
}

function quitarFilaDetalleVenta(btn) {
    const tr = btn.closest('tr');
    if (tr) tr.remove();

    const tbody = document.getElementById('ventaDetallesBody');
    if (tbody) {
        const lineas = tbody.querySelectorAll('tr:not(#ventaDetallesVacio)');
        if (lineas.length === 0) {
            const vacioRow = document.getElementById('ventaDetallesVacio');
            if (vacioRow) vacioRow.style.display = '';
        }
    }
    actualizarSubtotalVenta();
}

function actualizarSubtotalVenta() {
    const tbody = document.getElementById('ventaDetallesBody');
    if (!tbody) return;

    let totalUSD = 0;
    const lineas = tbody.querySelectorAll('tr:not(#ventaDetallesVacio)');

    lineas.forEach(tr => {
        const select = tr.querySelector('.venta-producto-select');
        const cantInput = tr.querySelector('.venta-cantidad');
        const precioInput = tr.querySelector('.venta-precio');
        const subtotalSpan = tr.querySelector('.venta-subtotal');
        const stockSpan = tr.querySelector('.venta-stock-disp');

        const cant = parseInt(cantInput?.value || 0, 10);
        const precio = parseFloat(precioInput?.value || 0);
        const selectedOpt = select ? select.options[select.selectedIndex] : null;
        const stockAvailable = selectedOpt ? parseInt(selectedOpt.dataset.stock || 0, 10) : 0;

        if (cant > stockAvailable && selectedOpt && selectedOpt.value !== '') {
            cantInput.style.borderColor = '#dc2626';
            cantInput.style.backgroundColor = '#fef2f2';
        } else {
            cantInput.style.borderColor = '#ddd';
            cantInput.style.backgroundColor = '#fff';
        }

        const subtotal = cant > 0 && precio >= 0 ? cant * precio : 0;
        totalUSD += subtotal;

        if (subtotalSpan) {
            subtotalSpan.textContent = `$${subtotal.toFixed(2)}`;
        }
    });

    const rate = parseFloat(tasaBCV || 0);
    const totalBS = totalUSD * rate;

    const tasaDisplay = document.getElementById('ventaTasaDisplay');
    if (tasaDisplay) tasaDisplay.textContent = `Bs ${rate.toFixed(2)}`;

    const usdDisplay = document.getElementById('ventaTotalUsdDisplay');
    if (usdDisplay) usdDisplay.textContent = `$${totalUSD.toFixed(2)} USD`;

    const bsDisplay = document.getElementById('ventaTotalBsDisplay');
    if (bsDisplay) bsDisplay.textContent = `Bs ${totalBS.toFixed(2)}`;
}

function toggleVentaApartadoPresencial(checked) {
    const sec = document.getElementById('seccionCamposApartadoPresencial');
    if (!sec) return;
    sec.style.display = checked ? 'grid' : 'none';
    if (checked) {
        // Calcular abono sugerido 30% del total actual
        const totalUsdTxt = document.getElementById('ventaTotalUsdDisplay')?.textContent || '$0.00';
        const totalUsdVal = parseFloat(totalUsdTxt.replace(/[^0-9.]/g, '') || 0);
        const inputAbono = document.getElementById('inputVentaAbonoInicialUsd');
        if (inputAbono && !inputAbono.value && totalUsdVal > 0) {
            inputAbono.value = (totalUsdVal * 0.30).toFixed(2);
        }
    }
}

function enviarVentaPresencial() {
    const clienteId = document.getElementById('selectVentaCliente')?.value || '';
    const clienteNombre = document.getElementById('inputVentaClienteNombre')?.value.trim() || 'Cliente Presencial';
    const fechaVenta = document.getElementById('inputFechaVenta')?.value || '';
    const metodoPago = document.getElementById('selectVentaMetodoPago')?.value || 'Efectivo USD';

    const tbody = document.getElementById('ventaDetallesBody');
    if (!tbody) return;

    const lineas = tbody.querySelectorAll('tr:not(#ventaDetallesVacio)');
    if (lineas.length === 0) {
        mostrarNotificacion('Debes agregar al menos un producto a la venta.', true);
        return;
    }

    const detalles = [];
    let hayErrorStock = false;
    let prodErrorNombre = '';

    for (let tr of lineas) {
        const select = tr.querySelector('.venta-producto-select');
        const cantInput = tr.querySelector('.venta-cantidad');
        const precioInput = tr.querySelector('.venta-precio');

        const pId = parseInt(select?.value || 0, 10);
        const cant = parseInt(cantInput?.value || 0, 10);
        const precio = parseFloat(precioInput?.value || 0);

        if (!pId) {
            mostrarNotificacion('Selecciona un producto en todas las filas.', true);
            return;
        }
        if (cant <= 0) {
            mostrarNotificacion('La cantidad debe ser mayor a 0.', true);
            return;
        }

        const selectedOpt = select.options[select.selectedIndex];
        const stockAvailable = parseInt(selectedOpt?.dataset.stock || 0, 10);

        if (cant > stockAvailable) {
            hayErrorStock = true;
            prodErrorNombre = selectedOpt.textContent;
            break;
        }

        detalles.push({
            producto_id: pId,
            cantidad: cant,
            precio_unitario: precio
        });
    }

    if (hayErrorStock) {
        mostrarNotificacion(`⚠️ La cantidad supera el stock disponible de: ${prodErrorNombre}`, true);
        return;
    }

    const esApartado = document.getElementById('checkVentaEsApartado')?.checked || false;
    const abonoInicialUsd = parseFloat(document.getElementById('inputVentaAbonoInicialUsd')?.value || 0);
    const apartadoDias = parseInt(document.getElementById('inputVentaApartadoDias')?.value || 15, 10);
    const apartadoRef = document.getElementById('inputVentaApartadoRef')?.value.trim() || 'Abono Inicial Presencial';

    const payload = {
        cliente_id: clienteId ? parseInt(clienteId, 10) : null,
        cliente_nombre: clienteNombre,
        fecha_venta: fechaVenta,
        metodo_pago: metodoPago,
        tasa_cambio: tasaBCV,
        igtf_usd: parseFloat(document.getElementById('inputVentaIgtf')?.value || 0) || 0,
        detalles: detalles,
        es_apartado: esApartado,
        monto_abono_inicial_usd: abonoInicialUsd,
        dias_limite: apartadoDias,
        apartado_referencia: apartadoRef
    };

    const btnSubmit = document.getElementById('btnRegistrarVenta');
    if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Procesando...';
    }

    fetch('save_venta_presencial.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion(data.message || '✅ Venta registrada con éxito');
            cerrarModalVentaPresencial();
            if (typeof cargarInventario === 'function') cargarInventario();
            if (typeof cargarStats === 'function') cargarStats();
            cargarProductosParaCompras();
            if (typeof refrescarHistorialSiEstaAbierto === 'function') refrescarHistorialSiEstaAbierto();
        } else {
            alert('❌ Error al registrar la venta: ' + (data.error || 'Error desconocido'));
        }
    })
    .catch(err => {
        console.error("Error en enviarVentaPresencial:", err);
        alert('❌ Error de conexión al registrar la venta.');
    })
    .finally(() => {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.textContent = '✅ Registrar Venta';
        }
    });
}

// ==================== NUEVO CLIENTE ADMIN ====================

function abrirModalNuevoCliente() {
    const modal = document.getElementById('modalNuevoCliente');
    if (modal) modal.style.display = 'flex';

    // Limpiar campos
    const nom = document.getElementById('clienteNombre'); if (nom) nom.value = '';
    const tel = document.getElementById('clienteTelefono'); if (tel) tel.value = '';
    const ced = document.getElementById('clienteCedula'); if (ced) ced.value = '';
    const eml = document.getElementById('clienteEmail'); if (eml) eml.value = '';
    const usu = document.getElementById('clienteUsuario'); if (usu) usu.value = '';
    const pas = document.getElementById('clientePassword'); if (pas) pas.value = '12345678';
    const pre = document.getElementById('clientePreguntaSeguridad'); if (pre) pre.value = '';
    const res = document.getElementById('clienteRespuestaSeguridad'); if (res) res.value = '';

    // El modal se muestra de golpe: hay que preparar aqui el boton de
    // mostrar contraseña, porque el script global ya corrió al cargar la pagina.
    if (typeof window.aplicarTogglePassword === 'function') {
        window.aplicarTogglePassword();
    }
}

function cerrarModalNuevoCliente() {
    const modal = document.getElementById('modalNuevoCliente');
    if (modal) modal.style.display = 'none';
}

function guardarClienteAdmin() {
    const nombre = document.getElementById('clienteNombre')?.value.trim() || '';
    const prefijoTelefono = document.getElementById('clientePrefijoTelefono')?.value || '0412';
    const numeroTelefono = document.getElementById('clienteTelefono')?.value.trim() || '';
    const numeroCedula = document.getElementById('clienteCedula')?.value.trim() || '';
    const email = document.getElementById('clienteEmail')?.value.trim() || '';
    const usuario = document.getElementById('clienteUsuario')?.value.trim() || '';
    const password = document.getElementById('clientePassword')?.value || '';

    if (!nombre) {
        mostrarNotificacion('Ingresa el Nombre y Apellido del cliente.', true);
        return;
    }
    if (!numeroTelefono) {
        mostrarNotificacion('Ingresa el número de teléfono del cliente.', true);
        return;
    }
    if (!numeroCedula) {
        mostrarNotificacion('Ingresa la cédula del cliente.', true);
        return;
    }
    if (!usuario) {
        mostrarNotificacion('Ingresa un usuario para el cliente.', true);
        return;
    }
    if (!password) {
        mostrarNotificacion('Ingresa una contraseña para el cliente.', true);
        return;
    }
    const preguntaSeguridad = document.getElementById('clientePreguntaSeguridad')?.value || '';
    const respuestaSeguridad = document.getElementById('clienteRespuestaSeguridad')?.value.trim() || '';

    if (!preguntaSeguridad) {
        mostrarNotificacion('Elige una pregunta de seguridad para el cliente.', true);
        return;
    }
    if (respuestaSeguridad.length < 3) {
        mostrarNotificacion('La respuesta de seguridad debe tener al menos 3 caracteres.', true);
        return;
    }

    const payload = {
        nombre_completo: nombre,
        prefijo_telefono: prefijoTelefono,
        numero_telefono: numeroTelefono,
        numero_cedula: numeroCedula,
        email: email,
        usuario: usuario,
        password: password,
        pregunta_seguridad_id: preguntaSeguridad,
        respuesta_seguridad: respuestaSeguridad
    };

    const btn = document.getElementById('btnGuardarClienteAdmin');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Guardando...';
    }

    fetch('save_cliente.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion(data.message || '✅ Cliente registrado con éxito');
            cerrarModalNuevoCliente();
            cargarClientes();
            if (typeof cargarClientesParaVenta === 'function') {
                cargarClientesParaVenta();
            }
        } else {
            alert('❌ Error al registrar cliente: ' + (data.error || 'Error desconocido'));
        }
    })
    .catch(err => {
        console.error("Error en guardarClienteAdmin:", err);
        alert('❌ Error de conexión al registrar el cliente.');
    })
    .finally(() => {
        if (btn) {
            btn.disabled = false;
            btn.textContent = '✅ Guardar Cliente';
        }
    });
}

// ==================== GESTIÓN DE PERFIL ADMINISTRADOR ====================
function showAdminAlert(message, type = 'success') {
    const alertBox = document.getElementById('adminProfileAlert');
    if (!alertBox) return;
    alertBox.textContent = message;
    alertBox.style.display = 'block';
    alertBox.style.background = type === 'success' ? '#d4edda' : '#f8d7da';
    alertBox.style.color = type === 'success' ? '#155724' : '#721c24';
    alertBox.style.border = type === 'success' ? '1px solid #c3e6cb' : '1px solid #f5c6cb';
    setTimeout(() => {
        alertBox.style.display = 'none';
    }, 5000);
}

function cargarPerfilAdmin() {
    fetch('../api/get_perfil.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.profile) {
                const p = data.profile;
                const usrInp = document.getElementById('adminUsuario');
                const nomInp = document.getElementById('adminNombreCompleto');
                const emlInp = document.getElementById('adminEmail');
                const prefSel = document.getElementById('prefijoAdminTelefono');
                const telInp = document.getElementById('adminTelefonoNum');

                if (usrInp) usrInp.value = p.usuario || '';
                if (nomInp) nomInp.value = p.nombre_completo || '';
                if (emlInp) emlInp.value = p.email || '';

                if (p.telefono && p.telefono.length >= 10) {
                    const pref = p.telefono.substring(0, 4);
                    const num = p.telefono.substring(4);
                    if (prefSel && [...prefSel.options].some(o => o.value === pref)) {
                        prefSel.value = pref;
                        if (telInp) telInp.value = num;
                    } else {
                        if (telInp) telInp.value = p.telefono;
                    }
                } else {
                    if (telInp) telInp.value = p.telefono || '';
                }

                // Aviso si el admin aún no tiene pregunta de seguridad configurada.
                const avisoSeguridad = document.getElementById('adminAvisoSeguridad');
                if (avisoSeguridad) {
                    avisoSeguridad.style.display = p.tiene_pregunta_seguridad ? 'none' : 'block';
                }

                if (p.negocio) {
                    const negNom = document.getElementById('negocioNombre');
                    const negTel = document.getElementById('negocioTelefono');
                    const negHor = document.getElementById('negocioHorario');
                    const negUbi = document.getElementById('negocioUbicacion');

                    if (negNom) negNom.value = p.negocio.nombre || '';
                    if (negTel) negTel.value = p.negocio.telefono || '';
                    if (negHor) negHor.value = p.negocio.horario || '';
                    if (negUbi) negUbi.value = p.negocio.ubicacion || '';

                    if (p.negocio.nombre && p.negocio.nombre.trim() !== '') {
                        const nomSistema = window.SISTEMA_NOMBRE || 'EasyStore';
                        const adminBrandEl = document.getElementById('adminBrand') || document.querySelector('.admin-brand');
                        if (adminBrandEl) {
                            adminBrandEl.innerHTML = `🛍️ ${nomSistema} <span>| ${p.negocio.nombre}</span>`;
                        }
                        document.title = `${nomSistema} · Panel Administrativo | ${p.negocio.nombre}`;
                    }
                }

                if (p.fiscal) {
                    const f = p.fiscal;
                    const rifEl = document.getElementById('negocioRif');
                    const dirEl = document.getElementById('negocioDireccionFiscal');
                    const ivaEl = document.getElementById('negocioIvaGeneral');
                    const igtfEl = document.getElementById('negocioIgtfPct');
                    const agEl = document.getElementById('negocioAgenteRetencion');
                    const retEl = document.getElementById('negocioRetencionPct');

                    if (rifEl) rifEl.value = f.rif || '';
                    if (dirEl) dirEl.value = f.direccion_fiscal || '';
                    if (ivaEl) ivaEl.value = f.iva_general_pct ?? 16;
                    if (igtfEl) igtfEl.value = f.igtf_pct ?? 3;
                    if (agEl) agEl.value = String(f.es_agente_retencion ? 1 : 0);
                    if (retEl) retEl.value = f.retencion_iva_pct ?? 75;
                }

                if (p.bancos) {
                    const pmB = document.getElementById('pmBanco');
                    const pmT = document.getElementById('pmTelefono');
                    const pmC = document.getElementById('pmCedula');
                    const trB = document.getElementById('transfBanco');
                    const trC = document.getElementById('transfCuenta');
                    const trT = document.getElementById('transfTitular');
                    const trId = document.getElementById('transfCedula');
                    const zC = document.getElementById('zelleCorreo');
                    const zT = document.getElementById('zelleTitular');
                    const bB = document.getElementById('biopagoBanco');
                    const bC = document.getElementById('biopagoCedula');
                    const bT = document.getElementById('biopagoTitular');

                    if (pmB) pmB.value = p.bancos.pm_banco || '';
                    if (pmT) pmT.value = p.bancos.pm_telefono || '';
                    if (pmC) pmC.value = p.bancos.pm_cedula || '';
                    if (trB) trB.value = p.bancos.transf_banco || '';
                    if (trC) trC.value = p.bancos.transf_cuenta || '';
                    if (trT) trT.value = p.bancos.transf_titular || '';
                    if (trId) trId.value = p.bancos.transf_cedula || '';
                    if (zC) zC.value = p.bancos.zelle_correo || '';
                    if (zT) zT.value = p.bancos.zelle_titular || '';
                    if (bB) bB.value = p.bancos.biopago_banco || '';
                    if (bC) bC.value = p.bancos.biopago_cedula || '';
                    if (bT) bT.value = p.bancos.biopago_titular || '';
                }
            } else {
                showAdminAlert(data.message || 'Error al cargar perfil', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showAdminAlert('Error de conexión al obtener el perfil', 'error');
        });
}

function guardarNegocioAdmin(e) {
    if (e) e.preventDefault();
    const payload = {
        type: 'negocio',
        negocio_nombre: document.getElementById('negocioNombre').value.trim(),
        negocio_telefono: document.getElementById('negocioTelefono').value.trim(),
        negocio_horario: document.getElementById('negocioHorario').value.trim(),
        negocio_ubicacion: document.getElementById('negocioUbicacion').value.trim(),
        negocio_rif: document.getElementById('negocioRif')?.value.trim() || '',
        direccion_fiscal: document.getElementById('negocioDireccionFiscal')?.value.trim() || '',
        iva_general_pct: parseFloat(document.getElementById('negocioIvaGeneral')?.value || 16) || 0,
        igtf_pct: parseFloat(document.getElementById('negocioIgtfPct')?.value || 0) || 0,
        es_agente_retencion: (document.getElementById('negocioAgenteRetencion')?.value === '1') ? 1 : 0,
        retencion_iva_pct: parseFloat(document.getElementById('negocioRetencionPct')?.value || 0) || 0
    };

    fetch('../api/update_perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
            if (payload.negocio_nombre) {
                const adminBrandEl = document.getElementById('adminBrand') || document.querySelector('.admin-brand');
                if (adminBrandEl) {
                    adminBrandEl.innerHTML = `🚀 ${payload.negocio_nombre} <span>| Admin</span>`;
                }
                document.title = `Panel Administrativo | ${payload.negocio_nombre}`;
            }
        } else {
            showAdminAlert(data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de conexión al actualizar el negocio', 'error');
    });
}

/**
 * Guarda la configuración global de apartados (cuotas a pagar y días límite)
 * que se edita en el módulo Mi Cuenta.
 */
function guardarConfigApartado(e) {
    if (e) e.preventDefault();
    const cuotas = parseInt(document.getElementById('cfgApartadoCuotas')?.value || 0, 10);
    const dias = parseInt(document.getElementById('cfgApartadoDias')?.value || 0, 10);
    const mensaje = document.getElementById('cfgApartadoMensaje');
    const btn = document.getElementById('btnGuardarConfigApartado');

    const avisar = (texto, ok) => {
        if (mensaje) {
            mensaje.textContent = texto;
            mensaje.style.color = ok ? '#15803d' : '#b91c1c';
            if (ok) setTimeout(() => { if (mensaje) mensaje.textContent = ''; }, 6000);
        }
    };

    if (!cuotas || cuotas < 1 || cuotas > 12) {
        avisar('⚠️ Las cuotas deben estar entre 1 y 12.', false);
        return;
    }
    if (!dias || dias < 1 || dias > 90) {
        avisar('⚠️ El límite de pago debe estar entre 1 y 90 días.', false);
        return;
    }

    if (btn) { btn.disabled = true; btn.innerText = 'Guardando...'; }

    fetch('../api/update_perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'apartado',
            apartado_cuotas: cuotas,
            apartado_dias_limite: dias
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const guardado = data.apartado || { cuotas: cuotas, dias_limite: dias };

            // Se refrescan los avisos usados en la venta presencial.
            const inputDias = document.getElementById('inputVentaApartadoDias');
            if (inputDias) inputDias.value = guardado.dias_limite;
            const avisoCuotas = document.getElementById('ventaApartadoPlanCuotas');
            if (avisoCuotas) avisoCuotas.textContent = `${guardado.cuotas} cuotas`;
            const avisoDias = document.getElementById('ventaApartadoPlanDias');
            if (avisoDias) avisoDias.textContent = `${guardado.dias_limite} días`;

            // Se mantiene sincronizado el valor que usan los planes de cuotas.
            window.configApartado = { cuotas: guardado.cuotas, dias_limite: guardado.dias_limite };

            avisar('✅ ' + (data.message || 'Configuración guardada.'), true);
            showAdminAlert(data.message || 'Configuración de apartados guardada.', 'success');
        } else {
            avisar('⚠️ ' + (data.message || 'No se pudo guardar.'), false);
            showAdminAlert(data.message || 'No se pudo guardar la configuración.', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        avisar('⚠️ Error de conexión con el servidor.', false);
        showAdminAlert('Error de conexión al guardar la configuración de apartados', 'error');
    })
    .finally(() => {
        if (btn) { btn.disabled = false; btn.innerText = '💾 Guardar Configuración'; }
    });
}

function guardarBancosAdmin(e) {
    if (e) e.preventDefault();
    const payload = {
        type: 'bancos',
        pm_banco: document.getElementById('pmBanco').value.trim(),
        pm_telefono: document.getElementById('pmTelefono').value.trim(),
        pm_cedula: document.getElementById('pmCedula').value.trim(),
        transf_banco: document.getElementById('transfBanco').value.trim(),
        transf_cuenta: document.getElementById('transfCuenta').value.trim(),
        transf_titular: document.getElementById('transfTitular').value.trim(),
        transf_cedula: document.getElementById('transfCedula').value.trim(),
        zelle_correo: document.getElementById('zelleCorreo').value.trim(),
        zelle_titular: document.getElementById('zelleTitular').value.trim(),
        biopago_banco: document.getElementById('biopagoBanco').value.trim(),
        biopago_cedula: document.getElementById('biopagoCedula').value.trim(),
        biopago_titular: document.getElementById('biopagoTitular').value.trim()
    };

    fetch('../api/update_perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
        } else {
            showAdminAlert(data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de conexión al actualizar datos bancarios', 'error');
    });
}

function guardarPerfilAdmin(e) {
    if (e) e.preventDefault();
    const nombre = document.getElementById('adminNombreCompleto').value.trim();
    const email = document.getElementById('adminEmail').value.trim();
    const prefijo = document.getElementById('prefijoAdminTelefono').value;
    const numTel = document.getElementById('adminTelefonoNum').value.trim();
    const telefono = numTel ? (prefijo + numTel) : '';

    const payload = {
        nombre_completo: nombre,
        email: email,
        telefono: telefono
    };

    fetch('../api/update_perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
            const welcomeMsg = document.querySelector('.admin-user-welcome');
            if (welcomeMsg) {
                welcomeMsg.textContent = 'Bienvenido, ' + nombre;
            }
        } else {
            showAdminAlert(data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de conexión al actualizar el perfil', 'error');
    });
}

function guardarSeguridadAdmin(e) {
    if (e) e.preventDefault();
    const pregunta = document.getElementById('adminPreguntaSeguridad')?.value || '';
    const respuesta = document.getElementById('adminRespuestaSeguridad')?.value.trim() || '';
    const actual = document.getElementById('adminPasswordActualSeguridad')?.value || '';

    if (!pregunta) {
        showAdminAlert('Selecciona una pregunta de seguridad', 'error');
        return;
    }
    if (respuesta.length < 3) {
        showAdminAlert('La respuesta debe tener al menos 3 caracteres', 'error');
        return;
    }
    if (!actual) {
        showAdminAlert('Ingresa tu contraseña actual para confirmar el cambio', 'error');
        return;
    }

    fetch('../api/update_perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'seguridad',
            pregunta_seguridad_id: pregunta,
            respuesta_seguridad: respuesta,
            password_actual: actual
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
            document.getElementById('adminRespuestaSeguridad').value = '';
            document.getElementById('adminPasswordActualSeguridad').value = '';
            if (document.getElementById('adminPasswordActualSeguridad').type === 'text') {
                document.getElementById('adminPasswordActualSeguridad').type = 'password';
            }
            const aviso = document.getElementById('adminAvisoSeguridad');
            if (aviso) aviso.style.display = 'none';
        } else {
            showAdminAlert(data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de conexión al guardar la pregunta de seguridad', 'error');
    });
}

function guardarPasswordAdmin(e) {
    if (e) e.preventDefault();
    const actual = document.getElementById('adminPasswordActual').value;
    const nueva = document.getElementById('adminNuevaPassword').value;
    const confirmar = document.getElementById('adminConfirmarPassword').value;

    if (!actual) {
        showAdminAlert('Ingresa tu contraseña actual', 'error');
        return;
    }
    if (!nueva || nueva.length < 8) {
        showAdminAlert('La nueva contraseña debe tener al menos 8 caracteres', 'error');
        return;
    }
    if (!/[0-9]/.test(nueva) || !/[a-zA-Z]/.test(nueva)) {
        showAdminAlert('La nueva contraseña debe incluir letras y números', 'error');
        return;
    }
    if (nueva !== confirmar) {
        showAdminAlert('La nueva contraseña y su confirmación no coinciden', 'error');
        return;
    }

    // Limpia los campos de clave al guardar.
    ['adminPasswordActual', 'adminNuevaPassword', 'adminConfirmarPassword'].forEach(id => {
        const campo = document.getElementById(id);
        if (!campo) return;
        campo.value = '';
        if (campo.type === 'text') campo.type = 'password';
    });

    const nombre = document.getElementById('adminNombreCompleto').value.trim();
    const email = document.getElementById('adminEmail').value.trim();
    const prefijo = document.getElementById('prefijoAdminTelefono').value;
    const numTel = document.getElementById('adminTelefonoNum').value.trim();
    const telefono = numTel ? (prefijo + numTel) : '';

    const payload = {
        nombre_completo: nombre,
        email: email,
        telefono: telefono,
        password_actual: actual,
        nueva_password: nueva,
        confirmar_password: confirmar
    };

    fetch('../api/update_perfil.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
            document.getElementById('adminPasswordForm').reset();
        } else {
            showAdminAlert(data.message, 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de conexión al actualizar la contraseña', 'error');
    });
}

// RESTAURAR BASE DE DATOS (.SQL)
function restaurarBaseDeDatos(e) {
    if (e) e.preventDefault();
    const fileInput = document.getElementById('sqlFileInput');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showAdminAlert('Por favor selecciona un archivo .sql de respaldo.', 'error');
        return;
    }

    if (!confirm('⚠️ ¿Estás seguro de que deseas restaurar la base de datos? Esta acción sobreescribirá la información actual del sistema con la del archivo seleccionado.')) {
        return;
    }

    const btn = document.getElementById('btnRestoreSubmit');
    if (btn) {
        btn.disabled = true;
        btn.innerText = '⌛ Restaurando... Por favor espera';
    }

    const formData = new FormData();
    formData.append('sql_file', fileInput.files[0]);

    fetch('../api/restore_db.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerText = '📤 Restaurar BD';
        }

        if (data.success) {
            showAdminAlert(data.message + ' Recargando la página...', 'success');
            fileInput.value = '';
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showAdminAlert(data.message || 'Error al restaurar la base de datos.', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        if (btn) {
            btn.disabled = false;
            btn.innerText = '📤 Restaurar BD';
        }
        showAdminAlert('Error de conexión al restaurar la base de datos.', 'error');
    });
}


// ==================== MÓDULO DE CIERRE DE CAJA Y ARQUEO DIARIO ====================

let turnoCajaActivoCache = null;
let saldoEsperadoCajaCache = 0.00;
let acumulacionMetodosCache = null; // desglose por método para la conciliación del cierre

function cargarSeccionCaja() {
    const banner = document.getElementById('cajaStatusBanner');
    const headerAcciones = document.getElementById('cajaHeaderAcciones');
    const tbody = document.getElementById('cajaHistorialTableBody');

    if (banner) banner.innerHTML = '<div style="text-align:center; padding:20px; color:#aaa;">Cargando turno de caja...</div>';

    fetch('get_caja_turno.php')
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success) {
                if (banner) {
                    banner.innerHTML = `
                        <div style="text-align:center; padding:30px 10px;">
                            <span style="font-size:3rem; display:block; margin-bottom:10px;">🔒</span>
                            <h3 style="margin:0 0 8px 0; color:#555;">La Caja se Encuentra Cerrada</h3>
                            <p style="margin:0 0 20px 0; color:#888; font-size:0.95rem;">
                                Para comenzar a registrar ventas presenciales y cobros en efectivo, abre un nuevo turno de caja.
                            </p>
                            <button type="button" onclick="abrirNuevaCaja()" class="btn-primary" style="background:#2ecc71; border:none; padding:12px 24px; border-radius:8px; font-weight:700; color:#fff; cursor:pointer;">
                                🔓 Abrir Nuevo Turno de Caja
                            </button>
                        </div>
                    `;
                }
                return;
            }

            const turno = data.turno_activo;
            const enVivo = data.resumen_en_vivo;
            turnoCajaActivoCache = turno;

            if (turno && enVivo) {
                saldoEsperadoCajaCache = parseFloat(enVivo.total_esperado_efectivo_usd);
                acumulacionMetodosCache = (enVivo.acumulacion || null);
                const fechaAp = new Date(turno.fecha_apertura).toLocaleString('es-VE', {dateStyle:'medium', timeStyle:'short'});

                if (headerAcciones) {
                    headerAcciones.innerHTML = `
                        <button type="button" onclick="abrirModalCierreCaja()" class="btn-primary" style="background:#e74c3c; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:700; color:#fff;">
                            🔒 Arqueo y Cierre de Caja
                        </button>
                    `;
                }

                if (banner) {
                    banner.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                            <div>
                                <span style="background:#2ecc71; color:#fff; padding:4px 10px; border-radius:20px; font-weight:700; font-size:0.8rem;">● CAJA ABIERTA</span>
                                <span style="margin-left:10px; font-weight:600; color:#555;">Turno #${turno.id} | Iniciado: ${fechaAp}</span>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
                            <div style="background:rgba(46,204,113,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#27ae60; font-weight:600;">Ventas en Efectivo USD</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#27ae60;">+$${parseFloat(enVivo.ventas_efectivo_usd).toFixed(2)}</h4>
                            </div>
                            <div style="background:rgba(14,165,233,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#0284c7; font-weight:600;">Ventas en Efectivo Bs</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#0284c7;">Bs ${parseFloat(enVivo.ventas_efectivo_bs || 0).toLocaleString('es-VE', {minimumFractionDigits:2})}</h4>
                            </div>
                            <div style="background:rgba(54,162,235,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#1565c0; font-weight:600;">Pago Móvil</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#1565c0;">$${parseFloat(enVivo.ventas_pago_movil_usd).toFixed(2)}</h4>
                            </div>
                            <div style="background:rgba(155,89,182,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#8e44ad; font-weight:600;">Transferencias</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#8e44ad;">$${parseFloat(enVivo.ventas_transferencia_usd).toFixed(2)}</h4>
                            </div>
                            <div style="background:rgba(230,126,34,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#ca6f1e; font-weight:600;">Zelle</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#ca6f1e;">$${parseFloat(enVivo.ventas_zelle_usd || 0).toFixed(2)}</h4>
                            </div>
                            <div style="background:rgba(22,160,133,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#148f77; font-weight:600;">Biopago</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#148f77;">$${parseFloat(enVivo.ventas_biopago_usd || 0).toFixed(2)}</h4>
                            </div>
                            <div style="background:rgba(107,114,128,0.1); padding:14px; border-radius:10px;">
                                <span style="font-size:0.8rem; color:#6b7280; font-weight:600;">Punto de Venta</span>
                                <h4 style="margin:4px 0 0 0; font-size:1.3rem; color:#6b7280;">$${parseFloat(enVivo.ventas_punto_venta_usd || 0).toFixed(2)}</h4>
                            </div>
                        </div>

                        ${(() => {
                            // Saldo esperado negativo: el cajon no alcanza para
                            // cubrir los reembolsos en efectivo de este turno.
                            const esperado = parseFloat(enVivo.total_esperado_efectivo_usd);
                            const reembolsos = parseFloat(enVivo.reembolsos_efectivo_usd || 0);
                            const detalle = [];
                            detalle.push('Ventas en efectivo - reembolsos en efectivo = $' + esperado.toFixed(2));
                            if (esperado < 0) {
                                return `
                                    <div style="margin-top:16px; background:#fef2f2; border:1px solid #fecaca; border-left:5px solid #dc2626; padding:14px 16px; border-radius:10px;">
                                        <strong style="color:#991b1b;">⚠️ El saldo esperado es negativo</strong>
                                        <div style="font-size:0.85rem; color:#7f1d1d; margin-top:6px; line-height:1.6;">
                                            ${detalle.join('<br>')}<br>
                                            El cajón no tiene efectivo suficiente para cubrir los reembolsos de $${reembolsos.toFixed(2)}.
                                            Suele pasar cuando el cliente pagó el pedido por pago móvil o transferencia y
                                            devuelve en efectivo. <strong>Fondos el cajón antes de continuar</strong> y,
                                            cuando ya cuadre, cuenta el efectivo real para que el arqueo dé cero.
                                        </div>
                                        <div style="font-size:0.78rem; color:#7f1d1d; margin-top:8px; padding-top:8px; border-top:1px solid #fecaca;">
                                            Ojo: solo los reembolsos en <strong>efectivo</strong> salen del cajón.
                                            Los de pago móvil, transferencia o crédito se devuelven por esa vía y
                                            <strong>no</strong> afectan el saldo de caja.
                                        </div>
                                    </div>`;
                            }
                            if (reembolsos > 0) {
                                return `
                                    <div style="margin-top:16px; background:#eff6ff; border:1px solid #bfdbfe; border-left:5px solid #3b82f6; padding:14px 16px; border-radius:10px;">
                                        <strong style="color:#1e3a8a;">ℹ️ Hay reembolsos en efectivo en este turno</strong>
                                        <div style="font-size:0.85rem; color:#1e40af; margin-top:6px; line-height:1.6;">
                                            ${detalle.join('<br>')}<br>
                                            Los $${reembolsos.toFixed(2)} devueltos ya están descontados del saldo esperado.
                                            Los reembolsos por pago móvil, transferencia o crédito <strong>no</strong> afectan el cajón.
                                        </div>
                                    </div>`;
                            }
                            return '';
                        })()}
                    `;
                }
            } else {
                acumulacionMetodosCache = null;
                saldoEsperadoCajaCache = 0;
                if (headerAcciones) {
                    headerAcciones.innerHTML = `
                        <button type="button" onclick="abrirNuevaCaja()" class="btn-primary" style="background:#2ecc71; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:700; color:#fff;">
                            🔓 Abrir Nuevo Turno de Caja
                        </button>
                    `;
                }

                if (banner) {
                    banner.innerHTML = `
                        <div style="text-align:center; padding:30px 10px;">
                            <span style="font-size:3rem; display:block; margin-bottom:10px;">🔒</span>
                            <h3 style="margin:0 0 8px 0; color:#555;">La Caja se Encuentra Cerrada</h3>
                            <p style="margin:0 0 20px 0; color:#888; font-size:0.95rem;">
                                Para comenzar a registrar ventas presenciales y cobros en efectivo, abre un nuevo turno de caja.
                            </p>
                            <button type="button" onclick="abrirNuevaCaja()" class="btn-primary" style="background:#2ecc71; border:none; padding:12px 24px; border-radius:8px; font-weight:700; color:#fff; cursor:pointer;">
                                🔓 Abrir Nuevo Turno de Caja
                            </button>
                        </div>
                    `;
                }
            }

            // Historial de Cierres de Caja
            if (tbody) {
                if (!data.historial || !data.historial.length) {
                    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; color:#aaa; padding:20px;">No hay cierres de caja registrados.</td></tr>';
                    return;
                }

                tbody.innerHTML = data.historial.map(h => {
                    const fApertura = new Date(h.fecha_apertura).toLocaleString('es-VE', {dateStyle:'short', timeStyle:'short'});
                    const fCierre = h.fecha_cierre ? new Date(h.fecha_cierre).toLocaleString('es-VE', {dateStyle:'short', timeStyle:'short'}) : 'En curso';
                    
                    const dif = parseFloat(h.diferencia_usd);
                    const difBadge = h.estado === 'ABIERTA' ? '—' :
                                     dif === 0 ? '<span style="color:#2ecc71; font-weight:700;">$0.00 (Cuadrada)</span>' :
                                     dif < 0 ? `<span style="color:#e74c3c; font-weight:700;">-$${Math.abs(dif).toFixed(2)} (Faltante)</span>` :
                                     `<span style="color:#f39c12; font-weight:700;">+$${dif.toFixed(2)} (Sobrante)</span>`;

                    const estadoBadge = h.estado === 'ABIERTA' ?
                        '<span style="background:#e8f5e9; color:#2e7d32; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem;">ABIERTA</span>' :
                        '<span style="background:#eee; color:#666; padding:3px 8px; border-radius:12px; font-weight:700; font-size:0.75rem;">CERRADA</span>';

                    return `<tr data-fecha="${h.fecha_apertura || ''}" data-fecha-fin="${h.fecha_cierre || ''}" data-abierto="${h.estado === 'ABIERTA' ? '1' : ''}">
                        <td><strong>#${h.id}</strong></td>
                        <td>${fApertura}</td>
                        <td>${fCierre}</td>
                        <td>${h.admin_usuario || 'Admin'}</td>
                        <td>$${parseFloat(h.ventas_efectivo_usd).toFixed(2)}${h.ventas_efectivo_bs ? `<br><small style="color:#0284c7;">Bs ${parseFloat(h.ventas_efectivo_bs).toLocaleString('es-VE', {minimumFractionDigits:2})}</small>` : ''}</td>
                        <td>-$${parseFloat(h.egresos_caja_usd).toFixed(2)}</td>
                        <td style="color:#c0392b; font-weight:600;">-$${parseFloat(h.reembolsos_efectivo_usd || 0).toFixed(2)}</td>
                        <td><strong>$${parseFloat(h.total_esperado_usd).toFixed(2)}</strong></td>
                        <td><strong>$${parseFloat(h.total_conteo_usd).toFixed(2)}</strong></td>
                        <td>${difBadge}</td>
                        <td>${estadoBadge}</td>
                    </tr>`;
                }).join('');
                // Se reaplican los filtros de texto/fecha vigentes sobre las filas nuevas.
                aplicarFiltrosTabla('cajaHistorialTableBody');
            }
        })
        .catch(err => {
            console.error("Error al cargar seccion caja:", err);
            acumulacionMetodosCache = null;
            saldoEsperadoCajaCache = 0;
            if (headerAcciones) {
                headerAcciones.innerHTML = `
                    <button type="button" onclick="abrirNuevaCaja()" class="btn-primary" style="background:#2ecc71; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:700; color:#fff;">
                        🔓 Abrir Nuevo Turno de Caja
                    </button>
                `;
            }
            if (banner) {
                banner.innerHTML = `
                    <div style="text-align:center; padding:30px 10px;">
                        <span style="font-size:3rem; display:block; margin-bottom:10px;">🔒</span>
                        <h3 style="margin:0 0 8px 0; color:#555;">La Caja se Encuentra Cerrada</h3>
                        <p style="margin:0 0 20px 0; color:#888; font-size:0.95rem;">
                            Para comenzar a registrar ventas presenciales y cobros en efectivo, abre un nuevo turno de caja.
                        </p>
                        <button type="button" onclick="abrirNuevaCaja()" class="btn-primary" style="background:#2ecc71; border:none; padding:12px 24px; border-radius:8px; font-weight:700; color:#fff; cursor:pointer;">
                            🔓 Abrir Nuevo Turno de Caja
                        </button>
                    </div>
                `;
            }
        });
}

// Abre un nuevo turno de caja de inmediato (sin formulario intermedio).
// El backend guarda la observación por defecto "Apertura de turno diario".
function abrirNuevaCaja() {
    fetch('save_caja_apertura.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({observaciones: 'Apertura de turno diario'})
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
            cargarSeccionCaja();
        } else {
            showAdminAlert(data.error || 'Error al abrir caja', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de red al abrir caja', 'error');
    });
}



function abrirModalCierreCaja() {
    if (!turnoCajaActivoCache) {
        showAdminAlert("No hay una caja abierta para cerrar.", "error");
        return;
    }
    document.getElementById('cierreTurnoId').value = turnoCajaActivoCache.id;
    const esperadoEfectivo = saldoEsperadoCajaCache || 0;
    document.getElementById('cierreTotalConteoUSD').value = esperadoEfectivo.toFixed(2);
    llenarTablaConciliacionMetodos(esperadoEfectivo);

    document.getElementById('modalCierreCaja').style.display = 'flex';
    calcularDiferenciaCierreCaja();
}

// Llena la tabla "Conciliación por Métodos de Pago" con el esperado que calcula
// el sistema por método y un campo Contado editable. El contado de efectivo se
// sincroniza con el campo grande "Conteo Físico Real de Efectivo".
function llenarTablaConciliacionMetodos(esperadoEfectivo) {
    const tbody = document.getElementById('cierreTablaMetodosBody');
    if (!tbody) return;

    const metodos = ['efectivo', 'pago_movil', 'transferencia', 'zelle', 'biopago', 'punto_venta', 'otros'];
    const detalle = acumulacionMetodosCache || {};

    tbody.innerHTML = metodos.map(clave => {
        const d = detalle[clave] || {};
        const etiqueta = d.etiqueta || clave;
        const icono = d.icono || '';
        const esperado = clave === 'efectivo'
            ? (esperadoEfectivo || 0)
            : (parseFloat(d.neto) || 0);
        const oninput = clave === 'efectivo'
            ? 'syncEfectivoContado(this); calcularDiferenciaCierreCaja();'
            : 'calcularDiferenciaCierreCaja();';
        return `
        <tr>
            <td style="padding:7px 10px; font-weight:600;">${icono} ${etiqueta}</td>
            <td style="padding:7px 10px; text-align:right;">$${esperado.toFixed(2)}</td>
            <td style="padding:7px 10px; text-align:right;">
                <input type="number" id="cierreContado_${clave}" class="cierreContadoInput"
                       step="0.01" min="0" value="${esperado.toFixed(2)}" data-esperado="${esperado.toFixed(2)}"
                       oninput="${oninput}"
                       style="width:90px; text-align:right; padding:5px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem; font-weight:600;">
            </td>
            <td id="cierreDif_${clave}" style="padding:7px 10px; text-align:right; font-weight:700;">$0.00</td>
        </tr>`;
    }).join('');
}

// El contado de efectivo de la tabla y el campo grande son la misma cantidad.
function syncEfectivoContado(input) {
    const campo = document.getElementById('cierreTotalConteoUSD');
    if (campo) {
        const v = parseFloat(input.value) || 0;
        campo.value = v.toFixed(2);
    }
}

function cerrarModalCierreCaja() {
    document.getElementById('modalCierreCaja').style.display = 'none';
}

function calcularDiferenciaCierreCaja() {
    const contado = parseFloat(document.getElementById('cierreTotalConteoUSD').value) || 0;

    // Mantener el contado de efectivo de la tabla sincronizado con el campo grande.
    const inputEfectivo = document.getElementById('cierreContado_efectivo');
    if (inputEfectivo) {
        const valTabla = parseFloat(inputEfectivo.value) || 0;
        if (Math.abs(valTabla - contado) > 0.001) {
            inputEfectivo.value = contado.toFixed(2);
        }
    }

    // Diferencia por método (Conciliación por Métodos de Pago).
    document.querySelectorAll('#cierreTablaMetodosBody .cierreContadoInput').forEach(inp => {
        const clave = inp.id.replace('cierreContado_', '');
        const esperado = parseFloat(inp.dataset.esperado) || 0;
        const contadoMet = parseFloat(inp.value) || 0;
        const dif = Math.round((contadoMet - esperado) * 100) / 100;
        const celda = document.getElementById('cierreDif_' + clave);
        if (!celda) return;
        if (Math.abs(dif) < 0.01) {
            celda.textContent = '$0.00';
            celda.style.color = '#2e7d32';
        } else if (dif < 0) {
            celda.textContent = '-$' + Math.abs(dif).toFixed(2);
            celda.style.color = '#c62828';
        } else {
            celda.textContent = '+$' + dif.toFixed(2);
            celda.style.color = '#f57f17';
        }
    });

    // Diferencia general del arqueo de efectivo.
    const dif = Math.round((contado - saldoEsperadoCajaCache) * 100) / 100;
    const box = document.getElementById('cierreDiferenciaBox');

    if (!box) return;

    if (Math.abs(dif) < 0.01) {
        box.innerText = "✓ Diferencia: $0.00 (Caja Cuadrada)";
        box.style.background = "#e8f5e9";
        box.style.color = "#2e7d32";
    } else if (dif < 0) {
        box.innerText = `⚠️ Faltante en Caja: -$${Math.abs(dif).toFixed(2)}`;
        box.style.background = "#ffebee";
        box.style.color = "#c62828";
    } else {
        box.innerText = `ℹ️ Sobrante en Caja: +$${dif.toFixed(2)}`;
        box.style.background = "#fff8e1";
        box.style.color = "#f57f17";
    }
}

// Reúne el contado físico de cada método (Conciliación) para guardarlo en el cierre.
function construirPayloadCierre(turno_id, total_conteo_usd, observaciones, forzar) {
    const payload = {turno_id, total_conteo_usd, observaciones};
    if (forzar) payload.forzar = true;

    ['efectivo', 'pago_movil', 'transferencia', 'zelle', 'biopago', 'punto_venta', 'otros'].forEach(clave => {
        const inp = document.getElementById('cierreContado_' + clave);
        payload['conteo_' + clave + '_usd'] = inp ? (parseFloat(inp.value) || 0) : 0;
    });
    return payload;
}

function guardarCierreCaja(e) {
    e.preventDefault();
    const turno_id = document.getElementById('cierreTurnoId').value;
    const total_conteo_usd = parseFloat(document.getElementById('cierreTotalConteoUSD').value) || 0;
    const observaciones = document.getElementById('cierreObservaciones').value.trim();

    fetch('save_caja_cierre.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(construirPayloadCierre(turno_id, total_conteo_usd, observaciones))
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAdminAlert(data.message, 'success');
            cerrarModalCierreCaja();
            document.getElementById('cierreTotalConteoUSD').value = '';
            document.getElementById('cierreObservaciones').value = '';
            cargarSeccionCaja();
        } else {
            if (data.requiere_confirmacion && data.pagos_pendientes && data.pagos_pendientes.cantidad > 0) {
                const mensajePlano = (data.error || '') + '\n\nPagos sin revisar: ' + data.pagos_pendientes.cantidad + ' (' + data.pagos_pendientes.monto_bs.toLocaleString('es-VE', {minimumFractionDigits:2}) + ' Bs)\n';
                if (Array.isArray(data.items)) {
                    data.items.forEach(it => {
                        const f = it.fecha_pago ? new Date(it.fecha_pago).toLocaleString('es-VE', {timeStyle:'short', dateStyle:'short'}) : '-';
                        mensajePlano += '  - Venta #' + it.venta_id + ' | ' + it.metodo_pago + ' | Bs ' + parseFloat(it.monto_bs).toLocaleString('es-VE', {minimumFractionDigits:2}) + ' | ' + f + '\n';
                    });
                }
                mensajePlano += '\n¿Cerrar caja de todos modos?';
                if (window.confirm(mensajePlano)) {
                    fetch('save_caja_cierre.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(construirPayloadCierre(turno_id, total_conteo_usd, observaciones, true))
                    })
                    .then(res2 => res2.json())
                    .then(data2 => {
                        if (data2.success) {
                            showAdminAlert(data2.message, 'success');
                            cerrarModalCierreCaja();
                            document.getElementById('cierreTotalConteoUSD').value = '';
                            document.getElementById('cierreObservaciones').value = '';
                            cargarSeccionCaja();
                        } else {
                            showAdminAlert(data2.error || 'Error al cerrar caja', 'error');
                        }
                    })
                    .catch(err2 => {
                        console.error(err2);
                        showAdminAlert('Error de red al cerrar caja', 'error');
                    });
                    return;
                }
            }
            showAdminAlert(data.error || 'Error al cerrar caja', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showAdminAlert('Error de red al cerrar caja', 'error');
    });
}

// ==================== MOTOR UNIVERSAL DE REPORTES (PDF Y EXCEL/CSV) ====================

function exportarTablaCSV(titulo, tableIdOrBodyId) {
    let target = document.getElementById(tableIdOrBodyId);
    if (!target) {
        showAdminAlert("No hay datos disponibles para exportar.", "error");
        return;
    }

    let table = target.tagName === 'TABLE' ? target : target.closest('table') || target.querySelector('table');
    if (!table) {
        showAdminAlert("No se encontró la estructura de tabla para exportar.", "error");
        return;
    }

    let csvContent = "\uFEFF"; // UTF-8 BOM para soporte de acentos en Microsoft Excel
    let rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        // No se exportan las filas que descartaron los filtros activos.
        if (row.dataset && row.dataset.matchFilter === 'false') return;

        let cols = row.querySelectorAll('th, td');
        let rowData = [];
        cols.forEach(col => {
            // Omitir columnas con botones de acciones
            if (col.innerText.includes('Acciones') || col.querySelector('button, select, input[type="file"]')) {
                // si la celda solo tiene botones, omitir
                if (col.querySelectorAll('button').length > 0) return;
            }
            let text = col.innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""').trim();
            rowData.push('"' + text + '"');
        });
        if (rowData.length > 0) {
            csvContent += rowData.join(",") + "\r\n";
        }
    });

    let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    let url = URL.createObjectURL(blob);
    let link = document.createElement("a");
    let fechaHoy = new Date().toISOString().slice(0, 10);
    link.setAttribute("href", url);
    link.setAttribute("download", `${titulo.replace(/[^a-zA-Z0-9_-]/g, '_')}_${fechaHoy}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showAdminAlert(`Reporte en Excel (${titulo}) generado exitosamente.`, 'success');
}

function imprimirReporteSeccion(tituloReporte, tableIdOrBodyId) {
    let target = document.getElementById(tableIdOrBodyId);
    if (!target) {
        showAdminAlert("No hay datos disponibles para generar el reporte PDF.", "error");
        return;
    }

    let table = target.tagName === 'TABLE' ? target : target.closest('table') || target.querySelector('table');
    if (!table) {
        showAdminAlert("No se encontró la tabla de datos.", "error");
        return;
    }

    let fechaHoy = new Date().toLocaleString('es-VE', {dateStyle:'full', timeStyle:'medium'});
    let negocioNombre = document.querySelector('.admin-brand')?.innerText.replace('| Admin', '').trim() || 'Tecnology';

    // Clonar tabla y remover columnas de acciones si las hay
    let clonedTable = table.cloneNode(true);
    // Las filas descartadas por los filtros activos no se imprimen.
    clonedTable.querySelectorAll('tr').forEach(row => {
        if (row.dataset && row.dataset.matchFilter === 'false') row.remove();
    });
    clonedTable.querySelectorAll('th, td').forEach(cell => {
        if (cell.innerText.includes('Acciones') || cell.querySelector('button, select, input[type="file"]')) {
            if (cell.querySelectorAll('button').length > 0) {
                cell.remove();
            }
        }
    });

    let printWin = window.open('', '_blank', 'width=950,height=750');
    printWin.document.write(`
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>${tituloReporte} - ${negocioNombre}</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #1e293b; margin: 24px; line-height: 1.5; font-size: 13px; }
                .report-header { border-bottom: 2px solid #0f172a; padding-bottom: 14px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; }
                .company-name { font-size: 1.8rem; font-weight: 800; color: #2563eb; letter-spacing: -0.5px; }
                .report-title { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: 4px; }
                .report-meta { font-size: 0.85rem; color: #64748b; text-align: right; }
                table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                th { background-color: #f1f5f9; color: #0f172a; font-weight: 700; padding: 10px; border: 1px solid #cbd5e1; text-align: left; font-size: 12px; text-transform: uppercase; }
                td { padding: 9px 10px; border: 1px solid #e2e8f0; word-break: break-word; vertical-align: top; }
                tr:nth-child(even) { background-color: #f8fafc; }
                .report-footer { margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 0.8rem; color: #94a3b8; display: flex; justify-content: space-between; }
                @media print {
                    body { margin: 0; padding: 15px; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="report-header">
                <div>
                    <div class="company-name">🚀 ${negocioNombre}</div>
                    <div class="report-title">📋 ${tituloReporte}</div>
                </div>
                <div class="report-meta">
                    <div><strong>Fecha de Emisión:</strong> ${fechaHoy}</div>
                    <div><strong>Sistema:</strong> ERP Control de Gestión</div>
                </div>
            </div>

            <table>
                ${clonedTable.innerHTML}
            </table>

            <div class="report-footer">
                <div>Documento Interno Confidencial - ${negocioNombre}</div>
                <div>Página 1 de 1</div>
            </div>

            <script>
                window.onload = function() {
                    window.print();
                };
            </script>
        </body>
        </html>
    `);
    printWin.document.close();
}


// ==================== CÁLCULO DE MARGEN DE GANANCIA Y RENTABILIDAD ====================

// Alícuota de IVA seleccionada en el formulario de producto (fracción, ej. 0.16).
function ivaRateForm() {
    const sel = document.getElementById('ivaProd');
    const pct = sel ? parseFloat(sel.value) : 16;
    return isNaN(pct) ? 0 : pct / 100;
}

// Pinta el select de IVA con el valor guardado; si no es uno de los estándar,
// se agrega como opción para no perderlo al editar.
function seleccionarIvaProducto(valor) {
    const sel = document.getElementById('ivaProd');
    if (!sel) return;
    const v = String(parseFloat(valor));
    if (v === 'NaN') return;
    const existe = Array.from(sel.options).find(o => o.value === v);
    if (existe) {
        sel.value = v;
    } else {
        const nueva = document.createElement('option');
        nueva.value = v;
        nueva.textContent = v + '%';
        sel.appendChild(nueva);
        sel.value = v;
    }
}

function calcularMargenGananciaForm() {
    const inputCosto = document.getElementById('precioCompraProd');
    const inputVenta = document.getElementById('precioProd');
    const inputMargen = document.getElementById('margenGananciaPct');
    const labelResult = document.getElementById('labelGananciaCalculadaUSD');

    if (!inputCosto || !inputVenta || !labelResult) return;

    const costo = parseFloat(inputCosto.value) || 0;          // costo neto (sin IVA)
    const ventaPvp = parseFloat(inputVenta.value) || 0;       // P.V.P. con IVA incluido
    const tasa = ivaRateForm();
    const ventaBase = ventaPvp / (1 + tasa);                  // venta sin IVA

    if (costo <= 0 || ventaPvp <= 0) {
        labelResult.innerText = "Ganancia: $0.00 / und (0% Margen Bruto | 0% Markup)";
        labelResult.style.color = "#888";
        return;
    }

    // El IVA cobrado no es ingreso: el margen real se mide sobre la base.
    const gananciaUSD = ventaBase - costo;
    const margenBrutoPct = (gananciaUSD / ventaBase) * 100;
    const markupPct = (gananciaUSD / costo) * 100;

    if (inputMargen && document.activeElement !== inputMargen) {
        inputMargen.value = margenBrutoPct.toFixed(2);
    }

    const color = margenBrutoPct >= 25 ? '#2ecc71' : (margenBrutoPct >= 10 ? '#f39c12' : '#e74c3c');
    labelResult.innerText = `Ganancia: $${gananciaUSD.toFixed(2)} / und (${margenBrutoPct.toFixed(1)}% Margen Bruto | ${markupPct.toFixed(1)}% Markup) · Base sin IVA: $${ventaBase.toFixed(2)}`;
    labelResult.style.color = color;
}

function calcularPrecioVentaPorMargen() {
    const inputCosto = document.getElementById('precioCompraProd');
    const inputVenta = document.getElementById('precioProd');
    const inputMargen = document.getElementById('margenGananciaPct');

    if (!inputCosto || !inputVenta || !inputMargen) return;

    const costo = parseFloat(inputCosto.value) || 0;
    const margenPct = parseFloat(inputMargen.value) || 0;

    if (costo <= 0) return;

    if (margenPct >= 100) {
        showAdminAlert("El margen bruto no puede ser mayor o igual al 100%.", "error");
        return;
    }

    // Margen Bruto sobre la base (sin IVA): Base = Costo / (1 - Margen%).
    // El P.V.P. que se muestra al cliente lleva el IVA encima.
    const tasa = ivaRateForm();
    const baseRecomendada = costo / (1 - (margenPct / 100));
    const ventaRecomendada = baseRecomendada * (1 + tasa);
    inputVenta.value = ventaRecomendada.toFixed(2);

    calcularMargenGananciaForm();
}

/* ==========================================================================
   FILTROS DE FECHA Y HORA (GENÉRICOS PARA TABLAS DEL PANEL)
   ==========================================================================
   La barra de filtros de cada tabla vive en #filtros-<tbodyId> y contiene
   inputs con data-filtro="texto | desde | hasta | hora-desde | hora-hasta"
   (ver renderBarraFiltrosFechaHora en index.php).

   Para que el filtro de fecha funcione, cada <tr> debe traer:
     data-fecha="YYYY-MM-DD HH:mm:ss"   fecha del registro
     data-fecha-fin="..."                (opcional) fin del intervalo
     data-abierto="1"                    (opcional) registro todavía abierto:
                                         el intervalo llega hasta "ahora".

   Las filas sin data-fecha no pasan el filtro cuando hay fechas activas.
   ========================================================================== */

function _valorFiltroTabla(tbodyId, tipo) {
    const barra = document.getElementById('filtros-' + tbodyId);
    if (!barra) return '';
    const campo = barra.querySelector('[data-filtro="' + tipo + '"]');
    return campo ? String(campo.value || '').trim() : '';
}

function _leerFiltrosTabla(tbodyId) {
    return {
        texto: _valorFiltroTabla(tbodyId, 'texto').toLowerCase(),
        desde: _valorFiltroTabla(tbodyId, 'desde'),
        hasta: _valorFiltroTabla(tbodyId, 'hasta'),
        horaDesde: _valorFiltroTabla(tbodyId, 'hora-desde'),
        horaHasta: _valorFiltroTabla(tbodyId, 'hora-hasta')
    };
}

function _parsearFechaTabla(valor) {
    if (!valor) return null;
    let texto = String(valor).trim();
    if (!texto) return null;
    // MySQL: "YYYY-MM-DD HH:mm:ss" se lee como hora local, no como UTC.
    if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?$/.test(texto)) {
        texto = texto.replace(' ', 'T');
    }
    const fecha = new Date(texto);
    return isNaN(fecha.getTime()) ? null : fecha;
}

/** Convierte la fecha del filtro (YYYY-MM-DD) más la hora (HH:mm) en Date local. */
function _limiteFechaTabla(fechaTexto, horaTexto, esInicio) {
    const partes = String(fechaTexto || '').split('-').map(n => parseInt(n, 10));
    if (partes.length !== 3 || partes.some(isNaN)) return null;
    let h = 0, m = 0, s = 0;
    if (horaTexto) {
        const hm = horaTexto.split(':').map(n => parseInt(n, 10));
        h = isNaN(hm[0]) ? 0 : hm[0];
        m = isNaN(hm[1]) ? 0 : hm[1];
    } else if (!esInicio) {
        h = 23; m = 59; s = 59;
    }
    return new Date(partes[0], partes[1] - 1, partes[2], h, m, s);
}

/** "HH:MM" de una fecha, para comparar solo la hora del día. */
function _horaDiaTabla(fecha) {
    return String(fecha.getHours()).padStart(2, '0') + ':' + String(fecha.getMinutes()).padStart(2, '0');
}

/**
 * Aplica a la vez el filtro de texto y el rango de fecha/hora de la barra
 * #filtros-<tbodyId> sobre las filas de la tabla, y refresca su paginación.
 * @param {string} tbodyId id del <tbody>
 */
function aplicarFiltrosTabla(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    const filtros = _leerFiltrosTabla(tbodyId);
    const hayFiltroFecha = !!(filtros.desde || filtros.hasta || filtros.horaDesde || filtros.horaHasta);
    const limiteDesde = _limiteFechaTabla(filtros.desde, filtros.horaDesde, true);
    const limiteHasta = _limiteFechaTabla(filtros.hasta, filtros.horaHasta, false);
    // Si solo se puso una hora (sin su fecha), se compara la hora del día.
    const horaSoloDesde = (!filtros.desde && filtros.horaDesde) ? filtros.horaDesde : '';
    const horaSoloHasta = (!filtros.hasta && filtros.horaHasta) ? filtros.horaHasta : '';

    tbody.querySelectorAll('tr').forEach(fila => {
        if (fila.cells.length === 1 && fila.cells[0].getAttribute('colspan')) return;

        let coincide = true;

        if (filtros.texto && !fila.innerText.toLowerCase().includes(filtros.texto)) {
            coincide = false;
        }

        if (coincide && hayFiltroFecha) {
            const inicio = _parsearFechaTabla(fila.dataset.fecha);
            if (!inicio) {
                coincide = false;
            } else {
                let fin = inicio;
                if (fila.dataset.fechaFin) {
                    fin = _parsearFechaTabla(fila.dataset.fechaFin) || inicio;
                } else if (fila.dataset.abierto === '1') {
                    fin = new Date();
                }
                // El registro debe solaparse con el rango pedido.
                if (limiteDesde && fin < limiteDesde) coincide = false;
                if (coincide && limiteHasta && inicio > limiteHasta) coincide = false;
                if (coincide && horaSoloDesde && _horaDiaTabla(inicio) < horaSoloDesde) coincide = false;
                if (coincide && horaSoloHasta && _horaDiaTabla(inicio) > horaSoloHasta) coincide = false;
            }
        }

        fila.dataset.matchFilter = coincide ? 'true' : 'false';
    });

    if (estadoPaginacion[tbodyId]) {
        estadoPaginacion[tbodyId].paginaActual = 1;
    }
    actualizarPaginacionTabla(tbodyId);
}

/** Vacia la barra de filtros de una tabla y vuelve a mostrar todo. */
function limpiarFiltrosTabla(tbodyId) {
    const barra = document.getElementById('filtros-' + tbodyId);
    if (barra) {
        barra.querySelectorAll('[data-filtro]').forEach(campo => { campo.value = ''; });
    }
    aplicarFiltrosTabla(tbodyId);
}

/**
 * Filtra dinámicamente en tiempo real cualquier tabla HTML del panel de administración.
 * @param {HTMLInputElement} inputEl 
 * @param {string} tableBodyId 
 * @param {string|null} selectFilterId 
 */
/**
 * Filtra dinámicamente en tiempo real cualquier tabla HTML del panel de administración.
 * @param {HTMLInputElement} inputEl 
 * @param {string} tableBodyId 
 * @param {string|null} selectFilterId 
 */
function filtrarTablaEnVivo(inputEl, tableBodyId, selectFilterId = null) {
    const filterText = (inputEl ? inputEl.value : '').toLowerCase().trim();
    const selectEl = selectFilterId ? document.getElementById(selectFilterId) : null;
    const filterSelect = selectEl ? selectEl.value.toLowerCase().trim() : '';

    const tbody = document.getElementById(tableBodyId);
    if (!tbody) return;

    const rows = tbody.querySelectorAll('tr');
    rows.forEach(row => {
        if (row.cells.length === 1 && row.cells[0].getAttribute('colspan')) {
            return;
        }

        const text = row.innerText.toLowerCase();
        const matchesText = !filterText || text.includes(filterText);
        const matchesSelect = !filterSelect || text.includes(filterSelect);

        if (matchesText && matchesSelect) {
            row.dataset.matchFilter = 'true';
        } else {
            row.dataset.matchFilter = 'false';
        }
    });

    if (estadoPaginacion[tableBodyId]) {
        estadoPaginacion[tableBodyId].paginaActual = 1;
    }
    actualizarPaginacionTabla(tableBodyId);
}

/**
 * Llena el selector de categorías de la matriz de productos dinámicamente.
 */
function poblacionCategoriasFiltroMatriz(data) {
    const selectCat = document.getElementById('filtroCategoriaMatriz');
    if (!selectCat) return;
    const currentVal = selectCat.value;
    const categorias = [...new Set(data.map(p => p.categoria).filter(Boolean))].sort();
    selectCat.innerHTML = '<option value="">Todas las Categorías</option>' + 
        categorias.map(cat => `<option value="${cat}">${cat}</option>`).join('');
    if (currentVal && categorias.includes(currentVal)) {
        selectCat.value = currentVal;
    }
}

function poblacionProveedoresFiltroMatriz(data) {
    const selectProv = document.getElementById('filtroProveedorMatriz');
    if (!selectProv) return;
    const currentVal = selectProv.value;
    const proveedores = [...new Set(
        data.map(p => p.proveedor_nombre).filter(Boolean)
    )].sort();
    selectProv.innerHTML = '<option value="">Todos los Proveedores</option>' +
        proveedores.map(prov => `<option value="${prov.toLowerCase()}">${prov}</option>`).join('');
    if (currentVal && proveedores.map(p => p.toLowerCase()).includes(currentVal)) {
        selectProv.value = currentVal;
    }
}

function filtrarMatrizInventario() {
    const texto = (document.getElementById('filtroTextoMatriz')?.value || '').toLowerCase().trim();
    const categoria = (document.getElementById('filtroCategoriaMatriz')?.value || '').toLowerCase().trim();
    const stockEstado = (document.getElementById('filtroStockMatriz')?.value || '').toLowerCase().trim();
    const margenEstado = (document.getElementById('filtroMargenMatriz')?.value || '').toLowerCase().trim();
    const precioMin = parseFloat(document.getElementById('filtroPrecioMinMatriz')?.value);
    const precioMax = parseFloat(document.getElementById('filtroPrecioMaxMatriz')?.value);
    const proveedor = (document.getElementById('filtroProveedorMatriz')?.value || '').toLowerCase().trim();

    const tbody = document.getElementById('inventoryTableBody');
    if (!tbody) return;

    const rows = tbody.querySelectorAll('tr');
    rows.forEach(row => {
        if (row.cells.length <= 1 && row.cells[0]?.getAttribute('colspan')) return;

        const pNombre = row.getAttribute('data-nombre') || '';
        const pCodigo = row.getAttribute('data-codigo') || '';
        const pId = row.getAttribute('data-id') || '';
        const pCategoria = row.getAttribute('data-categoria') || '';
        const pStock = parseInt(row.getAttribute('data-stock'), 10) || 0;
        const pPrecio = parseFloat(row.getAttribute('data-precio')) || 0;
        const pMargen = parseFloat(row.getAttribute('data-margen')) || 0;
        const pProveedor = row.getAttribute('data-proveedor') || '';

        // 1. Filtro Texto (Nombre, Código, ID, Categoría, Proveedor)
        const matchTextoFinal = !texto || pNombre.includes(texto) || pCodigo.includes(texto) || pId.includes(texto) || pCategoria.includes(texto) || pProveedor.includes(texto);

        // 2. Filtro Categoría
        const matchCat = !categoria || pCategoria === categoria;

        // 3. Filtro Estado de Stock
        let matchStock = true;
        if (stockEstado === 'agotado') matchStock = pStock <= 0;
        else if (stockEstado === 'bajo') matchStock = pStock > 0 && pStock <= 5;
        else if (stockEstado === 'disponible') matchStock = pStock > 5;

        // 4. Filtro Margen de Ganancia
        let matchMargen = true;
        if (margenEstado === 'alto') matchMargen = pMargen >= 30;
        else if (margenEstado === 'medio') matchMargen = pMargen >= 15 && pMargen < 30;
        else if (margenEstado === 'bajo') matchMargen = pMargen < 15;

        // 5. Filtro Rango de Precio
        let matchPrecio = true;
        if (!isNaN(precioMin)) matchPrecio = matchPrecio && (pPrecio >= precioMin);
        if (!isNaN(precioMax)) matchPrecio = matchPrecio && (pPrecio <= precioMax);

        // 6. Filtro Proveedor
        const matchProveedor = !proveedor || pProveedor === proveedor;

        if (matchTextoFinal && matchCat && matchStock && matchMargen && matchPrecio && matchProveedor) {
            row.dataset.matchFilter = 'true';
        } else {
            row.dataset.matchFilter = 'false';
        }
    });

    if (estadoPaginacion['inventoryTableBody']) {
        estadoPaginacion['inventoryTableBody'].paginaActual = 1;
    }
    actualizarPaginacionTabla('inventoryTableBody');
}

// ==================== SISTEMA GLOBAL DE PAGINACIÓN DE TABLAS ====================
const estadoPaginacion = {};

function cambiarElementosPorPagina(tbodyId, selectEl) {
    const val = selectEl.value;
    if (!estadoPaginacion[tbodyId]) {
        estadoPaginacion[tbodyId] = { paginaActual: 1, elementosPorPagina: 10 };
    }
    estadoPaginacion[tbodyId].elementosPorPagina = val === 'todos' ? 999999 : parseInt(val, 10);
    estadoPaginacion[tbodyId].paginaActual = 1;
    actualizarPaginacionTabla(tbodyId);
}

function cambiarPaginaTabla(tbodyId, delta) {
    if (!estadoPaginacion[tbodyId]) return;
    estadoPaginacion[tbodyId].paginaActual += delta;
    actualizarPaginacionTabla(tbodyId);
}

function actualizarPaginacionTabla(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!estadoPaginacion[containerId]) {
        estadoPaginacion[containerId] = { paginaActual: 1, elementosPorPagina: 10 };
    }

    const state = estadoPaginacion[containerId];
    
    // Soporta <tbody> (se pagan las <tr>) y contenedores de tarjetas como los
    // apartados del historial (div, se pagan los hijos directos <div>).
    const esTabla = container.tagName === 'TBODY';
    const allRows = esTabla
        ? Array.from(container.querySelectorAll('tr')).filter(r => {
            return !(r.cells.length === 1 && r.cells[0].getAttribute('colspan'));
        })
        : Array.from(container.children).filter(el => el.tagName === 'DIV');

    // En tablas, las filas que no pasan los filtros en vivo se ocultan siempre.
    // En contenedores de tarjetas no hay filas "no coincidentes": entran todas.
    const matchingRows = esTabla
        ? allRows.filter(r => r.dataset.matchFilter !== 'false')
        : allRows;

    if (esTabla) {
        allRows.forEach(r => {
            if (r.dataset.matchFilter === 'false') {
                r.style.display = 'none';
            }
        });
    }

    const totalRegistros = matchingRows.length;
    const limit = state.elementosPorPagina;
    const totalPaginas = Math.ceil(totalRegistros / limit) || 1;

    if (state.paginaActual < 1) state.paginaActual = 1;
    if (state.paginaActual > totalPaginas) state.paginaActual = totalPaginas;

    const inicio = (state.paginaActual - 1) * limit;
    const fin = inicio + limit;

    matchingRows.forEach((row, idx) => {
        if (idx >= inicio && idx < fin) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    // Actualizar elementos de la interfaz de usuario
    const infoEl = document.getElementById(`pag-info-${containerId}`);
    const btnPrev = document.getElementById(`pag-prev-${containerId}`);
    const btnNext = document.getElementById(`pag-next-${containerId}`);

    if (infoEl) {
        if (totalRegistros === 0) {
            infoEl.innerText = 'Mostrando 0 de 0 registros';
        } else {
            const numInicio = inicio + 1;
            const numFin = Math.min(fin, totalRegistros);
            infoEl.innerText = `Página ${state.paginaActual} de ${totalPaginas} (${numInicio}-${numFin} de ${totalRegistros})`;
        }
    }
    if (btnPrev) btnPrev.disabled = (state.paginaActual <= 1);
    if (btnNext) btnNext.disabled = (state.paginaActual >= totalPaginas);
}

/* ==========================================================================
   MÓDULO DE PÉRDIDAS Y MERMAS (SALIDAS NO OPERATIVAS)
   ========================================================================== */

window.mermasDataCache = [];
window.productosMermasCache = [];

/** Escapa texto antes de inyectarlo con innerHTML (producto_nombre, motivo, usuario...) */
function escaparHTML(valor) {
    if (valor === null || valor === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(valor);
    return div.innerHTML;
}

// OJO: escaparHTML() NO sirve para valores dentro de atributos.
// El truco del nodo de texto no escapa comillas, porque un nodo de texto no las
// necesita. Dentro de un href="..." o un value="...", en cambio, una comilla
// cierra el atributo y permite colar otro atributo (por ejemplo onmouseover=).
// Para atributos usar siempre esta.
function escaparAtributo(valor) {
    return escaparHTML(valor)
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================================================
// KARDEX DE INVENTARIO
// ============================================================================

const KARDEX_CONCEPTOS_BADGE = {
    'COMPRA':                         { texto: '🧾 Compra',                      color: '#166534', fondo: '#dcfce7' },
    'VENTA':                          { texto: '💰 Venta',                      color: '#1d4ed8', fondo: '#dbeafe' },
    'ANULACION_VENTA':                { texto: '↩️ Anul. venta',               color: '#075985', fondo: '#e0f2fe' },
    'DEVOLUCION_VENTA':               { texto: '↩️ Devol. venta',              color: '#6d28d9', fondo: '#ede9fe' },
    'DEVOLUCION_PROVEEDOR':           { texto: '📤 Devol. proveedor',         color: '#b45309', fondo: '#fef3c7' },
    'ANULACION_DEVOLUCION_PROVEEDOR': { texto: '↩️ Anul. devol. proveedor',    color: '#92400e', fondo: '#ffedd5' },
    'MERMA':                          { texto: '⚠️ Pérdida',                       color: '#b91c1c', fondo: '#fee2e2' },
    'ANULACION_MERMA':                { texto: '↩️ Anul. pérdida',               color: '#c2410c', fondo: '#ffedd5' },
    'AJUSTE_INVENTARIO':              { texto: '⚖️ Ajuste inventario',        color: '#4338ca', fondo: '#e0e7ff' },
    'ABASTECIMIENTO':                 { texto: '📥 Abastecimiento',            color: '#047857', fondo: '#d1fae5' }
};

function kardexBadgeConcepto(concepto) {
    const b = KARDEX_CONCEPTOS_BADGE[concepto];
    if (!b) {
        return `<span style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:3px 9px; border-radius:10px; font-size:0.76rem; font-weight:700;">${escaparHTML(concepto)}</span>`;
    }
    return `<span style="background:${b.fondo}; color:${b.color}; border:1px solid ${b.color}33; padding:3px 9px; border-radius:10px; font-size:0.76rem; font-weight:700; white-space:nowrap;">${b.texto}</span>`;
}

function formatearFechaKardex(fecha) {
    if (!fecha) return '-';
    const d = new Date(String(fecha).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return escaparHTML(fecha);
    return d.toLocaleString('es-VE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: true
    });
}

// La búsqueda se dispara en cada tecla: se retrasa para no pedir el Kardex
// completo por cada caracter.
let kardexTimerBusqueda = null;

// Desde el botón de acción del módulo de Inventario se muestra la sub-vista del
// Kardex dentro de la misma sección.
function abrirKardexDesdeInventario() {
    const lista = document.getElementById('inventarioVistaLista');
    const kardex = document.getElementById('inventarioVistaKardex');
    const perdidas = document.getElementById('inventarioVistaPerdidas');
    const tasa = document.getElementById('inventarioVistaTasa');
    if (perdidas) perdidas.classList.add('hidden');
    if (tasa) tasa.classList.add('hidden');
    if (lista) lista.classList.add('hidden');
    if (kardex) kardex.classList.remove('hidden');

    document.querySelectorAll('.admin-nav-btn').forEach(b => b.classList.remove('active'));
    const btnInventario = document.querySelector('.admin-nav-btn[data-section="inventario"]');
    if (btnInventario) btnInventario.classList.add('active');

    cargarKardex();
}

function abrirPerdidasDesdeInventario() {
    const lista = document.getElementById('inventarioVistaLista');
    const kardex = document.getElementById('inventarioVistaKardex');
    const perdidas = document.getElementById('inventarioVistaPerdidas');
    const tasa = document.getElementById('inventarioVistaTasa');
    if (kardex) kardex.classList.add('hidden');
    if (tasa) tasa.classList.add('hidden');
    if (lista) lista.classList.add('hidden');
    if (perdidas) perdidas.classList.remove('hidden');

    document.querySelectorAll('.admin-nav-btn').forEach(b => b.classList.remove('active'));
    const btnInventario = document.querySelector('.admin-nav-btn[data-section="inventario"]');
    if (btnInventario) btnInventario.classList.add('active');

    cargarMermas();
}

function abrirTasaDesdeInventario() {
    const lista = document.getElementById('inventarioVistaLista');
    const kardex = document.getElementById('inventarioVistaKardex');
    const perdidas = document.getElementById('inventarioVistaPerdidas');
    const tasa = document.getElementById('inventarioVistaTasa');
    if (kardex) kardex.classList.add('hidden');
    if (perdidas) perdidas.classList.add('hidden');
    if (lista) lista.classList.add('hidden');
    if (tasa) tasa.classList.remove('hidden');

    document.querySelectorAll('.admin-nav-btn').forEach(b => b.classList.remove('active'));
    const btnInventario = document.querySelector('.admin-nav-btn[data-section="inventario"]');
    if (btnInventario) btnInventario.classList.add('active');

    actualizarVistasTasa();
}

function volverAInventarioLista() {
    const lista = document.getElementById('inventarioVistaLista');
    const kardex = document.getElementById('inventarioVistaKardex');
    const perdidas = document.getElementById('inventarioVistaPerdidas');
    const tasa = document.getElementById('inventarioVistaTasa');
    if (kardex) kardex.classList.add('hidden');
    if (perdidas) perdidas.classList.add('hidden');
    if (tasa) tasa.classList.add('hidden');
    if (lista) lista.classList.remove('hidden');
    cargarInventario();
}
function cargarKardexDebounced() {
    clearTimeout(kardexTimerBusqueda);
    kardexTimerBusqueda = setTimeout(cargarKardex, 350);
}

async function cargarKardex() {
    const tbody = document.getElementById('kardexTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:25px; color:#666;">⌛ Cargando el Kardex...</td></tr>';

    // El <select> de productos se llena una vez por visita a la seccion.
    await cargarProductosFiltroKardex();

    const params = new URLSearchParams();
    const producto = document.getElementById('kardexProducto')?.value || '';
    const tipo = document.getElementById('kardexTipo')?.value || '';
    const concepto = document.getElementById('kardexConcepto')?.value || '';
    const desde = document.getElementById('kardexDesde')?.value || '';
    const hasta = document.getElementById('kardexHasta')?.value || '';
    const busqueda = (document.getElementById('kardexBusqueda')?.value || '').trim();

    if (producto) params.set('producto_id', producto);
    if (tipo) params.set('tipo', tipo);
    if (concepto) params.set('concepto', concepto);
    if (desde) params.set('desde', desde);
    if (hasta) params.set('hasta', hasta);
    if (busqueda) params.set('busqueda', busqueda);

    try {
        const res = await fetch('get_kardex.php?' + params.toString());
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; color:#c53030; padding:20px;">Error: ${escaparHTML(data.error || 'No se pudo cargar el Kardex.')}</td></tr>`;
            return;
        }

        pintarKPIsKardex(data.resumen || {});

        const filas = data.data || [];
        const aviso = document.getElementById('kardexAvisoLimite');
        if (aviso) {
            if (filas.length >= (data.limite || 500)) {
                aviso.style.display = 'block';
                aviso.textContent = `Se muestran los ${filas.length} movimientos más recientes. Acota el rango de fechas o el producto para ver los anteriores.`;
            } else {
                aviso.style.display = 'none';
                aviso.textContent = '';
            }
        }

        renderTablaKardex(filas);
    } catch (err) {
        console.error('Error al cargar el Kardex:', err);
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; color:#c53030; padding:20px;">Ocurrió un error al comunicarse con el servidor.</td></tr>';
    }
}

function pintarKPIsKardex(resumen) {
    const fmt = n => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const elEntradas = document.getElementById('kardexStatEntradas');
    const elSalidas = document.getElementById('kardexStatSalidas');
    const elMovimientos = document.getElementById('kardexStatMovimientos');
    const elValor = document.getElementById('kardexStatValorInventario');

    if (elEntradas) elEntradas.innerText = resumen.entradas || 0;
    if (elSalidas) elSalidas.innerText = resumen.salidas || 0;
    if (elMovimientos) elMovimientos.innerText = resumen.movimientos || 0;
    if (elValor) elValor.innerText = fmt(resumen.valor_inventario);
}

function renderTablaKardex(lista) {
    const tbody = document.getElementById('kardexTableBody');
    if (!tbody) return;

    if (!lista || lista.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:30px; color:#888;">No hay movimientos que coincidan con los filtros seleccionados.</td></tr>';
        return;
    }

    let html = '';
    for (const m of lista) {
        const esEntrada = m.tipo_movimiento === 'ENTRADA';

        const documento = m.numero_referencia
            ? escaparHTML(m.numero_referencia)
            : (m.documento_tipo ? escaparHTML(m.documento_tipo) : '-');

        const obs = m.observaciones ? escaparHTML(m.observaciones) : '-';

        html += `
            <tr style="${esEntrada ? 'background:#f7fdf9;' : ''}">
                <td style="font-size:0.82rem; color:#555; white-space:nowrap;">${formatearFechaKardex(m.fecha_movimiento)}</td>
                <td>
                    <strong style="color:var(--text, #1e293b);">${escaparHTML(m.nombre_producto)}</strong>
                    ${m.producto_id ? `<div style="font-size:0.72rem; color:#94a3b8;">ID #${m.producto_id}</div>` : '<div style="font-size:0.72rem; color:#c2410c;">Producto eliminado</div>'}
                </td>
                <td>${kardexBadgeConcepto(m.concepto)}</td>
                <td style="font-size:0.83rem; color:#475569;">${documento}</td>
                <td style="text-align:center; font-weight:800; color:${m.cantidad_entrada ? '#16a34a' : '#cbd5e1'};">${m.cantidad_entrada ? '+' + m.cantidad_entrada : '—'}</td>
                <td style="text-align:center; font-weight:800; color:${m.cantidad_salida ? '#dc2626' : '#cbd5e1'};">${m.cantidad_salida ? '−' + m.cantidad_salida : '—'}</td>
                <td style="text-align:center; color:#64748b;">${m.stock_anterior}</td>
                <td style="text-align:center; font-weight:800; color:var(--text, #1e293b); background:#f1f5f9; border-radius:6px;">${m.stock_nuevo}</td>
                <td style="text-align:right; color:#475569;">$${(m.costo_unitario_usd || 0).toFixed(2)}</td>
                <td style="text-align:right; font-weight:700; color:#1f2937;">$${(m.costo_total_usd || 0).toFixed(2)}</td>
                <td style="font-size:0.8rem; color:#64748b; max-width:230px;">${obs}</td>
            </tr>
        `;
    }

    tbody.innerHTML = html;
}

function limpiarFiltrosKardex() {
    ['kardexBusqueda', 'kardexProducto', 'kardexTipo', 'kardexConcepto', 'kardexDesde', 'kardexHasta']
        .forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    cargarKardex();
}

// El <select> de productos se llena una vez por visita a la seccion.
let productosKardexCargados = false;
async function cargarProductosFiltroKardex() {
    if (productosKardexCargados) return;

    const select = document.getElementById('kardexProducto');
    if (!select) return;

    const valorActual = select.value;

    try {
        const res = await fetch('get_productos_admin.php');
        const productos = await res.json();

        if (!Array.isArray(productos) || productos.length === 0) {
            productosKardexCargados = true;
            return;
        }

        let options = '<option value="">Todos los productos</option>';
        for (const p of productos) {
            const stock = parseInt(p.stock) || 0;
            options += `<option value="${escaparAtributo(p.id)}">${escaparHTML(p.nombre)} (stock: ${stock})</option>`;
        }

        select.innerHTML = options;
        if (valorActual) select.value = valorActual;
        productosKardexCargados = true;
    } catch (err) {
        console.error('Error al cargar los productos del filtro de Kardex:', err);
    }
}

function cerrarModalAjusteKardex() {
    const modal = document.getElementById('modalAjusteKardex');
    if (modal) modal.style.display = 'none';
}

async function abrirModalAjusteKardex() {
    const modal = document.getElementById('modalAjusteKardex');
    const select = document.getElementById('kardexAjusteProducto');
    const form = document.getElementById('formAjusteKardex');
    const info = document.getElementById('kardexAjusteInfo');

    if (form) form.reset();
    if (info) info.style.display = 'none';
    actualizarPreviewAjusteKardex();

    if (modal) modal.style.display = 'flex';

    if (select) select.innerHTML = '<option value="">⌛ Cargando catálogo...</option>';

    try {
        const res = await fetch('get_productos_admin.php');
        const productos = await res.json();

        if (!select) return;

        if (!Array.isArray(productos) || productos.length === 0) {
            select.innerHTML = '<option value="">No hay productos disponibles</option>';
            return;
        }

        let options = '<option value="">-- Seleccionar producto --</option>';
        for (const p of productos) {
            const stock = parseInt(p.stock) || 0;
            options += `<option value="${escaparAtributo(p.id)}" data-stock="${stock}">${escaparHTML(p.nombre)} (stock: ${stock})</option>`;
        }
        select.innerHTML = options;
    } catch (err) {
        console.error('Error al cargar productos para el ajuste de Kardex:', err);
        if (select) select.innerHTML = '<option value="">Error al cargar productos</option>';
    }
}

function actualizarInfoAjusteKardex() {
    const select = document.getElementById('kardexAjusteProducto');
    const info = document.getElementById('kardexAjusteInfo');
    const elStock = document.getElementById('kardexAjusteStockActual');

    if (!select || !select.value || !select.options[select.selectedIndex]) {
        if (info) info.style.display = 'none';
        actualizarPreviewAjusteKardex();
        return;
    }

    const stock = parseInt(select.options[select.selectedIndex].getAttribute('data-stock')) || 0;
    if (elStock) elStock.innerText = stock;
    if (info) info.style.display = 'block';

    actualizarPreviewAjusteKardex();
}

function actualizarPreviewAjusteKardex() {
    const select = document.getElementById('kardexAjusteProducto');
    const tipo = document.getElementById('kardexAjusteTipo');
    const cantidad = parseInt(document.getElementById('kardexAjusteCantidad')?.value || '0') || 0;
    const elPreview = document.getElementById('kardexAjustePreviewValor');

    if (!elPreview) return;

    if (!select || !select.value || cantidad <= 0) {
        elPreview.innerText = '-';
        return;
    }

    const stock = parseInt(select.options[select.selectedIndex].getAttribute('data-stock')) || 0;
    const signo = (tipo?.value === '-1') ? -1 : 1;
    const nuevo = stock + (signo * cantidad);

    elPreview.innerText = `${stock} → ${nuevo}`;
    elPreview.style.color = nuevo < 0 ? '#dc2626' : '#4338ca';
}

async function guardarAjusteKardex(e) {
    e.preventDefault();

    const productoId = document.getElementById('kardexAjusteProducto')?.value;
    const tipo = document.getElementById('kardexAjusteTipo')?.value;
    const cantidad = parseInt(document.getElementById('kardexAjusteCantidad')?.value || '0');
    const motivo = (document.getElementById('kardexAjusteMotivo')?.value || '').trim();

    if (!productoId) {
        alert('Selecciona el producto a ajustar.');
        return;
    }
    if (!Number.isInteger(cantidad) || cantidad <= 0) {
        alert('La cantidad debe ser un número entero mayor a cero.');
        return;
    }
    if (motivo.length < 5) {
        alert('Indica el motivo del ajuste (mínimo 5 caracteres).');
        return;
    }

    try {
        const res = await fetch('save_ajuste_kardex.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                producto_id: productoId,
                cantidad: (tipo === '-1' ? -1 : 1) * cantidad,
                motivo: motivo
            })
        });

        const data = await res.json();

        if (!data.success) {
            alert('No se pudo registrar el ajuste: ' + (data.error || 'error desconocido'));
            return;
        }

        cerrarModalAjusteKardex();
        alert(data.mensaje || 'Ajuste registrado correctamente.');

        productosKardexCargados = false;
        await cargarKardex();
        if (typeof cargarInventario === 'function') cargarInventario();
    } catch (err) {
        console.error('Error al guardar el ajuste de Kardex:', err);
        alert('Ocurrió un error al comunicarse con el servidor.');
    }
}

async function cargarMermas() {
    const tbody = document.getElementById('tablaMermasBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:25px; color:#666;">⌛ Cargando pérdidas...</td></tr>';

    try {
        const response = await fetch('get_mermas.php');
        const data = await response.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:#c53030; padding:20px;">Error: ${data.error || 'No se pudieron cargar los registros.'}</td></tr>`;
            return;
        }

        window.mermasDataCache = data.mermas || [];
        const stats = data.stats || {};

        // Actualizar KPIs (solo mermas activas; las anuladas van en su propio resumen)
        const elTotalUSD = document.getElementById('mermaStatTotalUSD');
        const elTotalUnidades = document.getElementById('mermaStatTotalUnidades');
        const elTotalRegistros = document.getElementById('mermaStatTotalRegistros');
        const elPrincipalMotivo = document.getElementById('mermaStatPrincipalMotivo');

        if (elTotalUSD) elTotalUSD.innerText = `$${(stats.total_perdidas_usd || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        if (elTotalUnidades) elTotalUnidades.innerText = `${stats.total_unidades || 0} unids`;
        if (elTotalRegistros) elTotalRegistros.innerText = stats.total_registros || 0;

        // Calcular principal tipo de salida por cantidad
        if (elPrincipalMotivo && stats.desglose) {
            let maxTipo = '-';
            let maxCant = 0;
            for (const [tipo, val] of Object.entries(stats.desglose)) {
                if (val.cantidad > maxCant) {
                    maxCant = val.cantidad;
                    maxTipo = tipo;
                }
            }
            const badgesText = {
                'DAÑADO': '📦 Producto Dañado',
                'ROBADO': '🥷 Robado / Sustraído',
                'EXTRAVIADO': '❓ Extraviado',
                'VENCIDO': '⏳ Vencido',
                'USO_INTERNO': '🏢 Uso Interno'
            };
            elPrincipalMotivo.innerText = maxCant > 0 ? `${badgesText[maxTipo] || maxTipo} (${maxCant})` : 'Sin pérdidas';
        }

        // Resumen de mermas anuladas (excluidas de los KPI de arriba)
        const resumen = document.getElementById('mermaAnuladasResumen');
        if (resumen) {
            const nAnuladas = stats.total_registros_anulados || 0;
            if (nAnuladas > 0) {
                resumen.style.display = 'block';
                resumen.innerHTML = `ℹ️ Hay <strong>${nAnuladas}</strong> pérdida(s) anulada(s) por
                    <strong>${stats.total_anuladas_unidades || 0}</strong> unidades y
                    <strong>$${(stats.total_anuladas_usd || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</strong>.
                    No se incluyen en las pérdidas totales porque su stock fue devuelto. Activa "Mostrar anuladas" para verlas.`;
            } else {
                resumen.style.display = 'none';
                resumen.innerHTML = '';
            }
        }

        filtrarTablaMermas();

    } catch (err) {
        console.error("Error al cargar mermas:", err);
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; color:#c53030; padding:20px;">Ocurrió un error al comunicarse con el servidor.</td></tr>';
    }
}

function renderTablaMermas(lista) {
    const tbody = document.getElementById('tablaMermasBody');
    if (!tbody) return;

    if (!lista || lista.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#888;">No se han registrado salidas no operativas (pérdidas) en el sistema.</td></tr>';
        return;
    }

    const badgesHtml = {
        'DAÑADO': '<span style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:4px 10px; border-radius:12px; font-size:0.78rem; font-weight:700;">📦 Dañado</span>',
        'ROBADO': '<span style="background:#fff7ed; color:#c2410c; border:1px solid #ffedd5; padding:4px 10px; border-radius:12px; font-size:0.78rem; font-weight:700;">🥷 Robado</span>',
        'EXTRAVIADO': '<span style="background:#fefce8; color:#a16207; border:1px solid #fef08a; padding:4px 10px; border-radius:12px; font-size:0.78rem; font-weight:700;">❓ Extraviado</span>',
        'VENCIDO': '<span style="background:#faf5ff; color:#7e22ce; border:1px solid #f3e8ff; padding:4px 10px; border-radius:12px; font-size:0.78rem; font-weight:700;">⏳ Vencido</span>',
        'USO_INTERNO': '<span style="background:#eff6ff; color:#1d4ed8; border:1px solid #dbeafe; padding:4px 10px; border-radius:12px; font-size:0.78rem; font-weight:700;">🏢 Uso Interno</span>'
    };

    let html = '';
    lista.forEach(item => {
        const anulada = item.estado === 'ANULADA';
        const tipoBadge = badgesHtml[item.tipo_salida] || `<span style="background:#eee; padding:3px 8px; border-radius:10px;">${escaparHTML(item.tipo_salida)}</span>`;
        const skuStr = item.producto_sku ? `<span style="font-size:0.75rem; color:#888; display:block;">SKU: ${escaparHTML(item.producto_sku)}</span>` : '';
        // obtenerRutaPreview() handles absolute URLs and paths already prefixed with uploads/
        const rutaImg = item.producto_imagen ? obtenerRutaPreview(item.producto_imagen) : '';
        const imgHtml = rutaImg ? `<img src="${escaparHTML(rutaImg)}" style="width:32px; height:32px; border-radius:6px; object-fit:cover; margin-right:8px; ${anulada ? 'filter:grayscale(1); opacity:0.5;' : ''}" onerror="this.src='../assets/img/no-image.png'">` : '';

        const anuladaBadge = anulada
            ? `<span style="display:inline-block; margin-top:3px; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; padding:2px 8px; border-radius:10px; font-size:0.7rem; font-weight:700;">ANULADA</span>`
            : '';

        const motivoHtml = anulada
            ? `<span style="color:#94a3b8; text-decoration:line-through;">${escaparHTML(item.motivo) || '-'}</span>
               <div style="font-size:0.75rem; color:#b45309; margin-top:3px;">↩️ Anulada ${escaparHTML(item.fecha_anulacion_formateada || '')} por ${escaparHTML(item.anulada_por_nombre || 'Administrador')}${item.motivo_anulacion ? ': ' + escaparHTML(item.motivo_anulacion) : ''}</div>`
            : `<span title="${escaparHTML(item.motivo || '')}">${escaparHTML(item.motivo) || '-'}</span>`;

        const acciones = anulada
            ? `<span style="font-size:0.75rem; color:#94a3b8;">Sin acciones</span>`
            : `<button type="button" title="Anular esta pérdida y devolver las unidades al inventario"
                        onclick="abrirModalAnularMerma(${item.id})"
                        style="background:#fffbeb; color:#b45309; border:1px solid #fcd34d; padding:5px 10px; border-radius:6px; font-size:0.78rem; font-weight:700; cursor:pointer;">↩️ Anular</button>`;

        html += `
            <tr style="${anulada ? 'opacity:0.6; background:#f8fafc;' : ''}">
                <td style="font-size:0.85rem; color:#555; white-space:nowrap;">${escaparHTML(item.fecha_formateada)}</td>
                <td>
                    <div style="display:flex; align-items:center;">
                        ${imgHtml}
                        <div>
                            <strong style="color:var(--text, #1e293b);">${escaparHTML(item.producto_nombre)}</strong>
                            ${skuStr}
                            ${anuladaBadge}
                        </div>
                    </div>
                </td>
                <td>${tipoBadge}</td>
                <td style="font-weight:700; text-align:center; color:#dc2626;">${anulada ? '+' : '-'}${item.cantidad}</td>
                <td style="font-size:0.88rem;">$${item.costo_unitario_usd.toFixed(2)}</td>
                <td style="font-weight:700; color:#b91c1c;">$${item.costo_total_usd.toFixed(2)}</td>
                <td style="font-size:0.85rem; color:#475569; max-width:250px; overflow:hidden;">${motivoHtml}</td>
                <td style="font-size:0.82rem; color:#64748b;">👤 ${escaparHTML(item.registrado_por)}</td>
                <td style="text-align:center;">${acciones}</td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function filtrarTablaMermas() {
    const texto = (document.getElementById('busquedaMerma')?.value || '').toLowerCase().trim();
    const tipo = (document.getElementById('filtroTipoMerma')?.value || '').trim();
    const mostrarAnuladas = document.getElementById('filtroMostrarAnuladas')?.checked || false;

    const filtrados = window.mermasDataCache.filter(item => {
        const matchEstado = mostrarAnuladas || item.estado !== 'ANULADA';
        const matchTipo = !tipo || item.tipo_salida === tipo;
        const matchTexto = !texto ||
            (item.producto_nombre || '').toLowerCase().includes(texto) ||
            (item.producto_sku || '').toLowerCase().includes(texto) ||
            (item.motivo || '').toLowerCase().includes(texto) ||
            (item.motivo_anulacion || '').toLowerCase().includes(texto) ||
            (item.registrado_por || '').toLowerCase().includes(texto);

        return matchEstado && matchTipo && matchTexto;
    });

    renderTablaMermas(filtrados);
}

/* ---------- Anulación de mermas ---------- */

function abrirModalAnularMerma(mermaId) {
    const merma = window.mermasDataCache.find(m => m.id === parseInt(mermaId));
    if (!merma) {
        alert('No se encontró el registro de la pérdida seleccionada.');
        return;
    }
    if (merma.estado === 'ANULADA') {
        alert('Esta pérdida ya fue anulada anteriormente.');
        return;
    }

    const elId = document.getElementById('anularMermaId');
    if (elId) elId.textContent = merma.id;

    const resumen = document.getElementById('anularMermaResumen');
    if (resumen) {
        resumen.innerHTML = `
            <div><strong>Producto:</strong> ${escaparHTML(merma.producto_nombre)}</div>
            <div><strong>Tipo de salida:</strong> ${escaparHTML(merma.tipo_salida)}</div>
            <div><strong>Fecha de registro:</strong> ${escaparHTML(merma.fecha_formateada)} por ${escaparHTML(merma.registrado_por)}</div>
            <div><strong>Motivo original:</strong> ${escaparHTML(merma.motivo) || '-'}</div>
            <div style="margin-top:6px; padding-top:6px; border-top:1px solid #e2e8f0;">
                Se devolverán <strong>${merma.cantidad}</strong> unidad(es) al inventario
                (valor: <strong>$${merma.costo_total_usd.toFixed(2)}</strong>)
            </div>`;
    }

    const motivo = document.getElementById('anularMermaMotivo');
    if (motivo) motivo.value = '';

    const modal = document.getElementById('modalAnularMerma');
    if (modal) modal.style.display = 'flex';
}

function cerrarModalAnularMerma() {
    const modal = document.getElementById('modalAnularMerma');
    if (modal) modal.style.display = 'none';
}

async function confirmarAnulacionMerma(e) {
    e.preventDefault();

    const elId = document.getElementById('anularMermaId');
    const mermaId = elId ? parseInt(elId.textContent) : 0;
    const motivo = document.getElementById('anularMermaMotivo')?.value?.trim();

    if (!mermaId) {
        alert('No se pudo identificar la pérdida a anular.');
        return;
    }
    if (!motivo) {
        alert('Indica el motivo de la anulación.');
        return;
    }

    try {
        const response = await fetch('anular_merma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ merma_id: mermaId, motivo_anulacion: motivo })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Pérdida anulada correctamente.');
            cerrarModalAnularMerma();
            cargarMermas();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();

            const avisos = resData.advertencias || [];
            avisos.forEach(a => alert(`⚠️ ${a}`));
        } else {
            alert(`Error al anular la pérdida: ${resData.error || 'Ocurrió un error inesperado.'}`);
        }

    } catch (err) {
        console.error("Error al anular merma:", err);
        alert("No se pudo procesar la respuesta del servidor al anular la pérdida.");
    }
}

async function abrirModalRegistrarMerma() {
    const select = document.getElementById('mermaProductoId');
    const modal = document.getElementById('modalRegistrarMerma');
    const infoBox = document.getElementById('mermaInfoBox');
    const form = document.getElementById('formMerma');

    if (form) form.reset();
    if (infoBox) infoBox.style.display = 'none';
    const elCalculado = document.getElementById('mermaTotalCalculadoUSD');
    if (elCalculado) elCalculado.innerText = '$0.00 USD';

    if (select) {
        select.innerHTML = '<option value="">⌛ Cargando catálogo de productos...</option>';
    }

    if (modal) modal.style.display = 'flex';

    try {
        const response = await fetch('get_productos_admin.php');
        const productos = await response.json();
        window.productosMermasCache = productos || [];

        if (select) {
            if (!productos || productos.length === 0) {
                select.innerHTML = '<option value="">No hay productos disponibles</option>';
                return;
            }

            let options = '<option value="">-- Seleccionar producto del catálogo --</option>';
            productos.forEach(p => {
                const stock = parseInt(p.stock) || 0;
                const costo = parseFloat(p.precio_compra) > 0 ? parseFloat(p.precio_compra) : parseFloat(p.precio_usd);
                const disabledStr = stock <= 0 ? 'disabled' : '';
                const stockLabel = stock <= 0 ? ' [SIN STOCK]' : ` (Stock: ${stock})`;
                options += `<option value="${escaparHTML(p.id)}" data-stock="${stock}" data-costo="${costo}" ${disabledStr}>${escaparHTML(p.nombre)}${stockLabel}</option>`;
            });

            select.innerHTML = options;
        }

    } catch (err) {
        console.error("Error al cargar productos para merma:", err);
        if (select) select.innerHTML = '<option value="">Error al cargar productos</option>';
    }
}

function cerrarModalRegistrarMerma() {
    const modal = document.getElementById('modalRegistrarMerma');
    if (modal) modal.style.display = 'none';
}

function actualizarInfoProductoMerma() {
    const select = document.getElementById('mermaProductoId');
    const infoBox = document.getElementById('mermaInfoBox');
    const stockEl = document.getElementById('mermaInfoStock');
    const costoEl = document.getElementById('mermaInfoCosto');

    if (!select || !select.value) {
        if (infoBox) infoBox.style.display = 'none';
        calcularTotalPerdidaMerma();
        return;
    }

    const selectedOption = select.options[select.selectedIndex];
    const stock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
    const costo = parseFloat(selectedOption.getAttribute('data-costo')) || 0.00;

    if (stockEl) stockEl.innerText = stock;
    if (costoEl) costoEl.innerText = `$${costo.toFixed(2)}`;
    if (infoBox) infoBox.style.display = 'block';

    const inputCant = document.getElementById('mermaCantidad');
    if (inputCant) {
        inputCant.max = stock;
        if (parseInt(inputCant.value) > stock) {
            inputCant.value = stock;
        }
    }

    calcularTotalPerdidaMerma();
}

function calcularTotalPerdidaMerma() {
    const select = document.getElementById('mermaProductoId');
    const inputCant = document.getElementById('mermaCantidad');
    const elCalculado = document.getElementById('mermaTotalCalculadoUSD');

    if (!select || !select.value || !inputCant || !inputCant.value) {
        if (elCalculado) elCalculado.innerText = '$0.00 USD';
        return;
    }

    const selectedOption = select.options[select.selectedIndex];
    const costo = parseFloat(selectedOption.getAttribute('data-costo')) || 0.00;
    const cantidad = parseInt(inputCant.value) || 0;

    const totalUSD = Math.max(0, cantidad * costo);
    if (elCalculado) {
        elCalculado.innerText = `$${totalUSD.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})} USD`;
    }
}

async function guardarSalidaMerma(e) {
    e.preventDefault();

    const productoId = document.getElementById('mermaProductoId')?.value;
    const tipoSalida = document.getElementById('mermaTipoSalida')?.value;
    const cantidadRaw = document.getElementById('mermaCantidad')?.value;
    const motivo = document.getElementById('mermaMotivo')?.value?.trim();

    if (!productoId || !tipoSalida || !cantidadRaw || !motivo) {
        alert("Por favor completa todos los campos obligatorios del formulario.");
        return;
    }

    const cantidad = parseInt(cantidadRaw);
    if (!Number.isInteger(cantidad) || cantidad <= 0) {
        alert("La cantidad debe ser un número entero mayor a cero.");
        return;
    }

    // Aviso temprano si se supera el stock (el backend también lo valida con FOR UPDATE)
    const optSeleccionado = document.getElementById('mermaProductoId')?.selectedOptions?.[0];
    const stockDisponible = parseInt(optSeleccionado?.getAttribute('data-stock')) || 0;
    if (cantidad > stockDisponible) {
        alert(`No puedes descontar ${cantidad} unidades: el producto solo tiene ${stockDisponible} en inventario.`);
        return;
    }

    const payload = {
        producto_id: parseInt(productoId),
        tipo_salida: tipoSalida,
        cantidad: cantidad,
        motivo: motivo
    };

    try {
        const response = await fetch('save_merma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const resData = await response.json();

        if (resData.success) {
            // OJO: antes se llamaba a mostrarToastNotification(), que NO existe en este
            // archivo. Lanzaba ReferenceError, caía en el catch y mostraba "No se pudo
            // conectar con el servidor" aunque la merma ya estuviera guardada.
            mostrarNotificacion(resData.message || 'Salida no operativa registrada con éxito.');
            cerrarModalRegistrarMerma();
            cargarMermas();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();

            const avisos = resData.advertencias || [];
            avisos.forEach(a => alert(`⚠️ ${a}`));

        } else {
            alert(`Error al registrar salida: ${resData.error || 'Ocurrió un error inesperado.'}`);
        }

    } catch (err) {
        console.error("Error al guardar salida de merma:", err);
        alert("No se pudo procesar la respuesta del servidor al registrar la pérdida.");
    }
}


// ==================== MÓDULO DE DEVOLUCIONES Y REEMBOLSOS ====================

window.devolucionItemsCache = [];    // lineas de la venta seleccionada en el modal
window.ventasDevolublesCache = [];   // resultado de la busqueda de ventas
window.devolucionTotalCache = 0;     // total calculado de la devolución en curso

const METODOS_REEMBOLSO_LABEL = {
    'efectivo':     '💵 Efectivo',
    'pago_movil':   '📱 Pago Móvil',
    'transferencia':'🏦 Transferencia',
    'credito':      '🎫 Crédito'
};

function fmtUSD(valor) {
    return '$' + parseFloat(valor || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function cerrarModalAnularDevolucion() {
    const modal = document.getElementById('modalAnularDevolucion');
    if (modal) modal.style.display = 'none';
}

async function confirmarAnulacionDevolucion(e) {
    e.preventDefault();

    const elId = document.getElementById('anularDevolucionId');
    const devolucionId = elId ? parseInt(elId.textContent) : 0;
    const motivo = document.getElementById('anularDevolucionMotivo')?.value?.trim();

    if (!devolucionId) {
        alert('No se pudo identificar la devolución a anular.');
        return;
    }
    if (!motivo) {
        alert('Indica el motivo de la anulación.');
        return;
    }

    try {
        const response = await fetch('anular_devolucion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ devolucion_id: devolucionId, motivo_anulacion: motivo })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Devolución anulada correctamente.');
            cerrarModalAnularDevolucion();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();
            // El saldo esperado de caja pudo cambiar
            if (typeof cargarSeccionCaja === 'function') cargarSeccionCaja();
            if (typeof refrescarHistorialSiEstaAbierto === 'function') refrescarHistorialSiEstaAbierto();

            (resData.advertencias || []).forEach(a => alert(`⚠️ ${a}`));
        } else {
            alert(`Error al anular la devolución: ${resData.error || 'Ocurrió un error inesperado.'}`);
        }

    } catch (err) {
        console.error("Error al anular devolución:", err);
        alert("No se pudo procesar la respuesta del servidor al anular la devolución.");
    }
}

/* ---------- Registrar devolución ---------- */

// Promesa de la carga de ventas con mercancía por devolver. El módulo de
// Historial la espera para preseleccionar la venta del cliente abierto.
let promesaVentasDevolubles = null;

function cerrarModalRegistrarDevolucion() {
    const modal = document.getElementById('modalRegistrarDevolucion');
    if (modal) modal.style.display = 'none';
    window.devolucionItemsCache = [];
    window.ventasDevolublesCache = [];
    window.devolucionTotalCache = 0;
}

async function abrirModalRegistrarDevolucion() {
    const modal = document.getElementById('modalRegistrarDevolucion');
    const form = document.getElementById('formDevolucion');
    const select = document.getElementById('devolucionVentaId');
    const listaBox = document.getElementById('devolucionListaVentas');

    if (form) form.reset();
    window.devolucionItemsCache = [];
    window.ventasDevolublesCache = [];
    // Importante: sin esto, al reabrir el modal se mostraria el total de la
    // devolución anterior y el boton de confirmar mostraria un importe viejo.
    window.devolucionTotalCache = 0;

    // Desmarcar la venta que hubiera quedado seleccionada
    document.querySelectorAll('input[name="ventaDevolucionElegida"]')
        .forEach(r => { r.checked = false; });

    document.getElementById('devolucionDetalleBox')?.style.setProperty('display', 'none');
    document.getElementById('devolucionCamposPago')?.style.setProperty('display', 'none');
    document.getElementById('devolucionTodoDevuelto')?.style.setProperty('display', 'none');
    document.getElementById('devolucionAvisoEfectivo')?.style.setProperty('display', 'none');

    const total = document.getElementById('devolucionTotalCalculado');
    if (total) total.innerText = '$0.00 USD';

    if (select) select.innerHTML = '<option value="">-- Busca y selecciona una venta --</option>';
    if (listaBox) { listaBox.style.display = 'none'; listaBox.innerHTML = ''; }

    if (modal) modal.style.display = 'flex';

    // Cargar de entrada las ventas que aun tienen mercaderia por devolver
    promesaVentasDevolubles = buscarVentasDevolubles();

    return promesaVentasDevolubles;
}

async function buscarVentasDevolubles() {
    const listaBox = document.getElementById('devolucionListaVentas');
    const select   = document.getElementById('devolucionVentaId');
    const buscar   = (document.getElementById('devolucionBuscarVenta')?.value || '').trim();

    if (!listaBox) return;

    listaBox.style.display = 'block';
    listaBox.innerHTML = '<div style="padding:12px; text-align:center; color:#888; font-size:0.85rem;">⌛ Buscando ventas con mercancía por devolver...</div>';

    try {
        const url = buscar ? `get_ventas_devolubles.php?buscar=${encodeURIComponent(buscar)}` : 'get_ventas_devolubles.php';
        const response = await fetch(url);
        const data = await response.json();

        if (!data.success) {
            listaBox.innerHTML = `<div style="padding:12px; color:#c53030; font-size:0.85rem;">Error: ${escaparHTML(data.error || 'no se pudieron cargar las ventas')}</div>`;
            return;
        }

        window.ventasDevolublesCache = data.ventas || [];

        if (window.ventasDevolublesCache.length === 0) {
            listaBox.innerHTML = `<div style="padding:12px; color:#888; font-size:0.85rem;">
                No hay ventas con mercancía por devolver${buscar ? ` para "<strong>${escaparHTML(buscar)}</strong>"` : ''}.
                Solo se pueden devolver ventas con pago validado, en preparación o entregadas.</div>`;
            return;
        }

        listaBox.innerHTML = window.ventasDevolublesCache.map(v => `
            <label style="display:flex; gap:10px; align-items:center; padding:9px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer; font-size:0.83rem;"
                   onmouseover="this.style.background='#faf5ff'"
                   onmouseout="this.style.background='transparent'">
                <input type="radio" name="ventaDevolucionElegida" value="${v.id}" style="cursor:pointer;"
                       onchange="seleccionarVentaDevolucion()">
                <div style="flex:1; min-width:0;">
                    <div>
                        <strong>Venta #${v.id}</strong>
                        <span style="color:#666;"> — ${escaparHTML(v.cliente_nombre) || 'Mostrador'}</span>
                        <span style="color:#888;"> (${new Date(v.fecha_venta).toLocaleDateString('es-VE')})</span>
                    </div>
                    <div style="color:#666; font-size:0.78rem;">
                        ${v.unidades} unids vendidas · ${v.unidades_devueltas} devueltas ·
                        <strong>${v.unidades_pendientes} por devolver</strong> · ${fmtUSD(v.total_usd)}
                    </div>
                </div>
            </label>`).join('');

        // El <select> queda sincronizado por si el flujo usa esa vía
        if (select) {
            select.innerHTML = '<option value="">-- Busca y selecciona una venta --</option>' +
                window.ventasDevolublesCache.map(v =>
                    `<option value="${v.id}">Venta #${v.id} — ${escaparHTML(v.cliente_nombre) || 'Mostrador'} (${v.unidades_pendientes} pend.)</option>`
                ).join('');
        }

    } catch (err) {
        console.error("Error al buscar ventas devolubles:", err);
        listaBox.innerHTML = '<div style="padding:12px; color:#c53030; font-size:0.85rem;">No se pudo conectar con el servidor.</div>';
    }
}

async function seleccionarVentaDevolucion() {
    const radio  = document.querySelector('input[name="ventaDevolucionElegida"]:checked');
    const select = document.getElementById('devolucionVentaId');
    const ventaId = radio ? parseInt(radio.value) : parseInt(select?.value || 0);

    if (!ventaId) return;

    const detalleBox = document.getElementById('devolucionDetalleBox');
    const camposPago  = document.getElementById('devolucionCamposPago');
    const resumen     = document.getElementById('devolucionVentaResumen');
    const itemsBody   = document.getElementById('devolucionItemsBody');
    const todoDevuelto= document.getElementById('devolucionTodoDevuelto');

    detalleBox.style.display = 'block';
    resumen.innerHTML = '<div style="color:#888;">⌛ Cargando productos de la venta...</div>';
    itemsBody.innerHTML = '';
    camposPago.style.display = 'none';
    todoDevuelto.style.display = 'none';

    try {
        const response = await fetch(`get_items_venta_devolucion.php?venta_id=${ventaId}`);
        const data = await response.json();

        if (!data.success) {
            resumen.innerHTML = `<div style="color:#c53030; font-weight:600;">${escaparHTML(data.error || 'No se pudo cargar la venta.')}</div>`;
            window.devolucionItemsCache = [];
            actualizarResumenDevolucion();
            return;
        }

        const v = data.venta;
        window.devolucionItemsCache = data.items || [];

        resumen.innerHTML = `
            <div><strong>Venta #${v.id}</strong> — ${escaparHTML(v.cliente_nombre) || 'Cliente de mostrador'}</div>
            <div>Fecha: ${new Date(v.fecha_venta).toLocaleString('es-VE', {dateStyle: 'short', timeStyle: 'short'})}</div>
            <div>Total de la venta: <strong>${fmtUSD(v.total_usd)}</strong> (Bs ${parseFloat(v.total_bs).toLocaleString('es-VE', {minimumFractionDigits: 2})} a tasa ${parseFloat(v.tasa_cambio).toFixed(2)})</div>
            <div>Estado: <strong>${escaparHTML(v.estado)}</strong></div>`;

        if (!window.devolucionItemsCache.length) {
            itemsBody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px; color:#888;">Esta venta no tiene productos registrados.</td></tr>';
            actualizarResumenDevolucion();
            return;
        }

        itemsBody.innerHTML = window.devolucionItemsCache.map(it => {
            const agotado = it.pendiente === 0;
            return `<tr style="${agotado ? 'opacity:0.5;' : ''}">
                <td>
                    ${escaparHTML(it.nombre_producto)}
                    ${it.producto_id === null ? '<div style="font-size:0.72rem; color:#b45309;">⚠️ Producto eliminado del catálogo</div>' : ''}
                </td>
                <td style="text-align:center;">${it.cantidad}</td>
                <td style="text-align:center;">${it.ya_devuelto}</td>
                <td style="text-align:center;"><strong>${it.pendiente}</strong></td>
                <td style="text-align:center;">
                    ${agotado
                        ? '<span style="font-size:0.75rem; color:#94a3b8;">Todo devuelto</span>'
                        : `<input type="number" min="0" max="${it.pendiente}" value="0"
                                   oninput="calcularTotalDevolucion()"
                                   style="width:70px; padding:6px; border:1px solid #d1d5db; border-radius:6px; font-weight:700; text-align:center;">`}
                </td>
                <td style="text-align:right;" data-subtotal="${it.id}">${fmtUSD(0)}</td>
            </tr>`;
        }).join('');

        const hayPendiente = window.devolucionItemsCache.some(it => it.pendiente > 0);
        todoDevuelto.style.display = hayPendiente ? 'none' : 'block';
        camposPago.style.display = hayPendiente ? 'flex' : 'none';

        actualizarResumenDevolucion();

    } catch (err) {
        console.error("Error al cargar items de la venta:", err);
        resumen.innerHTML = '<div style="color:#c53030; font-weight:600;">No se pudo conectar con el servidor.</div>';
        window.devolucionItemsCache = [];
    }
}

function calcularTotalDevolucion() {
    const inputs = document.querySelectorAll('#devolucionItemsBody input[type="number"]');
    let total = 0;

    inputs.forEach(inp => {
        // El indice coincide con el orden de devolucionItemsCache
        const itemsBody = document.getElementById('devolucionItemsBody');
        const fila = inp.closest('tr');
        const celdaSubtotal = fila?.querySelector('[data-subtotal]');

        // Recoverir el item por el id de la fila
        const item = window.devolucionItemsCache.find(it =>
            celdaSubtotal && String(it.id) === celdaSubtotal.getAttribute('data-subtotal'));
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

    // Guardar el total para el submit
    window.devolucionTotalCache = Math.round(total * 100) / 100;
    actualizarResumenDevolucion();
}

function actualizarResumenDevolucion() {
    const elTotal = document.getElementById('devolucionTotalCalculado');
    const aviso   = document.getElementById('devolucionAvisoEfectivo');
    const metodo  = document.getElementById('devolucionMetodo')?.value || 'efectivo';

    const total = parseFloat(window.devolucionTotalCache || 0);

    if (elTotal) {
        elTotal.innerText = total > 0
            ? `${fmtUSD(total)} USD`
            : '$0.00 USD  (selecciona las cantidades)';
    }

    if (!aviso) return;

    if (total <= 0) {
        aviso.style.display = 'none';
        aviso.innerHTML = '';
        return;
    }

    if (metodo === 'efectivo') {
        aviso.style.display = 'block';
        aviso.innerHTML = `
            <strong>⚠️ Sale dinero del cajón.</strong>
            Se descontarán <strong>${fmtUSD(total)}</strong> del saldo esperado del turno de caja.
            Si el cajón no tiene ese efectivo, el arqueo mostrará un faltante hasta que lo repongas.`;
    } else if (metodo === 'credito') {
        aviso.style.display = 'block';
        aviso.innerHTML = `
            <strong>🎫 No sale dinero del cajón.</strong>
            Se registra <strong>${fmtUSD(total)}</strong> como crédito del cliente para una próxima compra.
            El saldo esperado de caja <strong>no cambia</strong>.`;
    } else {
        aviso.style.display = 'block';
        aviso.innerHTML = `
            <strong>ℹ️ Reembolso por ${METODOS_REEMBOLSO_LABEL[metodo] || metodo}.</strong>
            Transfiere <strong>${fmtUSD(total)}</strong> al cliente por esa vía.
            El saldo esperado de caja <strong>no cambia</strong>, porque el dinero nunca estuvo en el cajón.`;
    }
}

async function guardarDevolucion(e) {
    e.preventDefault();

    const ventaId = (() => {
        const radio  = document.querySelector('input[name="ventaDevolucionElegida"]:checked');
        const select = document.getElementById('devolucionVentaId');
        return radio ? parseInt(radio.value) : parseInt(select?.value || 0);
    })();

    const metodo = document.getElementById('devolucionMetodo')?.value || '';
    const motivo = document.getElementById('devolucionMotivo')?.value?.trim();
    const notas  = document.getElementById('devolucionNotas')?.value?.trim();

    if (!ventaId) {
        alert('Selecciona la venta a devolver.');
        return;
    }
    if (!motivo) {
        alert('Indica el motivo de la devolución.');
        return;
    }

    // Armar el detalle leyendo las cantidades del formulario
    const detalles = [];
    document.querySelectorAll('#devolucionItemsBody input[type="number"]').forEach(inp => {
        const fila = inp.closest('tr');
        const celda = fila?.querySelector('[data-subtotal]');
        if (!celda) return;

        const item = window.devolucionItemsCache.find(it => String(it.id) === celda.getAttribute('data-subtotal'));
        if (!item) return;

        const cant = parseInt(inp.value || 0);
        if (cant > 0) {
            detalles.push({ venta_detalle_id: item.id, cantidad: cant });
        }
    });

    if (detalles.length === 0) {
        alert('Indica al menos una cantidad a devolver.');
        return;
    }

    const total = parseFloat(window.devolucionTotalCache || 0);

    // Confirmación explícita antes de tocar stock y dinero
    const detalleTexto = detalles.map(d => {
        const item = window.devolucionItemsCache.find(it => it.id === d.venta_detalle_id);
        return `  - ${d.cantidad} und. de "${item ? item.nombre_producto : '?'}"`;
    }).join('\n');

    const confirmacion =
        `Registrar devolución de la venta #${ventaId}:\n\n${detalleTexto}\n\n` +
        `Total a devolver: ${fmtUSD(total)}\n` +
        `Método: ${METODOS_REEMBOLSO_LABEL[metodo] || metodo}\n\n` +
        (metodo === 'efectivo'
            ? `El stock volverá al inventario y el efectivo se descontará del saldo de caja.\n\n`
            : `El stock volverá al inventario. El saldo de caja no cambia.\n\n`) +
        `¿Confirmas?`;

    if (!confirm(confirmacion)) return;

    const btn = document.getElementById('devolucionBtnGuardar');
    const textoOriginal = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Registrando...'; }

    try {
        const response = await fetch('save_devolucion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                venta_id: ventaId,
                metodo_reembolso: metodo,
                motivo: motivo,
                notas: notas,
                detalles: detalles
            })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Devolución registrada.');
            cerrarModalRegistrarDevolucion();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();
            // Un reembolso en efectivo cambia el saldo esperado de caja
            if (typeof cargarSeccionCaja === 'function') cargarSeccionCaja();
            if (typeof refrescarHistorialSiEstaAbierto === 'function') refrescarHistorialSiEstaAbierto();

            (resData.advertencias || []).forEach(a => alert(`⚠️ ${a}`));
        } else {
            alert(`No se pudo registrar la devolución: ${resData.error || 'Ocurrió un error inesperado.'}`);
        }

    } catch (err) {
        console.error("Error al registrar devolución:", err);
        alert("No se pudo procesar la respuesta del servidor al registrar la devolución.");
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = textoOriginal; }
    }
}









// ==================== MÓDULO DE DEVOLUCIONES A PROVEEDORES ====================

let devolucionProvData = [];
let devolucionProvItemsCache = [];
let devolucionProvTotalCache = 0;

// Desde el botón de acción del módulo de Compras se muestra la sub-vista de
// devoluciones a proveedores dentro de la misma sección.
function abrirDevolucionesProveedorDesdeCompras() {
    const lista = document.getElementById('comprasVistaLista');
    const devoluciones = document.getElementById('comprasVistaDevoluciones');
    if (lista) lista.classList.add('hidden');
    if (devoluciones) devoluciones.classList.remove('hidden');

    document.querySelectorAll('.admin-nav-btn').forEach(b => b.classList.remove('active'));
    const btnProveedores = document.querySelector('.admin-nav-btn[data-section="proveedores"]');
    if (btnProveedores) btnProveedores.classList.add('active');

    cargarDevolucionesProveedor();
}

function volverAComprasLista() {
    const lista = document.getElementById('comprasVistaLista');
    const devoluciones = document.getElementById('comprasVistaDevoluciones');
    if (devoluciones) devoluciones.classList.add('hidden');
    if (lista) lista.classList.remove('hidden');
    iniciarFormularioCompra();
}

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

// Inicializar cargas extra cuando se muestra la sección
document.addEventListener('DOMContentLoaded', function() {
    if (typeof cargarDevolucionesProveedor === 'function') {
        setTimeout(cargarDevolucionesProveedor, 500);
    }

    // Aviso en vivo cuando las dos contraseñas nuevas no coinciden.
    const campoClave = document.getElementById('adminNuevaPassword');
    const campoRepetir = document.getElementById('adminConfirmarPassword');
    const avisoRepetir = document.getElementById('adminHintConfirmar');
    if (!campoClave || !campoRepetir || !avisoRepetir) return;

    function revisarCoincidenciaAdmin() {
        if (!campoRepetir.value) {
            avisoRepetir.textContent = 'Las dos contraseñas deben coincidir.';
            avisoRepetir.style.color = 'var(--text-light, #666)';
            avisoRepetir.style.fontWeight = 'normal';
            return;
        }
        const coinciden = campoClave.value === campoRepetir.value;
        avisoRepetir.textContent = coinciden
            ? '✓ Las contraseñas coinciden.'
            : '✗ Las contraseñas no coinciden.';
        avisoRepetir.style.color = coinciden ? '#276749' : '#c53030';
        avisoRepetir.style.fontWeight = '700';
    }

    campoRepetir.addEventListener('input', revisarCoincidenciaAdmin);
    campoClave.addEventListener('input', revisarCoincidenciaAdmin);
});

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

// Inicializar devoluciones a proveedor al cargar DOM
document.addEventListener('DOMContentLoaded', function() {
    if (typeof cargarDevolucionesProveedor === 'function') {
        setTimeout(cargarDevolucionesProveedor, 300);
    }
});


// ==================== MÓDULO DE APARTADOS Y RESERVAS ====================

let apartadosData = [];

// Modal Registrar Abono
let tasaApartadoAbonoActual = 50.00;

function abrirModalRegistrarAbonoApartado(id, resumen, total, abonado, pendiente, tasa, cuotaSugerida = 0) {
    document.getElementById('abonoApartadoId').value = id;
    document.getElementById('abonoApartadoSaldoPendiente').value = pendiente;
    tasaApartadoAbonoActual = tasa > 0 ? tasa : (tasaBCV || 50.00);

    const cuota = Math.min(parseFloat(cuotaSugerida || 0), parseFloat(pendiente));
    const elResumen = document.getElementById('abonoApartadoResumen');
    if (elResumen) {
        elResumen.innerHTML = `
            <div><strong>${resumen}</strong></div>
            <div style="display:flex; justify-content:space-between; margin-top:6px; font-size:0.85rem;">
                <span>Total: <strong>$${parseFloat(total).toFixed(2)}</strong></span>
                <span>Ya Abonado: <strong style="color:#15803d;">$${parseFloat(abonado).toFixed(2)}</strong></span>
                <span>Saldo Pendiente: <strong style="color:#b91c1c;">$${parseFloat(pendiente).toFixed(2)} USD</strong></span>
            </div>
            <div style="font-size:0.75rem; color:#888; margin-top:4px;">Tasa de cambio: ${tasaApartadoAbonoActual.toFixed(2)} Bs/USD</div>
            ${cuota > 0 ? `<div style="font-size:0.78rem; color:#4338ca; font-weight:700; margin-top:6px;">📐 Cuota sugerida: $${cuota.toFixed(2)} USD (puedes registrar otro monto)</div>` : ''}
        `;
    }

    // Se prellena con la cuota que falta pagar; si no hay plan, con todo el saldo.
    const montoInicial = cuota > 0 ? cuota : parseFloat(pendiente);
    document.getElementById('abonoMontoUSD').value = montoInicial.toFixed(2);
    document.getElementById('abonoMontoUSD').max = parseFloat(pendiente).toFixed(2);
    document.getElementById('abonoReferencia').value = '';
    document.getElementById('abonoNotas').value = '';
    calcularEquivalenteAbonoBs();

    const modal = document.getElementById('modalRegistrarAbonoApartado');
    if (modal) modal.style.display = 'flex';
}

function cerrarModalRegistrarAbonoApartado() {
    const modal = document.getElementById('modalRegistrarAbonoApartado');
    if (modal) modal.style.display = 'none';
}

function calcularEquivalenteAbonoBs() {
    const usd = parseFloat(document.getElementById('abonoMontoUSD')?.value || 0);
    const bs = usd * tasaApartadoAbonoActual;
    const elBs = document.getElementById('abonoMontoBsCalculado');
    if (elBs) elBs.value = `Bs ${bs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}

async function guardarAbonoApartado(e) {
    e.preventDefault();
    const apartadoId = parseInt(document.getElementById('abonoApartadoId')?.value || 0);
    const montoUsd = parseFloat(document.getElementById('abonoMontoUSD')?.value || 0);
    const metodo = document.getElementById('abonoMetodoPago')?.value || 'efectivo_usd';
    const referencia = document.getElementById('abonoReferencia')?.value?.trim() || '';
    const notas = document.getElementById('abonoNotas')?.value?.trim() || '';

    if (!apartadoId || montoUsd <= 0) {
        alert('Ingresa un monto de abono válido.');
        return;
    }

    const btn = document.getElementById('btnGuardarAbono');
    const txtOriginal = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Guardando...'; }

    try {
        const response = await fetch('save_abono.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                apartado_id: apartadoId,
                monto_usd: montoUsd,
                metodo_pago: metodo,
                referencia: referencia,
                notas: notas,
                tasa_cambio: tasaApartadoAbonoActual
            })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Abono registrado con éxito.');
            cerrarModalRegistrarAbonoApartado();
            if (typeof refrescarHistorialSiEstaAbierto === 'function') refrescarHistorialSiEstaAbierto();
        } else {
            alert(`No se pudo registrar el abono: ${resData.error || 'Error inesperado.'}`);
        }
    } catch (err) {
        console.error("Error al registrar abono:", err);
        alert('Error al conectar con el servidor.');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = txtOriginal; }
    }
}

// Entregar Producto Apartado
async function entregarApartadoDirecto(id, resumen, pendiente) {
    if (pendiente > 0.001) {
        alert(`No se puede entregar el producto. El cliente aún debe $${pendiente.toFixed(2)} USD. Debe pagar el 100% para retirarlo.`);
        return;
    }

    if (!confirm(`¿Confirmas la entrega del producto para ${resumen}? El cliente ya completó el 100% del pago.`)) {
        return;
    }

    try {
        const response = await fetch('entregar_apartado.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ apartado_id: id })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Producto entregado con éxito.');
            if (typeof refrescarHistorialSiEstaAbierto === 'function') refrescarHistorialSiEstaAbierto();
        } else {
            alert(`No se pudo entregar: ${resData.error || 'Error inesperado.'}`);
        }
    } catch (err) {
        console.error("Error al entregar apartado:", err);
        alert('Error al conectar con el servidor.');
    }
}

// Cancelar Apartado
function abrirModalCancelarApartado(id, resumen) {
    const ap = apartadosData.find(a => a.id === id);
    if (!ap) return;

    document.getElementById('cancelarApartadoId').value = id;
    document.getElementById('cancelarApartadoResumen').innerHTML = `
        <div><strong>${resumen}</strong></div>
        <div style="font-size:0.82rem; color:#666; margin-top:4px;">
            Total: $${ap.total_usd.toFixed(2)} | Abonado: $${ap.monto_abonado_usd.toFixed(2)} | Pendiente: $${ap.monto_pendiente_usd.toFixed(2)}
        </div>
        <div style="font-size:0.8rem; color:#b91c1c; margin-top:6px;">
            Productos reservados: ${escaparHTML(ap.productos_resumen || '')}
        </div>
    `;

    document.getElementById('cancelarApartadoMotivo').value = '';
    document.getElementById('cancelarReingresarStock').checked = true;

    const modal = document.getElementById('modalCancelarApartado');
    if (modal) modal.style.display = 'flex';
}

function cerrarModalCancelarApartado() {
    const modal = document.getElementById('modalCancelarApartado');
    if (modal) modal.style.display = 'none';
}

async function confirmarCancelacionApartado(e) {
    e.preventDefault();
    const id = parseInt(document.getElementById('cancelarApartadoId')?.value || 0);
    const motivo = document.getElementById('cancelarApartadoMotivo')?.value?.trim();
    const reingresarStock = document.getElementById('cancelarReingresarStock')?.checked;

    if (!id || !motivo) {
        alert('Por favor indica el motivo de la cancelación.');
        return;
    }

    if (!confirm('¿Estás seguro de cancelar este apartado?')) return;

    const btn = document.getElementById('btnConfirmarCancelacionAp');
    const txtOriginal = btn ? btn.innerText : '';
    if (btn) { btn.disabled = true; btn.innerText = 'Cancelando...'; }

    try {
        const response = await fetch('cancelar_apartado.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                apartado_id: id,
                motivo: motivo,
                reingresar_stock: reingresarStock
            })
        });

        const resData = await response.json();

        if (resData.success) {
            mostrarNotificacion(resData.message || 'Apartado cancelado.');
            cerrarModalCancelarApartado();
            cargarAlertasStock();
            if (typeof cargarInventario === 'function') cargarInventario();
            if (typeof refrescarHistorialSiEstaAbierto === 'function') refrescarHistorialSiEstaAbierto();
        } else {
            alert(`No se pudo cancelar: ${resData.error || 'Error inesperado.'}`);
        }
    } catch (err) {
        console.error("Error al cancelar apartado:", err);
        alert('Error al conectar con el servidor.');
    } finally {
        if (btn) { btn.disabled = false; btn.innerText = txtOriginal; }
    }
}


