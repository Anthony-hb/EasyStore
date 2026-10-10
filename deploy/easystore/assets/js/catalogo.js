// ==================== CATÁLOGO - LÓGICA PRINCIPAL ====================

// Estado global del catálogo
let productos = []; 
let monedaActual = "usd";  
let categoriaActiva = "todas";  
let textoBusqueda = "";
let tasaBCV = 1; // Se cargará desde la DB
let negocioUbicacion = ""; // Se cargará desde la DB
let usuarioLogueado = null; // { id, usuario, rol }
let misFavoritosIds = [];

// Referencias al DOM
let searchInput, categoriesContainer, productsContainer, tasaDisplay;

// Función para formatear precio según moneda actual
function formatearPrecio(precio_usd) {
    if (monedaActual === "usd") {
        return `$${precio_usd.toFixed(2)}`;
    } else {
        return `Bs ${(precio_usd * tasaBCV).toFixed(2)}`;
    }
}

// Obtener enlace a Google Maps según texto o URL de la ubicación (Ruta en vivo / Cómo llegar)
function getGoogleMapsUrl(ubicacion) {
    if (!ubicacion) return '#';
    const trimmed = ubicacion.trim();
    if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
        return trimmed;
    }
    // Modo "Cómo llegar" (Direcciones en vivo desde la posición actual del cliente)
    return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(trimmed)}`;
}

// Ajustar selector de cantidad en tarjetas de producto
function cambiarCantidadProducto(productoId, delta) {
    const spanCantidad = document.getElementById(`qty-${productoId}`);
    if (!spanCantidad) return;

    const producto = productos.find(p => p.id === productoId);
    if (!producto) return;

    let cantidad = parseInt(spanCantidad.innerText) || 1;
    cantidad += delta;

    if (cantidad < 1) cantidad = 1;
    if (cantidad > producto.stock) cantidad = producto.stock;

    spanCantidad.innerText = cantidad;
}

function getQty(productoId) {
    const spanCantidad = document.getElementById(`qty-${productoId}`);
    if (!spanCantidad) return 1;
    const cantidad = parseInt(spanCantidad.innerText);
    return Number.isInteger(cantidad) && cantidad > 0 ? cantidad : 1;
}

function getCantidadModal() {
    const spanCantidad = document.getElementById('modalQty');
    if (!spanCantidad) return 1;
    const cantidad = parseInt(spanCantidad.innerText);
    return Number.isInteger(cantidad) && cantidad > 0 ? cantidad : 1;
}

function modificarCantidadModal(delta, max) {
    const spanCantidad = document.getElementById('modalQty');
    if (!spanCantidad) return;

    let actual = parseInt(spanCantidad.innerText) || 1;
    actual += delta;

    if (actual < 1) actual = 1;
    if (typeof max === 'number' && actual > max) actual = max;

    spanCantidad.innerText = actual;
}

function addAndClose(productoId) {
    const cantidad = getQty(productoId);
    agregarAlCarrito(productoId, cantidad);
    closeQuantityControls(productoId);
}

function toggleQuantityControls(productoId) {
    const panel = document.getElementById(`qty-panel-${productoId}`);
    if (!panel) return;
    panel.classList.toggle('hidden');
}

function closeQuantityControls(productoId) {
    const panel = document.getElementById(`qty-panel-${productoId}`);
    if (!panel) return;
    panel.classList.add('hidden');
}

function toggleCategoriesDropdown() {
    const menu = document.getElementById('categoriesMenu');
    if (!menu) return;
    menu.classList.toggle('hidden');
}

function mostrarCategorias() {
    const menu = document.getElementById('categoriesMenu');
    if (!menu) return;

    console.log('📂 mostrarCategorias ejecutado');
    renderizarCategorias();

    if (menu.classList.contains('hidden')) {
        menu.classList.remove('hidden');
    } else {
        menu.classList.add('hidden');
    }
    
    console.log('🔁 Categorías visibles:', !menu.classList.contains('hidden'));
}

// Actualizar display de la tasa de cambio
function actualizarTasaDisplay() {
    if (tasaDisplay) {
        tasaDisplay.innerText = tasaBCV.toFixed(2);
    }
}



// Renderizar botones de categorías
function renderizarCategorias() {
    const container = document.getElementById('categoriesContainer');
    if (!container) return;
    
    // Obtener categorías únicas (excluyendo 'todas' para ordenarlas aparte)
    const categoriasBase = [...new Set(productos.map(p => p.categoria))].sort();
    const categorias = ["todas", ...categoriasBase];
    
    let html = "";
    categorias.forEach(cat => {
        const nombre = cat === "todas" ? "📋 Todas" : cat;
        const isActive = categoriaActiva === cat || (categoriaActiva.startsWith(cat + ":"));
        html += `<button class="category-btn ${isActive ? 'active' : ''}" onclick="seleccionarCategoria('${cat}')">${nombre}</button>`;
    });
    
    // Si hay una categoría seleccionada (que no sea todas), mostrar sus subcategorías si existen
    if (categoriaActiva !== 'todas') {
        const catPrincipal = categoriaActiva.split(':')[0];
        const subcategorias = [...new Set(productos
            .filter(p => p.categoria === catPrincipal && p.subcategoria)
            .map(p => p.subcategoria)
        )].sort();
        
        if (subcategorias.length > 0) {
            html += `<div class="subcategories-row" style="width:100%; margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; padding:10px; background:rgba(0,0,0,0.02); border-radius:15px;">`;
            html += `<span style="width:100%; font-size:0.8rem; font-weight:700; color:var(--text-light); margin-bottom:5px; text-transform:uppercase;">Subcategorías de ${catPrincipal}:</span>`;
            
            // Botón para ver "Todo en [Categoria]"
            html += `<button class="subcategory-btn ${categoriaActiva === catPrincipal ? 'active' : ''}" onclick="seleccionarCategoria('${catPrincipal}')">Ver todo</button>`;
            
            subcategorias.forEach(sub => {
                const catFull = `${catPrincipal}:${sub}`;
                html += `<button class="subcategory-btn ${categoriaActiva === catFull ? 'active' : ''}" onclick="seleccionarCategoria('${catFull}')">${sub}</button>`;
            });
            html += `</div>`;
        }
    }
    
    container.innerHTML = html;
}

function seleccionarCategoria(cat) {
    categoriaActiva = cat;
    renderizarCategorias();
    renderizarProductos();
}

// Renderizar productos según filtros
function renderizarProductos() {
    if (!productsContainer) return;
    
    // Filtrar productos
    let productosFiltrados = productos.filter(p => {
        // Filtro por categoría
        if (categoriaActiva !== "todas") {
            if (categoriaActiva.includes(":")) {
                // Es categoria:subcategoria
                const [cat, sub] = categoriaActiva.split(":");
                if (p.categoria !== cat || p.subcategoria !== sub) return false;
            } else {
                // Es solo categoria principal
                if (p.categoria !== categoriaActiva) return false;
            }
        }
        // Filtro por búsqueda
        if (textoBusqueda && !p.nombre.toLowerCase().includes(textoBusqueda.toLowerCase()) && 
            !p.descripcion.toLowerCase().includes(textoBusqueda.toLowerCase())) return false;
        
        // Filtro por stock (ocultar si está agotado)
        if (p.stock <= 0) return false;

        return true;
    });
    
    if (productosFiltrados.length === 0) {
        productsContainer.innerHTML = `<div class="no-results">😕 No se encontraron productos</div>`;
        return;
    }
    
    // Ordenar productos
    const sortSelect = document.getElementById('sortSelect');
    const sortVal = sortSelect ? sortSelect.value : 'default';
    if (sortVal === 'price-asc') {
        productosFiltrados.sort((a, b) => a.precio_usd - b.precio_usd);
    } else if (sortVal === 'price-desc') {
        productosFiltrados.sort((a, b) => b.precio_usd - a.precio_usd);
    } else if (sortVal === 'name-asc') {
        productosFiltrados.sort((a, b) => a.nombre.localeCompare(b.nombre));
    } else {
        // default (más recientes primero)
        productosFiltrados.sort((a, b) => b.id - a.id);
    }
    
    let html = "";
    productosFiltrados.forEach(p => {
        const agotado = p.stock === 0;
        const stockBajo = p.stock > 0 && p.stock <= p.stock_minimo;
        const esFav = misFavoritosIds.includes(Number(p.id));
        const heartIcon = esFav ? '⭐' : '☆';
        
        html += `
            <div class="product-card" data-product-id="${p.id}" style="position:relative;">
                <button type="button" class="btn-fav-card" onclick="event.stopPropagation(); toggleFavoritoCatalogo(${p.id})" title="${esFav ? 'Quitar de favoritos' : 'Guardar en favoritos'}" style="position:absolute; top:12px; right:12px; z-index:5; background:rgba(255,255,255,0.9); border:none; border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; cursor:pointer; box-shadow:0 4px 10px rgba(0,0,0,0.12); transition:transform 0.2s;">
                    ${heartIcon}
                </button>
                ${agotado ? `<div class="product-badge badge-agotado">❌ AGOTADO</div>` : ""}
                <img src="${p.imagen}" alt="${p.nombre}" class="product-image">
                <div class="product-info">
                    <div class="product-title">${p.nombre}</div>
                    <div class="product-description">${p.descripcion}</div>
                    ${negocioUbicacion ? `<div style="font-size:0.8rem; color:var(--text-light); margin-top:4px;">📍 <a href="${getGoogleMapsUrl(negocioUbicacion)}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();" style="color:var(--text-light); text-decoration:underline;" title="Ver en Google Maps">${negocioUbicacion}</a></div>` : ''}
                    <div class="product-price">${formatearPrecio(p.precio_usd)}</div>
                    <div class="product-stock">
                        ${agotado ? '<span class="stock-agotado">📦 Sin stock</span>' : 
                          stockBajo ? `<span class="stock-bajo">⚠️ Últimas ${p.stock} unidades</span>` : 
                          `<span class="stock-disponible">✅ Stock: ${p.stock}</span>`}
                    </div>
                    <div class="product-card-actions">
                        <button class="add-to-cart ${agotado ? 'disabled' : 'active'}" 
                                ${agotado ? 'disabled' : `onclick="event.stopPropagation(); mostrarDetallesProducto(${p.id})"`}>
                            ${agotado ? '❌ No disponible' : '🛒 Ver detalles'}
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    
    productsContainer.innerHTML = html;

    // Añadir comportamiento de clic en tarjetas (para ver detalles) existiendo control de add-to-cart y qty
    productsContainer.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', () => {
            const id = Number(card.dataset.productId);
            if (!Number.isNaN(id)) {
                mostrarDetallesProducto(id);
            }
        });
    });
}

