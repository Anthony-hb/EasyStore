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
    <title>Mis Favoritos | <?php echo $nombreTienda; ?></title>
    <script>
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark-theme');
        }
    </script>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=3.6">
    <style>
        body {
            background-color: var(--bg, #f8f9fa);
            color: var(--text, #212529);
            min-height: 100vh;
        }
        .fav-container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .fav-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .fav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
        }
        .fav-card {
            background: var(--card-bg, #ffffff);
            border-radius: 20px;
            box-shadow: var(--shadow, 0 10px 30px rgba(0,0,0,0.05));
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            position: relative;
        }
        .fav-card:hover {
            transform: translateY(-5px);
        }
        .fav-img-container {
            width: 100%;
            height: 200px;
            background: #f0f0f0;
            position: relative;
            overflow: hidden;
        }
        .fav-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .fav-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .fav-category {
            font-size: 0.8rem;
            color: var(--primary, #4361ee);
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .fav-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }
        .fav-price {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text);
            margin-top: auto;
            margin-bottom: 15px;
        }
        .fav-actions {
            display: flex;
            gap: 10px;
        }
        .btn-add-cart {
            flex: 1;
            padding: 12px;
            background: var(--primary, #4361ee);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-add-cart:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        .btn-remove-fav {
            background: rgba(230, 57, 70, 0.1);
            color: var(--danger, #e63946);
            border: none;
            border-radius: 12px;
            padding: 12px;
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition);
        }
        .btn-remove-fav:hover {
            background: rgba(230, 57, 70, 0.2);
        }
        .empty-fav {
            text-align: center;
            padding: 60px 20px;
            background: var(--card-bg, #fff);
            border-radius: 24px;
            box-shadow: var(--shadow);
            grid-column: 1 / -1;
        }
        .nav-link-btn {
            text-decoration: none;
            color: var(--primary);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 12px;
            background: rgba(67, 97, 238, 0.08);
            transition: var(--transition);
        }
        .nav-link-btn:hover {
            background: rgba(67, 97, 238, 0.18);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <a href="../index.html" style="text-decoration: none; color: inherit;">
                    <h1>🛒 <?php echo $nombreTienda; ?></h1>
                </a>
            </div>
            <div class="header-actions">
                <button type="button" class="help-btn-header" onclick="abrirModalAyuda('cliente')" title="Centro de Ayuda / Manual de Usuario">
                    ❓ Ayuda
                </button>
                <a href="mis_pedidos.php" class="nav-link-btn">📦 Mis Pedidos</a>
                <a href="mi_cuenta.php" class="nav-link-btn">⚙️ Mi Cuenta</a>
                <a href="../index.html" class="nav-link-btn">🏠 Tienda</a>
            </div>
        </div>
    </header>

    <div class="fav-container">
        <div class="fav-header">
            <div>
                <h1 style="margin:0; font-size: 1.8rem;">⭐ Mis Productos Favoritos</h1>
                <p style="color: var(--text-light, #6c757d); margin-top: 5px;">Tus artículos guardados para comprar más tarde</p>
            </div>
        </div>

        <div id="favListContainer" class="fav-grid">
            <div class="empty-fav">
                <div style="font-size: 3rem;">⌛</div>
                <p>Cargando tus favoritos...</p>
            </div>
        </div>
    </div>

    <script>
        function cargarFavoritos() {
            fetch('../api/get_favoritos.php')
                .then(res => res.json())
                .then(data => {
                    const container = document.getElementById('favListContainer');
                    if (data.success && data.productos && data.productos.length > 0) {
                        container.innerHTML = data.productos.map(p => {
                            const imgUrl = p.imagen || 'https://via.placeholder.com/300?text=Sin+Imagen';
                            return `
                                <div class="fav-card" id="fav-card-${p.id}">
                                    <div class="fav-img-container">
                                        <img src="${imgUrl}" alt="${p.nombre}">
                                    </div>
                                    <div class="fav-body">
                                        <div class="fav-category">${p.categoria || 'Producto'}</div>
                                        <h3 class="fav-title">${p.nombre}</h3>
                                        <div class="fav-price">$${parseFloat(p.precio_usd).toFixed(2)}</div>
                                        <div class="fav-actions">
                                            <button class="btn-add-cart" onclick="agregarAlCarritoDesdeFavoritos(${p.id}, '${escapeHtml(p.nombre)}', ${p.precio_usd}, '${imgUrl}')">
                                                🛒 Al Carrito
                                            </button>
                                            <button class="btn-remove-fav" onclick="quitarFavorito(${p.id})" title="Quitar de favoritos">
                                                ❌
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    } else {
                        container.innerHTML = `
                            <div class="empty-fav">
                                <div style="font-size: 4rem; margin-bottom: 15px;">❌</div>
                                <h3>Aún no tienes productos favoritos</h3>
                                <p style="color: var(--text-light); margin-top: 5px;">Explora el catálogo y marca los productos con un ⭐ para verlos aquí.</p>
                                <a href="../index.html" class="btn-add-cart" style="display: inline-flex; width: auto; margin-top: 20px; padding: 12px 24px; text-decoration: none;">
                                    🔍 Explorar Catálogo
                                </a>
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('favListContainer').innerHTML = '<div class="empty-fav"><p>Error al cargar favoritos.</p></div>';
                });
        }

        function quitarFavorito(productoId) {
            fetch('../api/toggle_favorito.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ producto_id: productoId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const card = document.getElementById(`fav-card-${productoId}`);
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.8)';
                        setTimeout(() => {
                            card.remove();
                            if (document.querySelectorAll('.fav-card').length === 0) {
                                cargarFavoritos();
                            }
                        }, 300);
                    }
                }
            });
        }

        function agregarAlCarritoDesdeFavoritos(id, nombre, precio, imagen) {
            let carrito = JSON.parse(localStorage.getItem('carrito')) || [];
            const index = carrito.findIndex(item => item.id === id);
            if (index > -1) {
                carrito[index].cantidad++;
            } else {
                carrito.push({ id, nombre, precio, imagen, cantidad: 1 });
            }
            localStorage.setItem('carrito', JSON.stringify(carrito));
            alert(`✅ ${nombre} agregado al carrito de compras.`);
        }

        function escapeHtml(text) {
            return String(text ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        }

        document.addEventListener('DOMContentLoaded', cargarFavoritos);
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="../assets/js/manual.js?v=1.0"></script>
</body>
</html>