// Evento de búsqueda en tiempo real
function initBuscador() {
    if (searchInput) {
        searchInput.addEventListener("input", (e) => {
            textoBusqueda = e.target.value;
            renderizarProductos();
        });
    }
}



// Inicializar todo cuando el DOM esté listo
document.addEventListener("DOMContentLoaded", () => {
    // Obtener referencias del DOM
    searchInput = document.getElementById("searchInput");
    categoriesContainer = document.getElementById("categoriesContainer");
    productsContainer = document.getElementById("productsContainer");
    tasaDisplay = document.getElementById("tasaDisplay");
    
    actualizarTasaDisplay();
    verificarSesion();
    cargarCarritoStorage(); // Cargar carrito guardado
    initBuscador();

    // Inicializar filtro de ordenamiento
    const sortSelect = document.getElementById("sortSelect");
    if (sortSelect) sortSelect.addEventListener("change", renderizarProductos);

    // Inicializar icono de tema oscuro
    const toggleBtn = document.getElementById('theme-toggle');
    if (toggleBtn) {
        const isDark = document.documentElement.classList.contains('dark-theme');
        toggleBtn.innerText = isDark ? '☀️' : '🌙';
    }

    // Cargar productos desde la base de datos (PHP)
    fetch('api/get_productos.php')
        .then(res => res.json())
        .then(data => {
            if (Array.isArray(data)) {
                productos = data;
                renderizarCategorias();
                renderizarProductos();
            }
        });

    // Cargar configuración (Tasa del dólar y negocio)
    fetch('api/get_config.php')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (data.config.tasa_dolar) {
                    tasaBCV = parseFloat(data.config.tasa_dolar);
                }
                // Reglas de apartados definidas por el administrador
                window.configApartado = {
                    cuotas: parseInt(data.config.apartado_cuotas, 10) || 3,
                    dias_limite: parseInt(data.config.apartado_dias_limite, 10) || 15
                };
                if (data.config.negocio_ubicacion) {
                    negocioUbicacion = data.config.negocio_ubicacion;
                }
                if (data.config.negocio_nombre && data.config.negocio_nombre.trim() !== '') {
                    const negNombre = data.config.negocio_nombre.trim();
                    document.title = `${negNombre} - Catálogo Digital`;
                    const logoH1 = document.getElementById('brandTitle') || document.querySelector('.logo h1');
                    if (logoH1) logoH1.innerText = `🛒 ${negNombre}`;
                    const footerCopy = document.querySelector('.footer p');
                    if (footerCopy && footerCopy.innerText.includes('©')) {
                        footerCopy.innerText = `© ${new Date().getFullYear()} ${negNombre}`;
                    }
                }
                actualizarVistasTasa();
                renderizarProductos();
            }
        });
});

function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark-theme');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    const toggleBtn = document.getElementById('theme-toggle');
    if (toggleBtn) {
        toggleBtn.innerText = isDark ? '☀️' : '🌙';
    }
}

function cambiarImagenPrincipal(src, element) {
    const mainImg = document.getElementById('mainDetailImage');
    if (mainImg) {
        mainImg.src = src;
    }
    
    // Actualizar clase activa en miniaturas
    document.querySelectorAll('.thumbnail').forEach(t => t.classList.remove('active'));
    if (element) element.classList.add('active');
}

// Lógica de Zoom tipo Mercado Libre
function initZoomEffect() {
    const wrapper = document.querySelector('.main-image-wrapper');
    if (!wrapper) return;

    const img = wrapper.querySelector('img');

    wrapper.addEventListener('mousemove', (e) => {
        const { left, top, width, height } = wrapper.getBoundingClientRect();
        const x = ((e.clientX - left) / width) * 100;
        const y = ((e.clientY - top) / height) * 100;
        img.style.transformOrigin = `${x}% ${y}%`;
    });

    wrapper.addEventListener('mouseleave', () => {
        img.style.transformOrigin = 'center center';
    });
}

// Función para mostrar detalles del producto
function mostrarDetallesProducto(id) {
    const producto = productos.find(p => p.id === id);
    if (!producto) return;

    const modal = document.getElementById('productModal');
    const details = document.getElementById('productDetails');

    const agotado = producto.stock === 0;
    const stockBajo = producto.stock > 0 && producto.stock <= producto.stock_minimo;

    // Generar Miniaturas
    let thumbnailsHtml = "";
    if (producto.imagenes && producto.imagenes.length > 0) {
        thumbnailsHtml = `<div class="thumbnails-container">`;
        // Asegurarse de incluir la imagen principal en las miniaturas si no está
        const todasLasImagenes = [...new Set([producto.imagen, ...producto.imagenes])];
        todasLasImagenes.forEach((img, idx) => {
            thumbnailsHtml += `
                <div class="thumbnail ${idx === 0 ? 'active' : ''}" 
                     onmouseover="cambiarImagenPrincipal('${img}', this)"
                     onclick="cambiarImagenPrincipal('${img}', this)">
                    <img src="${img}" alt="Miniatura ${idx + 1}">
                </div>`;
        });
        thumbnailsHtml += `</div>`;
    }

    const fichaTecnicaHtml = producto.ficha_tecnica ? `
        <div class="specs-container">
            <h3 class="specs-title">📋 Especificaciones</h3>
            <div class="specs-sheet">${producto.ficha_tecnica}</div>
        </div>
    ` : "";

    details.innerHTML = `
        <div class="product-detail-grid">
            <!-- Contenedor Galería (Miniaturas + Imagen Principal) -->
            <div class="gallery-container">
                ${thumbnailsHtml}
                <div class="main-image-wrapper">
                    <img src="${producto.imagen}" id="mainDetailImage" alt="${producto.nombre}">
                </div>
            </div>

            <!-- Columna Derecha: Info -->
            <div class="info-container">
                <div class="detail-header">
                    <span class="detail-badge">${producto.categoria}</span>
                    <h2 class="product-detail-title">${producto.nombre}</h2>
                </div>
                
                <p class="product-detail-description">${producto.descripcion}</p>
                ${negocioUbicacion ? `<p style="font-size:0.9rem; color:var(--text-light); margin-top:5px; margin-bottom:15px;">📍 Ubicación: <a href="${getGoogleMapsUrl(negocioUbicacion)}" target="_blank" rel="noopener noreferrer" style="color:#2563eb; text-decoration:underline; font-weight:500;" title="Abrir en Google Maps">${negocioUbicacion} 🗺️</a></p>` : ''}

                <div class="price-section">
                    <span class="main-price-usd">${formatearPrecio(producto.precio_usd)}</span>
                    <span class="sub-price-bs">≈ Bs ${(producto.precio_usd * tasaBCV).toFixed(2)}</span>
                </div>

                <div class="stock-status">
                    ${agotado ? '<span class="stock-agotado">📦 Sin stock disponible</span>' : 
                      stockBajo ? `<span class="stock-bajo">⚠️ ¡Últimas ${producto.stock} unidades!</span>` : 
                      `<span class="stock-disponible">✅ En stock: ${producto.stock} unidades</span>`}
                </div>

                <div class="detail-actions">
                    <div class="product-detail-qty">
                        <button type="button" class="qty-btn" onclick="modificarCantidadModal(-1, ${producto.stock})" ${agotado ? 'disabled' : ''}>−</button>
                        <span id="modalQty">1</span>
                        <button type="button" class="qty-btn" onclick="modificarCantidadModal(1, ${producto.stock})" ${agotado ? 'disabled' : ''}>+</button>
                    </div>

                    <button class="add-to-cart ${agotado ? 'disabled' : 'active'}" style="flex:1;"
                            ${agotado ? 'disabled' : `onclick="agregarAlCarrito(${id}, getCantidadModal()); cerrarModal()"`}>
                        ${agotado ? '❌ Agotado' : '🛒 Agregar al carrito'}
                    </button>
                </div>

                ${fichaTecnicaHtml}
            </div>
        </div>
    `;

    modal.classList.remove('hidden');
    initZoomEffect(); // Inicializar el efecto de lupa
}

// Función para cerrar el modal
function cerrarModal() {
    const modal = document.getElementById('productModal');
    modal.classList.add('hidden');
}

// Nueva función para centralizar la actualización de textos
function actualizarVistasTasa() {
    // Actualiza el footer que ve el Cliente
    const msgCliente = document.getElementById('tasaDisplay');
    if (msgCliente) msgCliente.innerText = tasaBCV.toFixed(2);
}

// Al cargar la página, ejecutar la sincronización
window.addEventListener('load', () => {
    actualizarVistasTasa();
});

function verificarSesion() {
    fetch('api/get_session.php')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('user-auth-container');
            if (!container) return;

            if (data.logged_in) {
                usuarioLogueado = data.user;
                cargarFavoritosIds();
                container.innerHTML = `
                    <div class="user-menu" style="position:relative;">
                        <button class="btn-user" onclick="toggleUserMenu()" style="background:var(--primary); color:white; border:none; padding:8px 15px; border-radius:50px; font-weight:600; cursor:pointer;">
                            👤 ${data.user.nombre}
                        </button>
                        <div id="userDropdown" class="hidden" style="position:absolute; top:45px; right:0; background:var(--card-bg); box-shadow:var(--shadow); border-radius:12px; padding:10px; min-width:150px; z-index:1000;">
                            ${data.user.rol === 'admin' ? '<a href="admin/index.php" style="display:block; padding:8px; text-decoration:none; color:var(--primary); font-size:0.9rem; font-weight:600;">🛠️ Panel Admin</a>' : ''}
                            <a href="customer/mis_pedidos.php" style="display:block; padding:8px; text-decoration:none; color:var(--text); font-size:0.9rem;">📦 Mis Pedidos</a>
                            <a href="customer/mis_favoritos.php" style="display:block; padding:8px; text-decoration:none; color:var(--text); font-size:0.9rem;">⭐ Mis Favoritos</a>
                            <a href="customer/mi_cuenta.php" style="display:block; padding:8px; text-decoration:none; color:var(--text); font-size:0.9rem;">⚙️ Mi Cuenta</a>
                            <hr style="margin:5px 0; border:0; border-top:1px solid rgba(150,150,150,0.2);">
                            <a href="auth/logout.php" style="display:block; padding:8px; text-decoration:none; color:var(--danger); font-size:0.9rem; font-weight:600;">🚪 Cerrar Sesión</a>
                        </div>
                    </div>
                `;
            } else {
                usuarioLogueado = null;
                container.innerHTML = `
                    <a href="auth/login.php" class="login-btn" style="text-decoration:none; padding: 8px 16px; background: var(--primary); color: white; border-radius: 50px; font-size: 0.9rem;">Entrar</a>
                    <a href="auth/register.php" class="register-btn" style="text-decoration:none; color:var(--primary); font-weight:600; font-size:0.9rem;">Registrarse</a>
                `;
            }
        });
}

function toggleUserMenu() {
    const menu = document.getElementById('userDropdown');
    if (menu) menu.classList.toggle('hidden');
}

// Cerrar menu al hacer clic fuera
document.addEventListener('click', (e) => {
    if (!e.target.closest('.user-menu')) {
        const menu = document.getElementById('userDropdown');
        if (menu) menu.classList.add('hidden');
    }
});

// ==================== GESTIÓN DE FAVORITOS ====================
function cargarFavoritosIds() {
    fetch('api/get_favoritos.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.favorited_ids) {
                misFavoritosIds = data.favorited_ids;
                renderizarProductos();
            }
        })
        .catch(err => console.error('Error al cargar IDs de favoritos:', err));
}

function toggleFavoritoCatalogo(productoId) {
    fetch('api/toggle_favorito.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ producto_id: productoId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.logged_in === false) {
            alert('Debes iniciar sesión para guardar productos en tus favoritos.');
            window.location.href = 'auth/login.php';
            return;
        }
        if (data.success) {
            if (data.is_favorito) {
                if (!misFavoritosIds.includes(Number(productoId))) {
                    misFavoritosIds.push(Number(productoId));
                }
            } else {
                misFavoritosIds = misFavoritosIds.filter(id => id !== Number(productoId));
            }
            renderizarProductos();
        }
    })
    .catch(err => console.error('Error al alternar favorito:', err));
}

