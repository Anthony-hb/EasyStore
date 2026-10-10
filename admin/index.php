<?php
session_start();
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit;
}
require_once '../includes/db.php';
$stmtAdminCfg = $pdo->query("SELECT * FROM administrador ORDER BY id ASC LIMIT 1");
$adminCfgRow = $stmtAdminCfg ? $stmtAdminCfg->fetch(PDO::FETCH_ASSOC) : null;
$negocioNombreAdmin = !empty($adminCfgRow['negocio_nombre']) ? htmlspecialchars($adminCfgRow['negocio_nombre']) : 'Tecnology';

// Configuración global de apartados (la administra el admin en Historial de Clientes)
$apartadoCuotasCfg = !empty($adminCfgRow['apartado_cuotas']) ? (int)$adminCfgRow['apartado_cuotas'] : 3;
$apartadoCuotasCfg = max(1, min(12, $apartadoCuotasCfg));
$apartadoDiasCfg = !empty($adminCfgRow['apartado_dias_limite']) ? (int)$adminCfgRow['apartado_dias_limite'] : 15;
$apartadoDiasCfg = max(1, min(90, $apartadoDiasCfg));

function renderPaginacionBar($tbodyId) {
    echo '
    <div class="paginacion-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:14px; padding:10px 14px; background:var(--bg-card, #f8fafc); border:1px solid var(--border-color, #e2e8f0); border-radius:8px; font-size:0.88rem;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span>Mostrar:</span>
            <select onchange="cambiarElementosPorPagina(\''.$tbodyId.'\', this)" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:0.85rem; background:#fff;">
                <option value="10" selected>10 registros</option>
                <option value="25">25 registros</option>
                <option value="50">50 registros</option>
                <option value="todos">Todos</option>
            </select>
        </div>
        <span id="pag-info-'.$tbodyId.'" style="font-weight:600; color:#555;">Página 1 de 1</span>
        <div style="display:flex; gap:6px;">
            <button type="button" id="pag-prev-'.$tbodyId.'" onclick="cambiarPaginaTabla(\''.$tbodyId.'\', -1)" style="padding:5px 12px; border:1px solid #ccc; border-radius:4px; background:#fff; cursor:pointer; font-weight:600;">◀ Anterior</button>
            <button type="button" id="pag-next-'.$tbodyId.'" onclick="cambiarPaginaTabla(\''.$tbodyId.'\', 1)" style="padding:5px 12px; border:1px solid #ccc; border-radius:4px; background:#fff; cursor:pointer; font-weight:600;">Siguiente ▶</button>
        </div>
    </div>';
}

/**
 * Barra de filtros reutilizable: texto libre + rango de fechas + rango de horas.
 * Trabaja con aplicarFiltrosTabla() de admin.js, que lee los controles desde
 * #filtros-<tbodyId> y compara contra el atributo data-fecha de cada fila
 * (y data-fecha-fin / data-abierto para intervalos, como los turnos de caja).
 *
 * @param string $tablaId    id del <tbody> de la tabla a filtrar
 * @param string $placeholder texto de ayuda del buscador
 * @param string $estiloExtra estilo CSS extra para la barra
 */
function renderBarraFiltrosFechaHora($tablaId, $placeholder, $estiloExtra = '') {
    $js = "aplicarFiltrosTabla('$tablaId')";
    $estiloBarra = 'display:flex; gap:10px; flex-wrap:wrap; align-items:center; background:rgba(0,0,0,0.02); padding:10px; border-radius:8px; margin-bottom:16px;' . $estiloExtra;
    $estiloInput = 'padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem; background:#fff; font-family:inherit;';
    $estiloLabel = 'display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;';
    $etiqueta = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');

    echo '<div class="tabla-filtros" id="filtros-' . $tablaId . '" style="' . $estiloBarra . '">'
        . '<input type="text" data-filtro="texto" oninput="' . $js . '" placeholder="🔍 ' . $etiqueta . '" style="flex:1; min-width:200px; padding:9px 12px; border:1px solid #ccc; border-radius:6px; font-size:0.9rem;">'
        . '<label style="' . $estiloLabel . '">📅 Fecha desde <input type="date" data-filtro="desde" onchange="' . $js . '" style="' . $estiloInput . '"></label>'
        . '<label style="' . $estiloLabel . '">Fecha hasta <input type="date" data-filtro="hasta" onchange="' . $js . '" style="' . $estiloInput . '"></label>'
        . '<label style="' . $estiloLabel . '">🕐 Hora desde <input type="time" data-filtro="hora-desde" onchange="' . $js . '" style="' . $estiloInput . '"></label>'
        . '<label style="' . $estiloLabel . '">Hora hasta <input type="time" data-filtro="hora-hasta" onchange="' . $js . '" style="' . $estiloInput . '"></label>'
        . '<button type="button" onclick="limpiarFiltrosTabla(\'' . $tablaId . '\')" style="padding:8px 12px; border:1px solid #ccc; border-radius:6px; background:#fff; cursor:pointer; font-size:0.85rem; font-weight:600;">🧹 Limpiar filtros</button>'
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SISTEMA_NOMBRE; ?> · Panel Administrativo | <?php echo $negocioNombreAdmin; ?></title>
    <script>
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark-theme');
        }
        // Identidad del sistema: Impulsado por EasyStore (nombre del negocio aparte)
        window.SISTEMA_NOMBRE = '<?php echo SISTEMA_NOMBRE; ?>';
        // Reglas globales de apartados (las edita el admin en Historial de Clientes)
        window.configApartado = {
            cuotas: <?php echo $apartadoCuotasCfg; ?>,
            dias_limite: <?php echo $apartadoDiasCfg; ?>
        };
    </script>
    <link rel="stylesheet" href="../assets/css/estilo.css?v=<?php echo time(); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body class="admin-body">

    <div class="admin-layout-wrapper">
        <!-- Barra Lateral Izquierda (Sidebar Vertical) -->
        <aside class="admin-sidebar">
            <div class="admin-sidebar-header">
                <div class="admin-brand" id="adminBrand">🛍️ <?php echo SISTEMA_NOMBRE; ?> <span>| <?php echo $negocioNombreAdmin; ?></span></div>
                <div class="admin-user-welcome">Bienvenido, <?php echo $_SESSION['usuario_nombre']; ?></div>
            </div>

            <nav class="admin-nav-vertical">
                <button type="button" class="admin-nav-btn active" data-section="inicio"
                    onclick="mostrarSeccionAdmin('inicio')">🏠 Inicio</button>
                <button type="button" class="admin-nav-btn" data-section="inventario"
                    onclick="mostrarSeccionAdmin('inventario')">📦 Inventario</button>
                <button type="button" class="admin-nav-btn" data-section="proveedores" 
                    onclick="mostrarSeccionAdmin('proveedores')">🏷️ Proveedores</button>
                <button type="button" class="admin-nav-btn" data-section="pedidos" 
                    onclick="mostrarSeccionAdmin('pedidos')">📋 Pedidos</button>
                <button type="button" class="admin-nav-btn" data-section="clientes" 
                    onclick="mostrarSeccionAdmin('clientes')">👥 Clientes</button>
                <button type="button" class="admin-nav-btn" data-section="nuevo" id="adminNavBtnNuevo"
                    onclick="mostrarSeccionAdmin('nuevo')">➕ Publicar</button>
                <button type="button" class="admin-nav-btn" data-section="historial" 
                    onclick="mostrarSeccionAdmin('historial')">🧾 Historial de Clientes</button>
                <button type="button" class="admin-nav-btn" data-section="caja"
                    onclick="mostrarSeccionAdmin('caja')">💵 Cierre de Caja</button>
                <button type="button" class="admin-nav-btn" data-section="fiscal"
                    onclick="mostrarSeccionAdmin('fiscal')">🧮 Libro de IVA</button>
                <button type="button" class="admin-nav-btn" data-section="pagos"
                    onclick="mostrarSeccionAdmin('pagos')" id="adminNavBtnPagos">&#9989; Pagos Pendientes</button>
                <button type="button" class="admin-nav-btn" data-section="micuenta"
                    onclick="mostrarSeccionAdmin('micuenta')">⚙️ Mi Cuenta</button>
                <button type="button" class="admin-nav-btn" data-section="seguridad"
                    onclick="mostrarSeccionAdmin('seguridad')">🛡️ Seguridad / BD</button>
                <button type="button" class="admin-nav-btn" data-section="ayuda" style="margin-top: 10px; background: rgba(67, 97, 238, 0.1); color: var(--primary);"
                    onclick="abrirModalAyuda('admin', true)">❓ Manual / Ayuda</button>
            </nav>

            <div class="admin-sidebar-footer">
                <a href="../auth/logout.php" class="admin-logout-btn">🚪 Cerrar sesión</a>
            </div>
        </aside>

        <!-- Contenido Principal a la Derecha -->
        <main class="admin-main-content">

        <!-- Panel de Control (Dashboard) -->
        <section id="admin-seccion-inicio" class="admin-panel-content">
            <div class="admin-card" style="margin-bottom: 25px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                    <div>
                        <h2 style="margin:0; font-size:1.6rem; display:flex; align-items:center; gap:8px;">📊 Panel de Control</h2>
                        <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                            Indicadores clave del negocio: ventas, inventario, pérdidas y más, reunidos en una sola pantalla.
                        </p>
                    </div>
                    <div id="admin-dash-fecha" style="font-size:0.85rem; font-weight:700; color:#4b5563; background:rgba(67,97,238,0.08); padding:8px 14px; border-radius:10px;"></div>
                </div>
            </div>

            <div id="stock-alerts-container" class="alert-box" style="display:none;">
                <h3>⚠️ Alertas de Stock Mínimo</h3>
                <ul id="stock-alerts-list">
                    <!-- Lista de alertas cargada por JS -->
                </ul>
            </div>
            
            <div id="admin-dashboard-stats" class="admin-stats-grid">
                <!-- KPIs (tarjetas) llenadas dinámicamente por JS -->
            </div>

            <div id="admin-dashboard-charts" class="admin-dash-charts-grid">
                <!-- Gráficas llenadas dinámicamente por JS -->
            </div>
        </section>

        <!-- Seccion Proveedores -->
        <div id="admin-seccion-proveedores" class="admin-panel-content hidden">
            <div class="admin-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <h2 style="margin:0;">🏷️ Gestión de Proveedores</h2>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte de Proveedores', 'proveedoresTableBody')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                        <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Proveedores', 'proveedoresTableBody')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        <button type="button" class="btn-primary" onclick="abrirModalProveedor()">+ Nuevo Proveedor</button>
                        <button type="button" class="btn-primary" onclick="mostrarSeccionAdmin('compras')" style="background:#0369a1;">🧾 Compras</button>
                    </div>
                </div>

                <?php renderBarraFiltrosFechaHora('proveedoresTableBody', 'Buscar proveedor por negocio, nombre, RIF, tipo de producto, marca o banco...'); ?>
                <div class="table-responsive">
                    <table class="inventory-table">
                        <thead>
                            <tr><th>ID</th><th>Negocio</th><th>Proveedor</th><th>Documento</th><th>Teléfono</th><th>Tipo de Producto</th><th>Marca</th><th>Banco</th><th>Registro</th><th>Acciones</th></tr>
                        </thead>
                        <tbody id="proveedoresTableBody"></tbody>
                    </table>
                </div>
                <?php renderPaginacionBar('proveedoresTableBody'); ?>
            </div>
        </div>

        <!-- Modal Proveedor -->
        <div id="modalProveedor" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; display:none; justify-content:center; align-items:center;">
            <div class="admin-card" style="width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; background: var(--bg-card, #fff); padding: 20px; border-radius: 8px;">
                <h3 id="modalProveedorTitulo" style="margin-top:0;">Añadir Proveedor</h3>
                <input type="hidden" id="provId" />
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <input id="provNegocio" placeholder="Nombre del Negocio" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <input id="provNombre" placeholder="Nombre del Proveedor" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <input id="provApellido" placeholder="Apellido del Proveedor" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <input id="provDocumento" placeholder="Documento de Identidad (RUC/CI)" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <div style="display:flex; align-items:center; border:1px solid #ddd; border-radius:4px; overflow:hidden;">
                        <input id="provEmail" placeholder="Correo (solo usuario)" style="padding:10px; border:none; flex:1; outline:none;" />
                        <span style="color:#888; font-weight:600; padding-right:10px;">@gmail.com</span>
                    </div>
                    <div style="display:flex; align-items:center; border:1px solid #ddd; border-radius:4px; overflow:hidden;">
                        <select id="prefijoProvTelefono" style="padding:10px; border:none; border-right:1px solid #ddd; outline:none; background:#f9f9f9; cursor:pointer;">
                            <option value="0412">0412</option>
                            <option value="0422">0422</option>
                            <option value="0414">0414</option>
                            <option value="0424">0424</option>
                            <option value="0416">0416</option>
                            <option value="0426">0426</option>
                        </select>
                        <input id="provTelefono" placeholder="Teléfono" style="padding:10px; border:none; outline:none; flex:1;" maxlength="7" />
                    </div>
                    <input id="provDireccion" placeholder="Dirección" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />

                    <h4 style="margin: 10px 0 0 0;">Producto que Vende</h4>
                    <input id="provTipoProducto" placeholder="Tipo de Producto (Ej: Ropa, Zapatos, Accesorios)" maxlength="100" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <input id="provMarcaProducto" placeholder="Marcas que vende (Ej: SanDisk, Kingston, HP)" maxlength="255" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <small style="color:#666; font-size:0.8rem; margin-top:-6px;">Separa varias marcas con comas. Todas aparecerán para elegir al registrar una compra.</small>
                    
                    <h4 style="margin: 10px 0 0 0;">Datos Bancarios</h4>
                    <select id="provBanco" style="padding:10px; border:1px solid #ddd; border-radius:4px; background:#fff;">
                        <option value="">Seleccione Banco...</option>
                        <option value="0102 - Banco de Venezuela">0102 - Banco de Venezuela</option>
                        <option value="0104 - Banco Venezolano de Crédito">0104 - Banco Venezolano de Crédito</option>
                        <option value="0105 - Mercantil">0105 - Mercantil</option>
                        <option value="0108 - Provincial">0108 - Provincial</option>
                        <option value="0114 - Bancaribe">0114 - Bancaribe</option>
                        <option value="0115 - Banco Exterior">0115 - Banco Exterior</option>
                        <option value="0128 - Banco Caroní">0128 - Banco Caroní</option>
                        <option value="0134 - Banesco">0134 - Banesco</option>
                        <option value="0137 - Banco Sofitasa">0137 - Banco Sofitasa</option>
                        <option value="0138 - Banco Plaza">0138 - Banco Plaza</option>
                        <option value="0151 - BFC Banco Fondo Común">0151 - BFC Banco Fondo Común</option>
                        <option value="0156 - 100% Banco">0156 - 100% Banco</option>
                        <option value="0157 - Bancosur">0157 - Bancosur</option>
                        <option value="0163 - Banco del Tesoro">0163 - Banco del Tesoro</option>
                        <option value="0166 - Banco Agrícola">0166 - Banco Agrícola</option>
                        <option value="0168 - Bancrecer">0168 - Bancrecer</option>
                        <option value="0169 - Mi Banco">0169 - Mi Banco</option>
                        <option value="0171 - Banco Activo">0171 - Banco Activo</option>
                        <option value="0172 - Bancamiga">0172 - Bancamiga</option>
                        <option value="0174 - Banplus">0174 - Banplus</option>
                        <option value="0175 - Banco Bicentenario">0175 - Banco Bicentenario</option>
                        <option value="0177 - Banfanb">0177 - Banfanb</option>
                        <option value="0191 - BNC">0191 - BNC</option>
                        <option value="Otro">Otro</option>
                    </select>
                    <input id="provCuenta" placeholder="Número de Cuenta Bancaria" style="padding:10px; border:1px solid #ddd; border-radius:4px;" />
                    <div style="display:flex; align-items:center; border:1px solid #ddd; border-radius:4px; overflow:hidden;">
                        <select id="prefijoProvTelefonoPago" style="padding:10px; border:none; border-right:1px solid #ddd; outline:none; background:#f9f9f9; cursor:pointer;">
                            <option value="0412">0412</option>
                            <option value="0422">0422</option>
                            <option value="0414">0414</option>
                            <option value="0424">0424</option>
                            <option value="0416">0416</option>
                            <option value="0426">0426</option>
                        </select>
                        <input id="provTelefonoPago" placeholder="Teléfono Pago Móvil" style="padding:10px; border:none; outline:none; flex:1;" maxlength="7" />
                    </div>

                    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                        <button class="btn-secondary" onclick="cerrarModalProveedor()" style="padding:10px 20px; border:none; border-radius:4px; cursor:pointer;">Cancelar</button>
                        <button class="btn-primary" onclick="guardarProveedor()" style="padding:10px 20px; border:none; border-radius:4px; cursor:pointer;">Guardar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seccion Compras -->
        <div id="admin-seccion-compras" class="admin-panel-content hidden">
            <div class="admin-card" id="comprasVistaLista">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <h2 style="margin:0;">🧾 Compras al Proveedor</h2>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte de Compras', 'comprasHistorialTable')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                        <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Compras', 'comprasHistorialTable')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        <button type="button" class="btn-primary" onclick="abrirModalCompra()">+ Registrar Compra</button>
                        <button type="button" class="btn-primary" onclick="abrirDevolucionesProveedorDesdeCompras()" style="background:#8e44ad; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">↩️ Dev. a Proveedor</button>
                    </div>
                </div>

                <?php renderBarraFiltrosFechaHora('comprasHistorialBody', 'Buscar compra por proveedor, factura o producto...'); ?>
                <!-- Historial de compras -->
                <div class="table-responsive">
                    <table class="inventory-table" id="comprasHistorialTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Proveedor</th>
                                <th>Factura</th>
                                <th>Fecha y Hora</th>
                                <th>Productos</th>
                                <th>Total USD</th>
                            </tr>
                        </thead>
                        <tbody id="comprasHistorialBody">
                            <tr><td colspan="7" style="text-align:center; color:#aaa; padding:20px;">Cargando historial de compras...</td></tr>
                        </tbody>
                    </table>
                </div>
                <?php renderPaginacionBar('comprasHistorialBody'); ?>
            </div>

            <!-- Sub-vista: Devoluciones a Proveedores (dentro del módulo de Compras) -->
            <div id="comprasVistaDevoluciones" class="hidden">
                <div class="admin-card" style="margin-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom: 20px;">
                        <div>
                            <h2 style="margin:0; font-size:1.5rem; display:flex; align-items:center; gap:8px;">
                                ↩️ Devoluciones a Proveedores
                            </h2>
                            <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                                Registra devoluciones de mercancía recibida de proveedores. Al devolver, se resta del stock
                                y queda registrada para trazabilidad. Se puede anular reingresando la mercancía al inventario.
                            </p>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button type="button" onclick="volverAComprasLista()" style="padding:12px 20px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px;">
                                ← Volver a Compras
                            </button>
                            <button type="button" onclick="abrirModalRegistrarDevolucionProveedor()" style="padding:12px 20px; background:#8e44ad; color:white; border:none; border-radius:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px; box-shadow:0 4px 14px rgba(142,68,173,0.3);">
                                ↩️ Registrar Devolución a Proveedor
                            </button>
                        </div>
                    </div>

                    <!-- Tarjetas KPI -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:25px;">
                        <div style="background:#fff; border:1px solid #f3e8ff; border-left:5px solid #9333ea; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">💸 Total Devuelto (USD)</div>
                            <div id="devProvStatTotalUSD" style="font-size:1.6rem; font-weight:800; color:#7e22ce; margin-top:4px;">$0.00</div>
                        </div>
                        <div style="background:#fff; border:1px solid #fee2e2; border-left:5px solid #ef4444; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">💵 Efectivo</div>
                            <div id="devProvStatEfectivoUSD" style="font-size:1.6rem; font-weight:800; color:#dc2626; margin-top:4px;">$0.00</div>
                        </div>
                        <div style="background:#fff; border:1px solid #dbeafe; border-left:5px solid #2563eb; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">🎫 Nota de Crédito</div>
                            <div id="devProvStatNCUSD" style="font-size:1.6rem; font-weight:800; color:#2563eb; margin-top:4px;">$0.00</div>
                        </div>
                        <div style="background:#fff; border:1px solid #e0e7ff; border-left:5px solid #6366f1; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">📋 Activas</div>
                            <div id="devProvStatActivas" style="font-size:1.6rem; font-weight:800; color:#4f46e5; margin-top:4px;">0</div>
                        </div>
                    </div>

                    <!-- Tabla -->
                    <div class="table-responsive">
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Compra</th>
                                    <th>Proveedor</th>
                                    <th>Mercancía Devuelta</th>
                                    <th>Tipo</th>
                                    <th>Monto (USD)</th>
                                    <th>Método</th>
                                    <th>Motivo</th>
                                    <th>Registrado Por</th>
                                    <th style="text-align:center;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaDevolucionesProveedorBody">
                                <tr><td colspan="10" style="text-align:center; padding:30px; color:#999;">Cargando registro de devoluciones a proveedores...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Registrar Compra -->
        <div id="modalCompra" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:flex-start; padding-top:30px;">
            <div class="admin-card" style="width:100%; max-width:760px; max-height:88vh; overflow-y:auto; background: var(--bg-card, #fff); padding: 28px; border-radius: 14px; position:relative; box-shadow:0 8px 40px rgba(0,0,0,0.18);">
                <button type="button" onclick="cerrarModalCompra()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
                <h3 style="margin-top:0; margin-bottom:20px; font-size:1.2rem;">🧾 Registrar Compra al Proveedor</h3>

                <!-- Datos generales -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div style="grid-column:1/-1;">
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Nombre del Proveedor <span style="color:red;">*</span></label>
                        <select id="selectProveedor" onchange="actualizarMarcasDelDetalleCompra()" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:0.97rem;"></select>
                        <div id="infoMarcasProveedor" style="margin-top:6px; font-size:0.82rem; color:#666; display:none;"></div>
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Código / N° de Factura</label>
                        <input id="inputFactura" placeholder="Ej: FAC-0001" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;" />
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Fecha y Hora de la Compra <span style="color:red;">*</span></label>
                        <input id="inputFechaCompra" type="datetime-local" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;" />
                    </div>
                </div>

                <!-- Tabla de productos -->
                <div style="border:1px solid #e0e0e0; border-radius:10px; padding:16px; background:#f9f9f9; margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <strong style="font-size:1rem;">📦 Productos Comprados</strong>
                        <button type="button" class="btn-primary" style="padding:7px 14px; font-size:0.88rem;" onclick="agregarFilaDetalleCompra()">+ Agregar Producto</button>
                    </div>
                    <div class="table-responsive">
                        <table class="inventory-table" id="tablaCompraDetalles" style="min-width:500px;">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Marca</th>
                                    <th>Cantidad</th>
                                    <th>Precio de Compra (USD)</th>
                                    <th>Subtotal</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody id="compraDetallesBody">
                                <tr id="compraDetallesVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Resumen total -->
                    <div style="display:flex; justify-content:flex-end; align-items:center; gap:16px; flex-wrap:wrap; margin-top:12px;">
                        <label style="display:flex; align-items:center; gap:6px; font-size:0.88rem; font-weight:600; color:#555;">
                            IGTF (USD, opcional):
                            <input type="number" id="inputCompraIgtf" step="0.01" min="0" value="0.00" title="Solo si la compra se pagó en divisas" style="width:110px; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </label>
                        <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:10px 20px; font-size:0.97rem;">
                            Total estimado: <strong id="compraTotalDisplay" style="color:#2563eb;">$0.00 USD</strong>
                        </div>
                    </div>
                </div>

                <!-- Botones -->
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:8px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalCompra()" style="padding:10px 22px;">Cancelar</button>
                    <button type="button" class="btn-primary" onclick="enviarCompra()" id="btnRegistrarCompra" style="padding:10px 22px;">✅ Registrar Compra</button>
                </div>
            </div>
        </div>

        <!-- Seccion Inventario -->
        <div id="admin-seccion-inventario" class="admin-panel-content hidden">
            <div class="admin-card" id="inventarioVistaLista">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <h2 style="margin:0;">📦 Matriz e Inventario de Productos</h2>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte de Inventario de Productos', 'inventoryTableBody')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                        <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Inventario', 'inventoryTableBody')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        <button type="button" class="btn-primary" onclick="abrirKardexDesdeInventario()" style="background:#6366f1; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📒 Kardex</button>
                        <button type="button" class="btn-primary" onclick="abrirPerdidasDesdeInventario()" style="background:#dc2626; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">⚠️ Pérdidas</button>
                        <button type="button" class="btn-primary" onclick="abrirTasaDesdeInventario()" style="background:#d97706; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📈 Tasa del Día</button>
                    </div>
                </div>

                <!-- Barra de Búsqueda y Filtros Avanzados de Matriz -->
                <div style="background:var(--bg-card, #f8fafc); border:1px solid var(--border-color, #e2e8f0); padding:16px; border-radius:10px; margin-bottom:20px;">
                    <div style="font-weight:700; font-size:0.95rem; margin-bottom:10px; color:var(--text-dark, #1e293b); display:flex; align-items:center; gap:6px;">
                        🎛️ Filtros Avanzados de la Matriz de Inventario
                    </div>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:12px;">
                        <div>
                            <label style="font-size:0.8rem; font-weight:600; display:block; margin-bottom:4px;">🔍 Búsqueda General</label>
                            <input type="text" id="filtroTextoMatriz" onkeyup="filtrarMatrizInventario()" placeholder="Nombre, ID o Código..." style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem;">
                        </div>
                        <div>
                            <label style="font-size:0.8rem; font-weight:600; display:block; margin-bottom:4px;">📂 Categoría</label>
                            <select id="filtroCategoriaMatriz" onchange="filtrarMatrizInventario()" style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem; background:#fff;">
                                <option value="">Todas las Categorías</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.8rem; font-weight:600; display:block; margin-bottom:4px;">📊 Estado de Stock</label>
                            <select id="filtroStockMatriz" onchange="filtrarMatrizInventario()" style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem; background:#fff;">
                                <option value="">Todos los Estados</option>
                                <option value="disponible">🟢 En Stock (> 5)</option>
                                <option value="bajo">⚠️ Stock Bajo (<= 5)</option>
                                <option value="agotado">🔴 Agotado (0)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.8rem; font-weight:600; display:block; margin-bottom:4px;">📈 Margen de Ganancia</label>
                            <select id="filtroMargenMatriz" onchange="filtrarMatrizInventario()" style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem; background:#fff;">
                                <option value="">Todos los Márgenes</option>
                                <option value="alto">🟢 Alto (≥ 30%)</option>
                                <option value="medio">🟡 Normal (15% - 29%)</option>
                                <option value="bajo">🔴 Bajo (< 15%)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.8rem; font-weight:600; display:block; margin-bottom:4px;">💵 Precio ($ USD)</label>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="number" id="filtroPrecioMinMatriz" oninput="filtrarMatrizInventario()" placeholder="Min" style="width:50%; padding:8px 6px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem;">
                                <span style="font-size:0.8rem;">-</span>
                                <input type="number" id="filtroPrecioMaxMatriz" oninput="filtrarMatrizInventario()" placeholder="Max" style="width:50%; padding:8px 6px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem;">
                            </div>
                        </div>
                        <div>
                            <label style="font-size:0.8rem; font-weight:600; display:block; margin-bottom:4px;">🏷️ Proveedor</label>
                            <select id="filtroProveedorMatriz" onchange="filtrarMatrizInventario()" style="width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem; background:#fff;">
                                <option value="">Todos los Proveedores</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Código</th>
                                <th>Producto / Categoría</th>
                                <th>Precio Venta ($)</th>
                                <th>Costo ($)</th>
                                <th>Margen Bruto</th>
                                <th>Stock Actual</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryTableBody">
                            <!-- Llenado por JS -->
                        </tbody>
                    </table>
                </div>
                <?php renderPaginacionBar('inventoryTableBody'); ?>
            </div>

            <!-- Sub-vista: Kardex de Inventario (dentro del módulo de Inventario) -->
            <div id="inventarioVistaKardex" class="hidden">
                <div class="admin-card" style="margin-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom: 20px;">
                        <div>
                            <h2 style="margin:0; font-size:1.5rem; display:flex; align-items:center; gap:8px;">
                                📒 Kardex de Inventario
                            </h2>
                            <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                                Historial de todas las entradas y salidas de cada producto con su saldo acumulado. Sirve para saber <em>de dónde salió cada unidad</em>, auditar el inventario y controlar el costo real.
                            </p>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" onclick="volverAInventarioLista()" style="padding:10px 16px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px;">
                                ← Volver a Inventario
                            </button>
                            <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte Kardex de Inventario', 'kardexTableBody')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                            <button type="button" class="btn-primary" onclick="exportarTablaCSV('Kardex_Inventario', 'kardexTableBody')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                            <button type="button" class="btn-primary" onclick="abrirModalAjusteKardex()" style="background:#6366f1; color:#fff; border:none; padding:9px 16px; border-radius:8px; font-weight:700; cursor:pointer;">⚖️ Ajuste de Inventario</button>
                        </div>
                    </div>

                    <!-- Tarjetas KPI -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap:15px; margin-bottom:25px;">
                        <div style="background:#fff; border:1px solid #dcfce7; border-left:5px solid #16a34a; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">📥 Entradas (unidades)</div>
                            <div id="kardexStatEntradas" style="font-size:1.6rem; font-weight:800; color:#16a34a; margin-top:4px;">0</div>
                        </div>
                        <div style="background:#fff; border:1px solid #fee2e2; border-left:5px solid #ef4444; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">📤 Salidas (unidades)</div>
                            <div id="kardexStatSalidas" style="font-size:1.6rem; font-weight:800; color:#dc2626; margin-top:4px;">0</div>
                        </div>
                        <div style="background:#fff; border:1px solid #e0e7ff; border-left:5px solid #6366f1; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">📋 Movimientos</div>
                            <div id="kardexStatMovimientos" style="font-size:1.6rem; font-weight:800; color:#4f46e5; margin-top:4px;">0</div>
                        </div>
                        <div style="background:#fff; border:1px solid #e5e7eb; border-left:5px solid #0ea5e9; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">💲 Valor del Inventario</div>
                            <div id="kardexStatValorInventario" style="font-size:1.6rem; font-weight:800; color:#0284c7; margin-top:4px;">$0.00</div>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:18px; background:#f9fafb; padding:14px; border-radius:10px; border:1px solid #f3f4f6;">
                        <div style="flex:2; min-width:230px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:4px;">🔍 Buscar</label>
                            <input type="text" id="kardexBusqueda" oninput="cargarKardexDebounced()" placeholder="Producto, factura, nº de documento u observación..." style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;">
                        </div>
                        <div style="flex:2; min-width:210px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:4px;">📦 Producto</label>
                            <select id="kardexProducto" onchange="cargarKardex()" style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; background:#fff;">
                                <option value="">Todos los productos</option>
                            </select>
                        </div>
                        <div style="min-width:160px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:4px;">Tipo</label>
                            <select id="kardexTipo" onchange="cargarKardex()" style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; background:#fff;">
                                <option value="">Todos</option>
                                <option value="ENTRADA">📥 Solo entradas</option>
                                <option value="SALIDA">📤 Solo salidas</option>
                            </select>
                        </div>
                        <div style="min-width:200px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:4px;">Concepto</label>
                            <select id="kardexConcepto" onchange="cargarKardex()" style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; background:#fff;">
                                <option value="">Todos los conceptos</option>
                                <option value="COMPRA">🧾 Compra</option>
                                <option value="VENTA">💰 Venta</option>
                                <option value="ANULACION_VENTA">↩️ Anulación de venta</option>
                                <option value="DEVOLUCION_VENTA">↩️ Devolución de venta</option>
                                <option value="DEVOLUCION_PROVEEDOR">📤 Devolución a proveedor</option>
                                <option value="ANULACION_DEVOLUCION_PROVEEDOR">↩️ Anulación dev. proveedor</option>
                                <option value="MERMA">⚠️ Pérdida</option>
                                <option value="ANULACION_MERMA">↩️ Anulación de pérdida</option>
                                <option value="AJUSTE_INVENTARIO">⚖️ Ajuste de inventario</option>
                                <option value="ABASTECIMIENTO">📥 Abastecimiento</option>
                            </select>
                        </div>
                        <div style="min-width:145px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:4px;">Desde</label>
                            <input type="date" id="kardexDesde" onchange="cargarKardex()" style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; background:#fff;">
                        </div>
                        <div style="min-width:145px;">
                            <label style="display:block; font-size:0.78rem; font-weight:700; color:#475569; margin-bottom:4px;">Hasta</label>
                            <input type="date" id="kardexHasta" onchange="cargarKardex()" style="width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; background:#fff;">
                        </div>
                        <button type="button" onclick="limpiarFiltrosKardex()" style="padding:9px 14px; border:1px solid #d1d5db; background:#fff; border-radius:8px; font-size:0.85rem; font-weight:700; color:#475569; cursor:pointer;">🧹 Limpiar</button>
                    </div>

                    <div class="table-responsive">
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Producto</th>
                                    <th>Concepto</th>
                                    <th>Documento</th>
                                    <th style="text-align:center;">Entrada</th>
                                    <th style="text-align:center;">Salida</th>
                                    <th style="text-align:center;">Stock Ant.</th>
                                    <th style="text-align:center;">Stock Nuevo</th>
                                    <th style="text-align:right;">Costo Unit.</th>
                                    <th style="text-align:right;">Valor Total</th>
                                    <th>Observaciones</th>
                                </tr>
                            </thead>
                            <tbody id="kardexTableBody">
                                <tr><td colspan="11" style="text-align:center; padding:30px; color:#999;">Cargando el Kardex de inventario...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p id="kardexAvisoLimite" style="display:none; margin:12px 0 0 0; font-size:0.82rem; color:#b45309; background:#fffbeb; border:1px solid #fde68a; padding:10px 14px; border-radius:8px;"></p>
                </div>
            </div>

            <!-- Sub-vista: Pérdidas (Salidas No Operativas) dentro del módulo de Inventario -->
            <div id="inventarioVistaPerdidas" class="hidden">
            <div class="admin-card" style="margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom: 20px;">
                    <div>
                        <h2 style="margin:0; font-size:1.5rem; display:flex; align-items:center; gap:8px;">
                            ⚠️ Pérdidas (Salidas No Operativas)
                        </h2>
                        <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                            Registra y gestiona las salidas de productos dañados, robados, extraviados, vencidos o de uso interno para ajustar el stock real sin alterar las ventas.
                        </p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" onclick="volverAInventarioLista()" style="padding:10px 16px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px;">
                            ← Volver a Inventario
                        </button>
                        <button type="button" class="btn-primary" onclick="abrirModalRegistrarMerma()" style="padding:12px 20px; background:#e74c3c; color:white; border:none; border-radius:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px; box-shadow:0 4px 14px rgba(231,76,60,0.3);">
                            ➕ Registrar Pérdida
                        </button>
                    </div>
                </div>

                <!-- Tarjetas KPI de Resumen -->
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:25px;">
                    <div style="background:#fff; border:1px solid #fee2e2; border-left:5px solid #ef4444; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">💸 Pérdidas Totales (USD)</div>
                        <div id="mermaStatTotalUSD" style="font-size:1.6rem; font-weight:800; color:#dc2626; margin-top:4px;">$0.00</div>
                    </div>
                    <div style="background:#fff; border:1px solid #fef3c7; border-left:5px solid #f59e0b; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">📦 Unidades Perdidas</div>
                        <div id="mermaStatTotalUnidades" style="font-size:1.6rem; font-weight:800; color:#d97706; margin-top:4px;">0 unids</div>
                    </div>
                    <div style="background:#fff; border:1px solid #e0e7ff; border-left:5px solid #6366f1; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">📋 Total Registros</div>
                        <div id="mermaStatTotalRegistros" style="font-size:1.6rem; font-weight:800; color:#4f46e5; margin-top:4px;">0</div>
                    </div>
                    <div style="background:#fff; border:1px solid #e5e7eb; border-left:5px solid #4b5563; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.85rem; color:#6b7280; font-weight:600; text-transform:uppercase;">💔 Principal Motivo</div>
                        <div id="mermaStatPrincipalMotivo" style="font-size:1.2rem; font-weight:700; color:#1f2937; margin-top:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">-</div>
                    </div>
                </div>

                <!-- Barra de Filtros -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px; background:#f9fafb; padding:14px; border-radius:10px; border:1px solid #f3f4f6;">
                    <div style="flex:1; min-width:250px;">
                        <input type="text" id="busquedaMerma" onkeyup="filtrarTablaMermas()" placeholder="🔍 Buscar por producto, SKU, motivo o usuario..." style="width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem;">
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <select id="filtroTipoMerma" onchange="filtrarTablaMermas()" style="padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff; font-weight:600; cursor:pointer;">
                            <option value="">Todos los Tipos de Salida</option>
                            <option value="DAÑADO">📦 Producto Dañado</option>
                            <option value="ROBADO">🥷 Robado / Sustraído</option>
                            <option value="EXTRAVIADO">❓ Extraviado / Perdido</option>
                            <option value="VENCIDO">⏳ Vencido / Expirado</option>
                            <option value="USO_INTERNO">🏢 Uso Interno / Muestra</option>
                        </select>
                        <label style="display:flex; align-items:center; gap:6px; font-size:0.85rem; font-weight:600; color:#475569; cursor:pointer; user-select:none; padding:10px 12px; background:#fff; border:1px solid #d1d5db; border-radius:8px;">
                            <input type="checkbox" id="filtroMostrarAnuladas" onchange="filtrarTablaMermas()" style="cursor:pointer;">
                            Mostrar anuladas
                        </label>
                    </div>
                </div>

                <div id="mermaAnuladasResumen" style="display:none; margin-bottom:15px; background:#f8fafc; border:1px dashed #cbd5e1; padding:12px 16px; border-radius:10px; font-size:0.85rem; color:#475569;">
                </div>

                <!-- Tabla de Historial -->
                <div class="table-responsive">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Producto</th>
                                <th>Tipo de Salida</th>
                                <th>Cant.</th>
                                <th>Costo Unit.</th>
                                <th>Pérdida Total</th>
                                <th>Motivo / Observación</th>
                                <th>Registrado Por</th>
                                <th style="text-align:center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaMermasBody">
                            <tr><td colspan="9" style="text-align:center; padding:30px; color:#999;">Cargando registro de pérdidas...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>

            <!-- Sub-vista: Tasa del Día dentro del módulo de Inventario -->
            <div id="inventarioVistaTasa" class="hidden">
                <div class="admin-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                        <div>
                            <h2 style="margin:0;">📈 Tasa del Dólar</h2>
                            <p class="admin-help-text" style="margin:4px 0 0 0;">Actualiza el valor de conversión para los precios en Bolívares.</p>
                        </div>
                        <button type="button" onclick="volverAInventarioLista()" style="padding:10px 16px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px;">
                            ← Volver a Inventario
                        </button>
                    </div>
                    <div class="tasa-control">
                        <input type="text" id="inputTasa" inputmode="decimal" pattern="[0-9.]*" oninput="this.value = this.value.replace(/[^0-9.]/g, '')" placeholder="Ej: 52.50">
                        <button onclick="actualizarTasaManual()" class="btn-primary">Actualizar Tasa</button>
                    </div>
                    <p id="tasaActualMsg" class="tasa-status-badge"></p>
                </div>
            </div>
        </div>

        <!-- Seccion Nuevo/Editar Producto -->
        <div id="admin-seccion-nuevo" class="admin-panel-content hidden">
            <div class="admin-card">
                <div class="admin-panel-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 id="admin-form-title" style="margin:0;">📦 Publicar nuevo producto</h2>
                    <button type="button" class="btn-primary" style="padding:10px 20px;" onclick="abrirModalHistorialProductos()">📅 Ver Historial de Ingreso</button>
                </div>
                <form id="adminForm" onsubmit="return crearProductoAdmin(event)" class="admin-grid-form">
                    <div class="form-group">
                        <label for="proveedorProd">Proveedor</label>
                        <select id="proveedorProd" required onchange="onProveedorProductoChanged()"
                            style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;">
                            <option value="">Cargando proveedores...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nombreProd">Producto</label>
                        <select id="nombreProd" required onchange="onProductoNuevoSelected()"
                            style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;">
                            <option value="">Elige primero un proveedor</option>
                        </select>
                        <div id="infoProductosProveedor" style="margin-top:6px; font-size:0.82rem; color:#555;"></div>
                        <datalist id="productosList"></datalist>
                    </div>
                    <div class="form-group"><label for="categoriaProd">Categoría</label><input id="categoriaProd"
                            required placeholder="Ej: Computación" /></div>
                    <div class="form-group"><label for="subcategoriaProd">Sub-categoría</label><input
                            id="subcategoriaProd" placeholder="Ej: Laptops" /></div>
                    <div class="form-group">
                        <label for="marcaProd">Marca</label>
                        <select id="marcaProd" onchange="onMarcaProductoSelected()"
                            style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;">
                            <option value="">Elige primero un producto</option>
                        </select>
                        <div id="infoMarcasProducto" style="margin-top:6px; font-size:0.82rem; color:#555;"></div>
                    </div>
                    <div class="form-group"><label for="precioCompraProd">Precio Compra / Costo neto (sin IVA) (USD)</label><input id="precioCompraProd"
                            type="text" inputmode="decimal" pattern="[0-9.]*" oninput="this.value = this.value.replace(/[^0-9.]/g, ''); calcularMargenGananciaForm();" required placeholder="0.00" /></div>
                    <div class="form-group"><label for="margenGananciaPct">Margen Deseado (%)</label><input id="margenGananciaPct"
                            type="text" inputmode="decimal" pattern="[0-9.]*" oninput="this.value = this.value.replace(/[^0-9.]/g, ''); calcularPrecioVentaPorMargen();" placeholder="Ej: 30" /></div>
                    <div class="form-group"><label for="precioProd">Precio Venta P.V.P. (IVA incluido) (USD)</label><input id="precioProd"
                            type="text" inputmode="decimal" pattern="[0-9.]*" oninput="this.value = this.value.replace(/[^0-9.]/g, ''); calcularMargenGananciaForm();" required placeholder="0.00" /></div>
                    <div class="form-group">
                        <label for="ivaProd">Alícuota de IVA</label>
                        <select id="ivaProd" onchange="calcularMargenGananciaForm()"
                            style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;">
                            <option value="16">General (16%)</option>
                            <option value="31">Adicional (31% = 16% + 15%)</option>
                            <option value="0">Exento / Exonerado (0%)</option>
                        </select>
                        <div style="margin-top:6px; font-size:0.78rem; color:#666;">El P.V.P. ya incluye el IVA; el sistema desglosa base e IVA al vender.</div>
                    </div>
                    <div class="form-group full-width" style="background:rgba(67, 97, 238, 0.05); padding:12px; border-radius:8px; border:1px solid rgba(67, 97, 238, 0.2);">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <span style="font-size:0.9rem; font-weight:600; color:#333;">💡 Análisis de Rentabilidad por Unidad:</span>
                            <span id="labelGananciaCalculadaUSD" style="font-size:0.95rem; font-weight:700; color:#2ecc71;">Ganancia: $0.00 / und (0% Margen Bruto | 0% Markup)</span>
                        </div>
                    </div>
                    <div class="form-group"><label for="stockProd">Stock Inicial</label><input id="stockProd"
                            type="text" inputmode="numeric" pattern="[0-9]*" value="0" readonly style="background-color: #e5e7eb; cursor: not-allowed;" title="El stock no se puede cambiar directamente desde esta sección" required placeholder="0" /></div>
                    <div class="form-group"><label for="stockMinProd">Stock Mínimo (Alerta)</label><input
                            id="stockMinProd" type="text" inputmode="numeric" pattern="[0-9]*" oninput="this.value = this.value.replace(/[^0-9]/g, '')" value="0" required /></div>
                    <div class="form-group full-width">
                        <label for="imagenProd">Imágenes del Producto</label>
                        <div style="font-size: 0.8rem; color: #666; margin-bottom: 6px;">Puedes agregar varias imágenes; la primera será la imagen principal.</div>
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <input id="imagenProd" type="text" required placeholder="Seleccionar una o varias imágenes" style="flex: 1; min-width: 200px;" oninput="actualizarPreviewImagenPrincipal()" />
                            <label for="imagenProdFile" class="btn-secondary" style="padding: 10px 16px; border: 1px dashed #4a90e2; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: #f0f7ff; color: #1a56db; font-weight: 600; transition: all 0.2s;">
                                🖼️ Seleccionar imágenes
                            </label>
                            <input type="file" id="imagenProdFile" accept="image/*" multiple style="display: none;" onchange="subirImagenProducto(this, 'imagenProd', 'previewMainImg')" />
                        </div>
                        <div id="previewMainImg" style="margin-top: 10px; display: none; flex-wrap: wrap; gap: 10px; background: #fafafa; padding: 10px; border-radius: 8px; border: 1px solid #eee;"></div>
                        <div id="imgPreviewMainStatus" style="margin-top: 6px; display: none; font-size: 0.85rem; color: #2e7d32; font-weight: 600;"></div>
                    </div>
                    <div class="form-group full-width"><label for="descProd">Descripción Corta</label><input
                            id="descProd" required placeholder="Breve descripción del producto..." /></div>
                    <div class="form-group full-width"><label for="fichaProd">Ficha Técnica Detallada</label><textarea
                            id="fichaProd" rows="4" placeholder="Especificaciones, dimensiones, etc."></textarea></div>

                    <div class="form-actions">
                        <button type="button" onclick="mostrarSeccionAdmin('inventario')" class="btn-secondary" style="margin-right: 10px; padding: 16px 30px; border-radius: 15px; border: 1px solid #ddd; background: white; cursor: pointer; font-weight: 600;">Cancelar</button>
                        <button type="submit" class="btn-primary">Confirmar Publicación</button>
                    </div>
                </form>
            </div>
        </div>

        

        <!-- Modal Agregar Venta Presencial -->
        <div id="modalVentaPresencial" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:flex-start; padding-top:30px;">
            <div class="admin-card" style="width:100%; max-width:780px; max-height:88vh; overflow-y:auto; background: var(--bg-card, #fff); padding: 28px; border-radius: 14px; position:relative; box-shadow:0 8px 40px rgba(0,0,0,0.18);">
                <button type="button" onclick="cerrarModalVentaPresencial()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
                <h3 style="margin-top:0; margin-bottom:20px; font-size:1.2rem;">💰 Registrar Venta Presencial (Tienda Física)</h3>

                <!-- Datos generales de la venta -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Cliente Registrado (Opcional)</label>
                        <select id="selectVentaCliente" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:0.97rem;" onchange="onClienteVentaSelected()"></select>
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Nombre del Cliente / Mostrador</label>
                        <input id="inputVentaClienteNombre" placeholder="Ej: Cliente Presencial / Juan Pérez" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;" value="Cliente Presencial" />
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Fecha y Hora de la Venta <span style="color:red;">*</span></label>
                        <input id="inputFechaVenta" type="datetime-local" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box;" />
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Método de Pago <span style="color:red;">*</span></label>
                        <select id="selectVentaMetodoPago" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:0.97rem;">
                            <option value="Efectivo USD">💵 Efectivo USD</option>
                            <option value="Efectivo Bs">💵 Efectivo Bs</option>
                            <option value="Pago Móvil">📱 Pago Móvil</option>
                            <option value="Transferencia">🏦 Transferencia Bancaria</option>
                            <option value="Zelle">🌐 Zelle</option>
                            <option value="Biopago">⚡ Biopago</option>
                            <option value="Punto de Venta">💳 Punto de Venta (Tarjeta)</option>
                        </select>
                    </div>
                </div>

                <!-- Tabla de productos a vender -->
                <div style="border:1px solid #e0e0e0; border-radius:10px; padding:16px; background:#f9f9f9; margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <strong style="font-size:1rem;">📦 Productos a Vender</strong>
                        <button type="button" class="btn-primary" style="padding:7px 14px; font-size:0.88rem;" onclick="agregarFilaDetalleVenta()">+ Agregar Producto</button>
                    </div>
                    <div class="table-responsive">
                        <table class="inventory-table" id="tablaVentaDetalles" style="min-width:550px;">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Stock Disp.</th>
                                    <th>Cantidad</th>
                                    <th>Precio (USD)</th>
                                    <th>Subtotal</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody id="ventaDetallesBody">
                                <tr id="ventaDetallesVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Resumen total -->
                    <div style="display:flex; justify-content:flex-end; gap:16px; margin-top:12px; align-items:center; flex-wrap:wrap;">
                        <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:10px 16px; font-size:0.92rem;">
                            Tasa del Día: <strong id="ventaTasaDisplay" style="color:#64748b;">Bs 0.00</strong>
                        </div>
                        <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:10px 20px; font-size:0.97rem;">
                            Total USD: <strong id="ventaTotalUsdDisplay" style="color:#2563eb;">$0.00 USD</strong>
                        </div>
                        <label style="display:flex; align-items:center; gap:6px; font-size:0.85rem; font-weight:600; color:#555; background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:8px 12px;">
                            IGTF (USD):
                            <input type="number" id="inputVentaIgtf" step="0.01" min="0" value="0.00" title="Solo si el cobro fue en divisas" style="width:100px; padding:6px; border:1px solid #ddd; border-radius:6px;">
                        </label>
                        <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:10px 20px; font-size:0.97rem;">
                            Total Bs: <strong id="ventaTotalBsDisplay" style="color:#16a34a;">Bs 0.00</strong>
                        </div>
                    </div>
                </div>

                <!-- Opcion de Sistema de Apartado -->
                <div style="background:#eef2ff; border:1px solid #c7d2fe; border-radius:10px; padding:14px; margin-bottom:16px;">
                    <label style="display:flex; align-items:center; gap:8px; font-weight:700; color:#3730a3; cursor:pointer;">
                        <input type="checkbox" id="checkVentaEsApartado" onchange="toggleVentaApartadoPresencial(this.checked)" style="width:18px; height:18px; cursor:pointer;" />
                        🏷️ Registrar como Sistema de Apartado (Reserva de mercancía con abono inicial)
                    </label>
                    <div id="seccionCamposApartadoPresencial" style="display:none; margin-top:12px; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                        <div>
                            <label style="display:block; font-size:0.83rem; font-weight:700; color:#3730a3; margin-bottom:4px;">Abono Inicial (USD)</label>
                            <input type="number" id="inputVentaAbonoInicialUsd" step="0.01" min="0" placeholder="Mínimo 30%" style="width:100%; padding:8px 10px; border:1px solid #c7d2fe; border-radius:6px; box-sizing:border-box;" />
                        </div>
                        <div>
                            <label style="display:block; font-size:0.83rem; font-weight:700; color:#3730a3; margin-bottom:4px;">Días Límite</label>
                            <input type="number" id="inputVentaApartadoDias" value="<?php echo $apartadoDiasCfg; ?>" min="1" max="90" style="width:100%; padding:8px 10px; border:1px solid #c7d2fe; border-radius:6px; box-sizing:border-box;" />
                        </div>
                        <div>
                            <label style="display:block; font-size:0.83rem; font-weight:700; color:#3730a3; margin-bottom:4px;">Ref. Pago Inicial</label>
                            <input type="text" id="inputVentaApartadoRef" placeholder="Ej: Efectivo / #Ref" style="width:100%; padding:8px 10px; border:1px solid #c7d2fe; border-radius:6px; box-sizing:border-box;" />
                        </div>
                        <div style="grid-column:1 / -1; background:#fff; border:1px dashed #c7d2fe; border-radius:8px; padding:8px 10px; font-size:0.8rem; color:#3730a3;">
                            📐 Plan configurado: <strong id="ventaApartadoPlanCuotas"><?php echo $apartadoCuotasCfg; ?> cuotas</strong> ·
                            Plazo <strong id="ventaApartadoPlanDias"><?php echo $apartadoDiasCfg; ?> días</strong>
                            (se cambia en <em>Mi Cuenta → Configuración de Apartados</em>).
                        </div>
                    </div>
                </div>

                <!-- Botones -->
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:8px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalVentaPresencial()" style="padding:10px 22px;">Cancelar</button>
                    <button type="button" class="btn-primary" onclick="enviarVentaPresencial()" id="btnRegistrarVenta" style="padding:10px 22px;">✅ Registrar Venta</button>
                </div>
            </div>
        </div>

        <!-- Seccion Historial de Clientes (modulo unificado Ventas + Apartados + Devoluciones) -->
        <div id="admin-seccion-historial" class="admin-panel-content hidden">
            <div class="admin-card" style="margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom: 20px;">
                    <div>
                        <h2 style="margin:0; font-size:1.5rem; display:flex; align-items:center; gap:8px;">
                            🧾 Historial de Clientes
                        </h2>
                        <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                            Elige un cliente para ver todo su historial: compras, apartados y devoluciones en un solo lugar.
                        </p>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="button" id="btnHistorialVolver" onclick="cerrarDetalleHistorial()"
                                style="display:none; padding:10px 16px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:10px; font-weight:600; cursor:pointer;">
                            ← Volver a la lista
                        </button>
                        <button type="button" onclick="cargarIndiceHistorial()" style="padding:10px 16px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:10px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                            🔄 Actualizar
                        </button>
                    </div>
                </div>

                <!-- ===== VISTA 1: INDICE DE CLIENTES ===== -->
                <div id="historialVistaIndice">
                    <div style="margin-bottom:18px; background:#f9fafb; padding:14px; border-radius:10px; border:1px solid #f3f4f6;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <div style="flex:1; min-width:250px;">
                                <input type="text" id="historialBuscarCliente" oninput="filtrarIndiceHistorial()" placeholder="🔍 Buscar cliente por nombre, usuario, cédula o teléfono..." style="width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem;">
                            </div>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                                <select id="historialFiltroActividad" onchange="filtrarIndiceHistorial()" style="padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff; font-weight:600; cursor:pointer;">
                                    <option value="">Todos los Clientes</option>
                                    <option value="con_compras">🛒 Con compras</option>
                                    <option value="con_apartados">🏷️ Con apartados activos</option>
                                    <option value="con_devoluciones">↩️ Con devoluciones</option>
                                    <option value="sin_movimiento">⚪ Sin movimientos</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; padding-top:12px; border-top:1px dashed #e5e7eb;">
                            <span style="font-size:0.82rem; font-weight:700; color:#555;">🕐 Por última actividad:</span>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">📅 Fecha desde <input type="date" id="historialIndiceFechaDesde" onchange="filtrarIndiceHistorial()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">Fecha hasta <input type="date" id="historialIndiceFechaHasta" onchange="filtrarIndiceHistorial()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">🕐 Hora desde <input type="time" id="historialIndiceHoraDesde" onchange="filtrarIndiceHistorial()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">Hora hasta <input type="time" id="historialIndiceHoraHasta" onchange="filtrarIndiceHistorial()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <button type="button" onclick="limpiarFiltrosHistorialIndice()" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; background:#fff; cursor:pointer; font-size:0.85rem; font-weight:600;">🧹 Limpiar filtros</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Usuario</th>
                                    <th>Cédula</th>
                                    <th>Contacto</th>
                                    <th style="text-align:center;">Compras</th>
                                    <th>Total Comprado (USD)</th>
                                    <th style="text-align:center;">Apartados</th>
                                    <th style="text-align:center;">Devoluciones</th>
                                    <th>Última Actividad</th>
                                    <th style="text-align:center;">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="historialIndiceBody">
                                <tr><td colspan="10" style="text-align:center; padding:30px; color:#999;">Cargando clientes...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php renderPaginacionBar('historialIndiceBody'); ?>
                </div>

                <!-- ===== VISTA 2: DETALLE DEL CLIENTE ===== -->
                <div id="historialVistaDetalle" style="display:none;">

                    <!-- Acciones rapidas sobre el cliente seleccionado -->
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:18px; background:#f0fdf4; border:1px solid #bbf7d0; padding:12px 16px; border-radius:10px;">
                        <span style="font-weight:700; color:#166534; font-size:0.9rem;">⚡ Acciones para este cliente</span>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" onclick="abrirVentaPresencialParaCliente()"
                                    style="padding:9px 16px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 12px rgba(22,163,74,0.25);">
                                ➕ Venta Presencial
                            </button>
                            <button type="button" onclick="abrirDevolucionParaCliente()"
                                    style="padding:9px 16px; background:#b91c1c; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 12px rgba(185,28,28,0.25);">
                                ↩️ Registrar Devolución
                            </button>
                            <button type="button" onclick="imprimirHistorialCliente()"
                                    style="padding:9px 16px; background:#4b5563; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
                                📄 PDF / Imprimir
                            </button>
                            <button type="button" onclick="exportarHistorialClienteCSV()"
                                    style="padding:9px 16px; background:#1d4ed8; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
                                📊 Exportar Excel
                            </button>
                        </div>
                    </div>

                    <!-- Ficha del cliente -->
                    <div id="historialFichaCliente" style="background:linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); border:1px solid #c7d2fe; border-radius:14px; padding:20px; margin-bottom:20px;">
                    </div>

                    <!-- KPIs del cliente -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:22px;">
                        <div style="background:#fff; border-left:5px solid #2563eb; border:1px solid #dbeafe; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.82rem; color:#6b7280; font-weight:700; text-transform:uppercase;">🛒 Total Comprado</div>
                            <div id="histCliStatCompras" style="font-size:1.5rem; font-weight:800; color:#1d4ed8; margin-top:4px;">$0.00</div>
                        </div>
                        <div style="background:#fff; border-left:5px solid #16a34a; border:1px solid #dcfce7; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.82rem; color:#6b7280; font-weight:700; text-transform:uppercase;">📈 Ganancia Generada</div>
                            <div id="histCliStatGanancia" style="font-size:1.5rem; font-weight:800; color:#15803d; margin-top:4px;">$0.00</div>
                        </div>
                        <div style="background:#fff; border-left:5px solid #d97706; border:1px solid #fef3c7; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.82rem; color:#6b7280; font-weight:700; text-transform:uppercase;">🏷️ Saldo por Cobrar</div>
                            <div id="histCliStatSaldo" style="font-size:1.5rem; font-weight:800; color:#b45309; margin-top:4px;">$0.00</div>
                            <div style="font-size:0.75rem; color:#9ca3af; margin-top:2px;">De apartados activos</div>
                        </div>
                        <div style="background:#fff; border-left:5px solid #9333ea; border:1px solid #f3e8ff; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="font-size:0.82rem; color:#6b7280; font-weight:700; text-transform:uppercase;">↩️ Reembolsado</div>
                            <div id="histCliStatReembolso" style="font-size:1.5rem; font-weight:800; color:#7e22ce; margin-top:4px;">$0.00</div>
                        </div>
                    </div>

                    <!-- Filtros del detalle -->
                    <div style="margin-bottom:18px; background:#f9fafb; padding:14px; border-radius:10px; border:1px solid #f3f4f6;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <div style="flex:1; min-width:240px;">
                                <input type="text" id="historialBuscarDetalle" oninput="filtrarHistorialDetalle()" placeholder="🔍 Buscar en el historial por producto, número o estado..." style="width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem;">
                            </div>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                                <select id="historialFiltroTipo" onchange="filtrarHistorialDetalle()" style="padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff; font-weight:600; cursor:pointer;">
                                    <option value="todo">📚 Todo el historial</option>
                                    <option value="ventas">🛒 Solo Compras</option>
                                    <option value="apartados">🏷️ Solo Apartados</option>
                                    <option value="devoluciones">↩️ Solo Devoluciones</option>
                                </select>
                                <select id="historialFiltroPeriodo" onchange="filtrarHistorialDetalle()" style="padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff; font-weight:600; cursor:pointer;">
                                    <option value="">Todo el tiempo</option>
                                    <option value="7">Últimos 7 días</option>
                                    <option value="30">Últimos 30 días</option>
                                    <option value="90">Últimos 90 días</option>
                                    <option value="365">Último año</option>
                                </select>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; padding-top:12px; border-top:1px dashed #e5e7eb;">
                            <span style="font-size:0.82rem; font-weight:700; color:#555;">🔎 Por fecha y hora del movimiento:</span>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">📅 Fecha desde <input type="date" id="historialDetalleFechaDesde" onchange="filtrarHistorialDetalle()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">Fecha hasta <input type="date" id="historialDetalleFechaHasta" onchange="filtrarHistorialDetalle()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">🕐 Hora desde <input type="time" id="historialDetalleHoraDesde" onchange="filtrarHistorialDetalle()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.82rem; font-weight:600; color:#555; white-space:nowrap;">Hora hasta <input type="time" id="historialDetalleHoraHasta" onchange="filtrarHistorialDetalle()" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; background:#fff;"></label>
                            <button type="button" onclick="limpiarFiltrosHistorialDetalle()" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; background:#fff; cursor:pointer; font-size:0.85rem; font-weight:600;">🧹 Limpiar filtros</button>
                        </div>
                    </div>

                    <!-- Bloque: COMPRAS -->
                    <div id="historialBloqueVentas" class="historial-bloque" style="margin-bottom: 26px;">
                        <h3 style="display:flex; align-items:center; gap:8px; font-size:1.15rem; margin:0 0 12px 0;">
                            🛒 Compras y Ventas <span id="historialContVentas" class="status-badge" style="background:#e0e7ff; color:#4338ca; padding:4px 11px; border-radius:12px; font-size:0.8rem; font-weight:700;">0</span>
                        </h3>
                        <div class="table-responsive">
                            <table class="inventory-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>#</th>
                                        <th>Productos</th>
                                        <th>Total USD</th>
                                        <th>Total Bs</th>
                                        <th>Método</th>
                                        <th>Ganancia</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="historialVentasBody"></tbody>
                            </table>
                        </div>
                        <?php renderPaginacionBar('historialVentasBody'); ?>
                    </div>

                    <!-- Bloque: APARTADOS -->
                    <div id="historialBloqueApartados" class="historial-bloque" style="margin-bottom: 26px;">
                        <h3 style="display:flex; align-items:center; gap:8px; font-size:1.15rem; margin:0 0 12px 0;">
                            🏷️ Apartados y Reservas <span id="historialContApartados" class="status-badge" style="background:#e0e7ff; color:#4338ca; padding:4px 11px; border-radius:12px; font-size:0.8rem; font-weight:700;">0</span>
                        </h3>
                        <div id="historialApartadosBody"></div>
                        <?php renderPaginacionBar('historialApartadosBody'); ?>
                    </div>

                    <!-- Bloque: DEVOLUCIONES -->
                    <div id="historialBloqueDevoluciones" class="historial-bloque" style="margin-bottom: 26px;">
                        <h3 style="display:flex; align-items:center; gap:8px; font-size:1.15rem; margin:0 0 12px 0;">
                            ↩️ Devoluciones <span id="historialContDevoluciones" class="status-badge" style="background:#e0e7ff; color:#4338ca; padding:4px 11px; border-radius:12px; font-size:0.8rem; font-weight:700;">0</span>
                        </h3>
                        <div class="table-responsive">
                            <table class="inventory-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Venta</th>
                                        <th>Mercancía Devuelta</th>
                                        <th>Tipo</th>
                                        <th>Monto (USD)</th>
                                        <th>Método</th>
                                        <th>Motivo</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="historialDevolucionesBody"></tbody>
                            </table>
                        </div>
                        <?php renderPaginacionBar('historialDevolucionesBody'); ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- Seccion Pedidos -->
        <div id="admin-seccion-pedidos" class="admin-panel-content hidden">
            <div class="admin-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <div>
                        <h2 style="margin:0;">📋 Pedidos al Proveedor</h2>
                        <p class="admin-help-text" style="margin:4px 0 0 0;">Órdenes de reposición a tus proveedores. <span id="pedidosResumenStock" style="font-weight:700; color:#d97706;"></span></p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte de Pedidos', 'pedidosTable')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                        <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Pedidos', 'pedidosTable')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        <button type="button" class="btn-primary" onclick="abrirModalPedido()">➕ Agregar Pedido</button>
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap; align-items:center; background:rgba(0,0,0,0.02); padding:10px; border-radius:8px;">
                    <input type="text" onkeyup="filtrarTablaEnVivo(this, 'pedidosTableBody')" placeholder="🔍 Buscar pedido por proveedor o producto..." style="flex:1; min-width:220px; padding:9px 12px; border:1px solid #ccc; border-radius:6px; font-size:0.9rem;">
                </div>
                <div class="table-responsive">
                    <table class="inventory-table" id="pedidosTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Proveedor</th>
                                <th>Productos</th>
                                <th>Total Estimado</th>
                                <th>Fecha</th>
                                <th style="text-align:center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="pedidosTableBody">
                            <tr><td colspan="6" style="text-align:center; color:#aaa; padding:20px;">Cargando pedidos...</td></tr>
                        </tbody>
                    </table>
                </div>
                <?php renderPaginacionBar('pedidosTableBody'); ?>
            </div>
        </div>

        <!-- Modal Agregar/Editar Pedido -->
        <div id="modalPedido" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:flex-start; padding-top:30px;">
            <div class="admin-card" style="width:100%; max-width:760px; max-height:88vh; overflow-y:auto; background: var(--bg-card, #fff); padding: 28px; border-radius: 14px; position:relative; box-shadow:0 8px 40px rgba(0,0,0,0.18);">
                <button type="button" onclick="cerrarModalPedido()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
                <h3 id="pedidoModalTitulo" style="margin-top:0; margin-bottom:20px; font-size:1.2rem;">📋 Agregar Pedido al Proveedor</h3>
                <input type="hidden" id="pedidoId">

                <!-- Datos generales -->
                <div style="margin-bottom:14px; display:grid; grid-template-columns:2fr 1fr; gap:12px; align-items:start;">
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Proveedor <span style="color:red;">*</span></label>
                        <select id="selectPedidoProveedor" onchange="pedidoProveedorSeleccionado()" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:0.97rem;"></select>
                        <small id="pedidoInfoProductos" style="display:none; color:#6b7280; font-size:0.82rem; margin-top:6px;"></small>
                    </div>
                    <div>
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Fecha del Pedido <span style="color:red;">*</span></label>
                        <input type="datetime-local" id="pedidoFecha" required style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; font-size:0.97rem;">
                    </div>
                </div>

                <!-- Items del pedido -->
                <div style="border:1px solid #e0e0e0; border-radius:10px; padding:16px; background:#f9f9f9; margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <strong style="font-size:1rem;">📦 Productos a Pedir</strong>
                        <button type="button" class="btn-primary" style="padding:7px 14px; font-size:0.88rem;" onclick="agregarFilaPedido()">+ Agregar Producto</button>
                    </div>
                    <div class="table-responsive">
                        <table class="inventory-table" id="tablaPedidoDetalles" style="min-width:480px;">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Cantidad</th>
                                    <th>Unidad</th>
                                    <th>Precio Estimado (USD)</th>
                                    <th>Subtotal</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody id="pedidoItemsBody">
                                <tr id="pedidoItemsVacio"><td colspan="6" style="text-align:center; color:#aaa; padding:16px;">Sin productos agregados</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Sugerencias de reposición -->
                    <div id="pedidoAyudaStock" style="display:none; margin-top:12px; padding:10px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; font-size:0.85rem; color:#9a3412;"></div>
                    <!-- Resumen total -->
                    <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                        <div style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:10px 20px; font-size:0.97rem;">
                            Total estimado: <strong id="pedidoTotalDisplay" style="color:#2563eb;">$0.00 USD</strong>
                        </div>
                    </div>
                </div>

                <!-- Botones -->
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:8px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalPedido()" style="padding:10px 22px;">Cancelar</button>
                    <button type="button" class="btn-primary" onclick="enviarPedido()" id="btnGuardarPedido" style="padding:10px 22px;">✅ Guardar Pedido</button>
                </div>
            </div>
        </div>
        <datalist id="productosPedidoList"></datalist>

        <!-- Seccion Clientes -->
        <div id="admin-seccion-clientes" class="admin-panel-content hidden">
            <div class="admin-card">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                    <h2 style="margin:0;">👥 Clientes Registrados</h2>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte de Clientes', 'clientesTableBody')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                        <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Clientes', 'clientesTableBody')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        <button type="button" class="btn-primary" onclick="abrirModalNuevoCliente()" style="padding:8px 16px; font-weight:600;">➕ Nuevo Cliente</button>
                    </div>
                </div>

                <?php renderBarraFiltrosFechaHora('clientesTableBody', 'Buscar por nombre, cédula, usuario, email o teléfono...'); ?>
                <div class="table-responsive">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Cédula</th>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Teléfono</th>
                                <th>Fecha Registro</th>
                            </tr>
                        </thead>
                        <tbody id="clientesTableBody"></tbody>
                    </table>
                </div>
                <?php renderPaginacionBar('clientesTableBody'); ?>
            </div>
        </div>

        <!-- Modal Registrar Nuevo Cliente -->
        <div id="modalNuevoCliente" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center; padding:20px;">
            <div class="admin-card" style="width:100%; max-width:580px; max-height:90vh; overflow-y:auto; background: var(--bg-card, #fff); padding: 30px; border-radius: 18px; position:relative; box-shadow:0 12px 48px rgba(0,0,0,0.2);">
                <button type="button" onclick="cerrarModalNuevoCliente()" style="position:absolute; top:18px; right:18px; background:#f1f5f9; border:none; width:36px; height:36px; border-radius:50%; font-size:1.2rem; cursor:pointer; color:#64748b; display:flex; align-items:center; justify-content:center; transition:all 0.2s;">&times;</button>
                
                <h3 style="margin-top:0; margin-bottom:4px; font-size:1.3rem; font-weight:800; color:var(--text, #1e293b);">👥 Registrar Nuevo Cliente</h3>
                <p style="margin-top:0; margin-bottom:20px; color:#64748b; font-size:0.88rem;">Registra los datos del cliente para procesar sus compras en el negocio.</p>

                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Nombre y Apellido <span style="color:#ef4444;">*</span></label>
                        <input id="clienteNombre" placeholder="Ej: Juan Pérez" style="width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; box-sizing:border-box; background:#f8fafc; font-size:0.95rem; outline:none;" />
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; width:100%; box-sizing:border-box;">
                        <div style="min-width:0;">
                            <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Teléfono <span style="color:#ef4444;">*</span></label>
                            <div style="display:flex; align-items:center; border:1.5px solid #cbd5e1; border-radius:10px; overflow:hidden; background:#f8fafc; width:100%; box-sizing:border-box;">
                                <select id="clientePrefijoTelefono" style="padding:10px 2px 10px 6px; border:none; border-right:1.5px solid #cbd5e1; outline:none; background:#edf2f7; font-weight:700; color:#1e293b; cursor:pointer; width:72px; min-width:72px; box-sizing:border-box; font-size:0.88rem;">
                                    <option value="0412">0412</option>
                                    <option value="0422">0422</option>
                                    <option value="0414">0414</option>
                                    <option value="0424">0424</option>
                                    <option value="0416">0416</option>
                                    <option value="0426">0426</option>
                                </select>
                                <input id="clienteTelefono" placeholder="1234567" style="padding:10px 10px; border:none; outline:none; flex:1; min-width:0; width:100%; background:transparent; font-size:0.9rem; color:#1e293b; box-sizing:border-box;" inputmode="numeric" maxlength="7" />
                            </div>
                        </div>

                        <div style="min-width:0;">
                            <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Cédula <span style="color:#ef4444;">*</span></label>
                            <div style="display:flex; align-items:center; border:1.5px solid #cbd5e1; border-radius:10px; overflow:hidden; background:#f8fafc; width:100%; box-sizing:border-box;">
                                <span style="padding:10px 10px; background:#edf2f7; color:#1e293b; font-weight:700; font-size:0.88rem; border-right:1.5px solid #cbd5e1; user-select:none; shrink:0;">V-</span>
                                <input id="clienteCedula" placeholder="12345678" style="padding:10px 10px; border:none; outline:none; flex:1; min-width:0; width:100%; background:transparent; font-size:0.9rem; color:#1e293b; box-sizing:border-box;" inputmode="numeric" maxlength="12" />
                            </div>
                        </div>
                    </div>

                    <div>
                        <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Gmail (Opcional)</label>
                        <div style="display:flex; align-items:center; border:1.5px solid #cbd5e1; border-radius:10px; overflow:hidden; background:#f8fafc;">
                            <input id="clienteEmail" placeholder="juanperez (Opcional)" style="padding:12px 14px; border:none; flex:1; outline:none; min-width:0; background:transparent; font-size:0.95rem; color:#1e293b;" />
                            <span style="color:#64748b; font-weight:600; padding-right:14px; font-size:0.9rem;">@gmail.com</span>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                        <div>
                            <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Usuario <span style="color:#ef4444;">*</span></label>
                            <input id="clienteUsuario" placeholder="juanperez123" style="width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; box-sizing:border-box; background:#f8fafc; font-size:0.95rem; outline:none;" />
                        </div>
                        <div>
                            <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Contraseña <span style="color:#ef4444;">*</span></label>
                            <div style="position:relative; display:flex; align-items:center;">
                                <input id="clientePassword" type="password" placeholder="••••••••" value="12345678" autocomplete="new-password" style="width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; box-sizing:border-box; background:#f8fafc; font-size:0.95rem; outline:none;" />
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                        <div>
                            <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Pregunta de Seguridad <span style="color:#ef4444;">*</span></label>
                            <select id="clientePreguntaSeguridad" style="width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; box-sizing:border-box; background:#f8fafc; font-size:0.95rem; outline:none;">
                                <option value="">Selecciona...</option>
                                <?php foreach (listarPreguntasSeguridad($pdo) as $ps): ?>
                                    <option value="<?php echo (int)$ps['id']; ?>"><?php echo Sanitizer::escapeHtml($ps['pregunta']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:700; display:block; margin-bottom:6px; font-size:0.88rem; color:#334155;">Respuesta de Seguridad <span style="color:#ef4444;">*</span></label>
                            <input id="clienteRespuestaSeguridad" placeholder="La usará para recuperar su clave" style="width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; box-sizing:border-box; background:#f8fafc; font-size:0.95rem; outline:none;" />
                        </div>
                    </div>

                    <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:14px;">
                        <button type="button" class="btn-secondary" onclick="cerrarModalNuevoCliente()" style="padding:12px 24px; border-radius:10px; font-weight:600;">Cancelar</button>
                        <button type="button" class="btn-primary" onclick="guardarClienteAdmin()" id="btnGuardarClienteAdmin" style="padding:12px 26px; border-radius:10px; font-weight:700;">✅ Guardar Cliente</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seccion Pagos Pendientes -->
        <div id="admin-seccion-pagos" class="admin-panel-content hidden">
            <div class="admin-card">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:1rem;">
                    <h2 style="margin:0;">✅ Pagos Pendientes de Verificación</h2>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Reporte de Pagos Pendientes', 'pagosTableBody')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                        <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Pagos', 'pagosTableBody')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        <button onclick="cargarPagosPendientes()" class="btn-primary" style="padding:8px 16px;">🔄 Actualizar</button>
                    </div>
                </div>
                <div style="margin-bottom:1rem;">
                    <input type="text" onkeyup="filtrarTablaEnVivo(this, 'pagosTableBody')" placeholder="🔍 Buscar pago por cliente, venta #, referencia o banco..." style="width:100%; max-width:450px; padding:9px 12px; border:1px solid #ccc; border-radius:6px; font-size:0.9rem;">
                </div>
                <p class="admin-help-text">Revisa el comprobante del cliente y valida o rechaza el pago. Al validar, el stock se descuenta automáticamente.</p>
                <div class="table-responsive">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Venta #</th>
                                <th>Cliente</th>
                                <th>Método</th>
                                <th>Banco Origen</th>
                                <th>Teléfono</th>
                                <th>Referencia</th>
                                <th>Monto Bs</th>
                                <th>Comprobante</th>
                                <th>Fecha</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="pagosTableBody">
                            <tr><td colspan="10" style="text-align:center; color:var(--text-light);">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <?php renderPaginacionBar('pagosTableBody'); ?>
            </div>
        </div>

        <!-- Seccion Mi Cuenta (Admin Profile & Settings) -->
        <!-- Seccion Libro de IVA (control interno: débito/crédito fiscal e IGTF) -->
        <div id="admin-seccion-fiscal" class="admin-panel-content hidden">
            <div class="admin-card" style="margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:18px;">
                    <div>
                        <h2 style="margin:0; display:flex; align-items:center; gap:8px;">🧮 Libro de IVA</h2>
                        <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                            Control interno de IVA (débito/crédito fiscal) e IGTF. No constituye factura fiscal ni número de control.
                        </p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" onclick="exportarTablaCSV('Libro_IVA_Ventas', 'libroIvaVentasTable')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Excel Ventas</button>
                        <button type="button" onclick="imprimirReporteSeccion('Libro de IVA - Ventas', 'libroIvaVentasTable')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF Ventas</button>
                        <button type="button" onclick="exportarTablaCSV('Libro_IVA_Compras', 'libroIvaComprasTable')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Excel Compras</button>
                        <button type="button" onclick="imprimirReporteSeccion('Libro de IVA - Compras', 'libroIvaComprasTable')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF Compras</button>
                    </div>
                </div>

                <!-- Rango de fechas -->
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; background:rgba(0,0,0,0.02); padding:12px; border-radius:10px; margin-bottom:18px;">
                    <label style="display:flex; flex-direction:column; gap:4px; font-size:0.82rem; font-weight:600; color:#555;">📅 Desde
                        <input type="date" id="libroIvaDesde" onchange="cargarLibroIva()" style="padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem;">
                    </label>
                    <label style="display:flex; flex-direction:column; gap:4px; font-size:0.82rem; font-weight:600; color:#555;">Hasta
                        <input type="date" id="libroIvaHasta" onchange="cargarLibroIva()" style="padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:0.85rem;">
                    </label>
                    <button type="button" class="btn-primary" onclick="cargarLibroIva()" style="padding:9px 18px;">🔍 Generar</button>
                    <button type="button" onclick="libroIvaMesActual()" style="padding:9px 14px; border:1px solid #ccc; border-radius:6px; background:#fff; cursor:pointer; font-size:0.85rem; font-weight:600;">📆 Mes actual</button>
                    <button type="button" onclick="libroIvaMesAnterior()" style="padding:9px 14px; border:1px solid #ccc; border-radius:6px; background:#fff; cursor:pointer; font-size:0.85rem; font-weight:600;">📆 Mes anterior</button>
                </div>

                <!-- KPIs -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:24px;">
                    <div style="background:#fff; border:1px solid #fee2e2; border-left:5px solid #dc2626; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.8rem; color:#6b7280; font-weight:700; text-transform:uppercase;">IVA Débito Fiscal (ventas)</div>
                        <div id="libroIvaKpiDebito" style="font-size:1.5rem; font-weight:800; color:#dc2626; margin-top:4px;">$0.00</div>
                    </div>
                    <div style="background:#fff; border:1px solid #dbeafe; border-left:5px solid #2563eb; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.8rem; color:#6b7280; font-weight:700; text-transform:uppercase;">IVA Crédito Fiscal (compras)</div>
                        <div id="libroIvaKpiCredito" style="font-size:1.5rem; font-weight:800; color:#2563eb; margin-top:4px;">$0.00</div>
                    </div>
                    <div style="background:#fff; border:1px solid #e0e7ff; border-left:5px solid #4f46e5; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div id="libroIvaKpiNetoLabel" style="font-size:0.8rem; color:#6b7280; font-weight:700; text-transform:uppercase;">IVA a Pagar del Período</div>
                        <div id="libroIvaKpiNeto" style="font-size:1.5rem; font-weight:800; color:#4f46e5; margin-top:4px;">$0.00</div>
                    </div>
                    <div style="background:#fff; border:1px solid #fef3c7; border-left:5px solid #d97706; padding:16px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                        <div style="font-size:0.8rem; color:#6b7280; font-weight:700; text-transform:uppercase;">IGTF del Período</div>
                        <div id="libroIvaKpiIgtf" style="font-size:1.5rem; font-weight:800; color:#d97706; margin-top:4px;">$0.00</div>
                    </div>
                </div>

                <h3 style="font-size:1rem; margin-bottom:8px;">📊 Ventas por alícuota</h3>
                <div class="table-responsive">
                    <table class="inventory-table" id="libroIvaAlicuotaTable">
                        <thead>
                            <tr>
                                <th>Alícuota</th>
                                <th>Base gravada (USD)</th>
                                <th>IVA (USD)</th>
                                <th>Total (USD)</th>
                            </tr>
                        </thead>
                        <tbody id="libroIvaAlicuotaBody">
                            <tr><td colspan="4" style="text-align:center; color:#aaa; padding:16px;">Genera el libro para ver el resumen.</td></tr>
                        </tbody>
                    </table>
                </div>

                <h3 style="font-size:1rem; margin:22px 0 8px;">⚖️ Cuadro débito / crédito fiscal</h3>
                <div class="table-responsive">
                    <table class="inventory-table" id="libroIvaCuadroTable">
                        <thead>
                            <tr>
                                <th>Concepto</th>
                                <th style="text-align:right;">Base (USD)</th>
                                <th style="text-align:right;">IVA (USD)</th>
                            </tr>
                        </thead>
                        <tbody id="libroIvaCuadroBody">
                            <tr><td colspan="3" style="text-align:center; color:#aaa; padding:16px;">Genera el libro para ver el cuadro.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admin-card" style="margin-bottom:20px;">
                <h3 style="font-size:1rem; margin-bottom:12px;">🧾 Libro de ventas</h3>
                <div class="table-responsive">
                    <table class="inventory-table" id="libroIvaVentasTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Método</th>
                                <th>Estado</th>
                                <th style="text-align:right;">Base</th>
                                <th style="text-align:right;">IVA</th>
                                <th style="text-align:right;">IGTF</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="libroIvaVentasBody">
                            <tr><td colspan="9" style="text-align:center; color:#aaa; padding:16px;">Genera el libro para ver las ventas.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admin-card">
                <h3 style="font-size:1rem; margin-bottom:12px;">🧾 Libro de compras</h3>
                <div class="table-responsive">
                    <table class="inventory-table" id="libroIvaComprasTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fecha</th>
                                <th>Proveedor</th>
                                <th>Factura</th>
                                <th style="text-align:right;">Base</th>
                                <th style="text-align:right;">IVA crédito</th>
                                <th style="text-align:right;">Retención IVA</th>
                                <th style="text-align:right;">IGTF</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="libroIvaComprasBody">
                            <tr><td colspan="9" style="text-align:center; color:#aaa; padding:16px;">Genera el libro para ver las compras.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="admin-seccion-micuenta" class="admin-panel-content hidden">
            <div class="admin-card">
                <h2>⚙️ Configuración de Mi Cuenta y Sistema</h2>
                <div id="adminProfileAlert" class="alert-box" style="display:none; margin-bottom:15px; padding:12px; border-radius:8px;"></div>
                
                <!-- 1. Perfil del Administrador -->
                <form id="adminPerfilForm" onsubmit="guardarPerfilAdmin(event)" style="margin-bottom:30px;">
                    <h3 style="display:flex; align-items:center; gap:8px;">👤 Información de Perfil Personal</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Nombre de Usuario</label>
                            <input type="text" id="adminUsuario" readonly style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; background:#f0f0f0; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Nombre Completo</label>
                            <input type="text" id="adminNombreCompleto" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Correo Electrónico</label>
                            <input type="email" id="adminEmail" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Teléfono de Contacto</label>
                            <div style="display:flex; align-items:center; border:1px solid #ccc; border-radius:8px; overflow:hidden; margin-top:5px;">
                                <select id="prefijoAdminTelefono" style="padding:10px; border:none; border-right:1px solid #ccc; background:#f9f9f9; outline:none; cursor:pointer;">
                                    <option value="0412">0412</option>
                                    <option value="0422">0422</option>
                                    <option value="0414">0414</option>
                                    <option value="0424">0424</option>
                                    <option value="0416">0416</option>
                                    <option value="0426">0426</option>
                                </select>
                                <input type="text" id="adminTelefonoNum" placeholder="1234567" maxlength="7" inputmode="numeric" style="padding:10px; border:none; outline:none; flex:1;">
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:15px; text-align:right;">
                        <button type="submit" class="btn-primary" style="padding:10px 22px;">💾 Guardar Datos Personales</button>
                    </div>
                </form>

                <hr style="border:0; border-top:1px solid #eee; margin:25px 0;">

                <!-- 2. Información del Negocio -->
                <form id="adminNegocioForm" onsubmit="guardarNegocioAdmin(event)" style="margin-bottom:30px;">
                    <h3 style="display:flex; align-items:center; gap:8px;">🏢 Información del Negocio / Tienda</h3>
                    <p style="font-size:0.88rem; color:#64748b; margin-top:4px;">Esta información se mostrará a los clientes en el catálogo y comprobantes.</p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Nombre Comercial del Negocio</label>
                            <input type="text" id="negocioNombre" required placeholder="Ej: Tecnology Tienda Digital" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Teléfono del Negocio</label>
                            <input type="text" id="negocioTelefono" placeholder="Ej: 0414-1234567" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Horario de Atención</label>
                            <input type="text" id="negocioHorario" placeholder="Ej: Lunes a Sábado 8:00 AM - 6:00 PM" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Ubicación / Dirección Física (o enlace de Google Maps)</label>
                            <input type="text" id="negocioUbicacion" placeholder="Ej: Av. Principal, C.C. Centro o enlace https://maps.google.com/..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>

                    <h4 style="margin:22px 0 0 0; color:var(--primary, #2563eb);">🧮 Datos Fiscales (IVA / IGTF — control interno)</h4>
                    <p style="font-size:0.85rem; color:#64748b; margin:4px 0 0 0;">Se usan para el Libro de IVA y los reportes internos. No reemplazan la facturación fiscal.</p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">RIF del Negocio</label>
                            <input type="text" id="negocioRif" placeholder="Ej: J-12345678-9" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Dirección Fiscal</label>
                            <input type="text" id="negocioDireccionFiscal" placeholder="Ej: Av. Principal, Local 1, Caracas" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Alícuota General de IVA (%)</label>
                            <input type="number" id="negocioIvaGeneral" step="0.01" min="0" max="100" value="16" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Alícuota de IGTF (%)</label>
                            <input type="number" id="negocioIgtfPct" step="0.01" min="0" max="100" value="3" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">¿Es Agente de Retención de IVA?</label>
                            <select id="negocioAgenteRetencion" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px; background:#fff;">
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">% de Retención de IVA</label>
                            <input type="number" id="negocioRetencionPct" step="0.01" min="0" max="100" value="75" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>
                    <div style="margin-top:15px; text-align:right;">
                        <button type="submit" class="btn-primary" style="padding:10px 22px;">🏢 Guardar Datos del Negocio</button>
                    </div>
                </form>

                <hr style="border:0; border-top:1px solid #eee; margin:25px 0;">

                <!-- 3. Información Bancaria -->
                <form id="adminBancosForm" onsubmit="guardarBancosAdmin(event)" style="margin-bottom:30px;">
                    <h3 style="display:flex; align-items:center; gap:8px;">🏦 Datos Bancarios para Cobros</h3>
                    <p style="font-size:0.88rem; color:#64748b; margin-top:4px;">Configura las cuentas donde los clientes realizarán sus pagos por Pago Móvil, Transferencia, Zelle y Biopago.</p>
                    
                    <h4 style="margin-top:15px; margin-bottom:10px; color:var(--primary, #2563eb);">📱 Pago Móvil</h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Banco Receptores</label>
                            <select id="pmBanco" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px; background:#fff;">
                                <option value="">Seleccione Banco...</option>
                                <option value="0102 - Banco de Venezuela">0102 - Banco de Venezuela</option>
                                <option value="0104 - Banco Venezolano de Crédito">0104 - Banco Venezolano de Crédito</option>
                                <option value="0105 - Mercantil">0105 - Mercantil</option>
                                <option value="0108 - Provincial">0108 - Provincial</option>
                                <option value="0114 - Bancaribe">0114 - Bancaribe</option>
                                <option value="0115 - Banco Exterior">0115 - Banco Exterior</option>
                                <option value="0128 - Banco Caroní">0128 - Banco Caroní</option>
                                <option value="0134 - Banesco">0134 - Banesco</option>
                                <option value="0137 - Banco Sofitasa">0137 - Banco Sofitasa</option>
                                <option value="0138 - Banco Plaza">0138 - Banco Plaza</option>
                                <option value="0151 - BFC Banco Fondo Común">0151 - BFC Banco Fondo Común</option>
                                <option value="0156 - 100% Banco">0156 - 100% Banco</option>
                                <option value="0157 - Bancosur">0157 - Bancosur</option>
                                <option value="0163 - Banco del Tesoro">0163 - Banco del Tesoro</option>
                                <option value="0166 - Banco Agrícola">0166 - Banco Agrícola</option>
                                <option value="0168 - Bancrecer">0168 - Bancrecer</option>
                                <option value="0169 - Mi Banco">0169 - Mi Banco</option>
                                <option value="0171 - Banco Activo">0171 - Banco Activo</option>
                                <option value="0172 - Bancamiga">0172 - Bancamiga</option>
                                <option value="0174 - Banplus">0174 - Banplus</option>
                                <option value="0175 - Banco Bicentenario">0175 - Banco Bicentenario</option>
                                <option value="0177 - Banfanb">0177 - Banfanb</option>
                                <option value="0191 - BNC">0191 - BNC</option>
                                <option value="Otro">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Teléfono Pago Móvil</label>
                            <input type="text" id="pmTelefono" placeholder="Ej: 0414-1234567" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Cédula / RIF Titular</label>
                            <input type="text" id="pmCedula" placeholder="Ej: V-12345678 / J-12345678-9" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>

                    <h4 style="margin-top:20px; margin-bottom:10px; color:var(--primary, #2563eb);">🏦 Transferencia Bancaria</h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Banco Receptor</label>
                            <select id="transfBanco" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px; background:#fff;">
                                <option value="">Seleccione Banco...</option>
                                <option value="0102 - Banco de Venezuela">0102 - Banco de Venezuela</option>
                                <option value="0104 - Banco Venezolano de Crédito">0104 - Banco Venezolano de Crédito</option>
                                <option value="0105 - Mercantil">0105 - Mercantil</option>
                                <option value="0108 - Provincial">0108 - Provincial</option>
                                <option value="0114 - Bancaribe">0114 - Bancaribe</option>
                                <option value="0115 - Banco Exterior">0115 - Banco Exterior</option>
                                <option value="0128 - Banco Caroní">0128 - Banco Caroní</option>
                                <option value="0134 - Banesco">0134 - Banesco</option>
                                <option value="0137 - Banco Sofitasa">0137 - Banco Sofitasa</option>
                                <option value="0138 - Banco Plaza">0138 - Banco Plaza</option>
                                <option value="0151 - BFC Banco Fondo Común">0151 - BFC Banco Fondo Común</option>
                                <option value="0156 - 100% Banco">0156 - 100% Banco</option>
                                <option value="0157 - Bancosur">0157 - Bancosur</option>
                                <option value="0163 - Banco del Tesoro">0163 - Banco del Tesoro</option>
                                <option value="0166 - Banco Agrícola">0166 - Banco Agrícola</option>
                                <option value="0168 - Bancrecer">0168 - Bancrecer</option>
                                <option value="0169 - Mi Banco">0169 - Mi Banco</option>
                                <option value="0171 - Banco Activo">0171 - Banco Activo</option>
                                <option value="0172 - Bancamiga">0172 - Bancamiga</option>
                                <option value="0174 - Banplus">0174 - Banplus</option>
                                <option value="0175 - Banco Bicentenario">0175 - Banco Bicentenario</option>
                                <option value="0177 - Banfanb">0177 - Banfanb</option>
                                <option value="0191 - BNC">0191 - BNC</option>
                                <option value="Otro">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Número de Cuenta (20 dígitos)</label>
                            <input type="text" id="transfCuenta" maxlength="20" placeholder="01340000000000000000" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Nombre del Titular</label>
                            <input type="text" id="transfTitular" placeholder="Ej: Nombre Empresa o Titular" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Cédula / RIF del Titular</label>
                            <input type="text" id="transfCedula" placeholder="Ej: V-12345678 / J-12345678-9" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>

                    <h4 style="margin-top:20px; margin-bottom:10px; color:var(--primary, #2563eb);">🌐 Zelle</h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Correo / Usuario Zelle</label>
                            <input type="text" id="zelleCorreo" placeholder="Ej: tienda@correo.com" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Titular de la Cuenta</label>
                            <input type="text" id="zelleTitular" placeholder="Nombre del titular" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>

                    <h4 style="margin-top:20px; margin-bottom:10px; color:var(--primary, #2563eb);">⚡ Biopago</h4>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px;">
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Banco Receptor</label>
                            <select id="biopagoBanco" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px; background:#fff;">
                                <option value="">Seleccione Banco...</option>
                                <option value="0102 - Banco de Venezuela">0102 - Banco de Venezuela</option>
                                <option value="0104 - Banco Venezolano de Crédito">0104 - Banco Venezolano de Crédito</option>
                                <option value="0105 - Mercantil">0105 - Mercantil</option>
                                <option value="0108 - Provincial">0108 - Provincial</option>
                                <option value="0114 - Bancaribe">0114 - Bancaribe</option>
                                <option value="0115 - Banco Exterior">0115 - Banco Exterior</option>
                                <option value="0128 - Banco Caroní">0128 - Banco Caroní</option>
                                <option value="0134 - Banesco">0134 - Banesco</option>
                                <option value="0137 - Banco Sofitasa">0137 - Banco Sofitasa</option>
                                <option value="0138 - Banco Plaza">0138 - Banco Plaza</option>
                                <option value="0151 - BFC Banco Fondo Común">0151 - BFC Banco Fondo Común</option>
                                <option value="0156 - 100% Banco">0156 - 100% Banco</option>
                                <option value="0157 - Bancosur">0157 - Bancosur</option>
                                <option value="0163 - Banco del Tesoro">0163 - Banco del Tesoro</option>
                                <option value="0166 - Banco Agrícola">0166 - Banco Agrícola</option>
                                <option value="0168 - Bancrecer">0168 - Bancrecer</option>
                                <option value="0169 - Mi Banco">0169 - Mi Banco</option>
                                <option value="0171 - Banco Activo">0171 - Banco Activo</option>
                                <option value="0172 - Bancamiga">0172 - Bancamiga</option>
                                <option value="0174 - Banplus">0174 - Banplus</option>
                                <option value="0175 - Banco Bicentenario">0175 - Banco Bicentenario</option>
                                <option value="0177 - Banfanb">0177 - Banfanb</option>
                                <option value="0191 - BNC">0191 - BNC</option>
                                <option value="Otro">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Cédula / RIF Titular</label>
                            <input type="text" id="biopagoCedula" placeholder="Ej: V-12345678 / J-12345678-9" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Nombre del Titular</label>
                            <input type="text" id="biopagoTitular" placeholder="Ej: Nombre Empresa o Titular" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>
                    
                    <div style="margin-top:15px; text-align:right;">
                        <button type="submit" class="btn-primary" style="padding:10px 22px;">💳 Guardar Datos Bancarios</button>
                    </div>
                </form>

                <hr style="border:0; border-top:1px solid #eee; margin:25px 0;">

                <!-- 4. Configuración de Apartados (cuotas y plazo de pago) -->
                <form id="adminApartadoForm" onsubmit="guardarConfigApartado(event)" style="margin-bottom:30px;">
                    <h3 style="display:flex; align-items:center; gap:8px;">🏷️ Configuración de Apartados</h3>
                    <p style="font-size:0.88rem; color:#64748b; margin-top:4px;">
                        Define en cuántas cuotas debe pagar el cliente el apartado y en cuántos días debe completarlo.
                        Los apartados nuevos toman estas reglas; los ya existentes conservan el plan con el que se crearon.
                    </p>
                    <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; margin-top:15px;">
                        <div>
                            <label for="cfgApartadoCuotas" style="font-weight:600; font-size:0.9rem;">💳 Cuotas a pagar</label>
                            <input type="number" id="cfgApartadoCuotas" min="1" max="12" value="<?php echo $apartadoCuotasCfg; ?>" style="display:block; width:160px; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label for="cfgApartadoDias" style="font-weight:600; font-size:0.9rem;">⏰ Límite de pago (días)</label>
                            <input type="number" id="cfgApartadoDias" min="1" max="90" value="<?php echo $apartadoDiasCfg; ?>" style="display:block; width:160px; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <button type="submit" id="btnGuardarConfigApartado" class="btn-primary" style="padding:10px 22px;">💾 Guardar Configuración</button>
                        <span id="cfgApartadoMensaje" style="font-size:0.85rem; font-weight:700; color:#15803d;"></span>
                    </div>
                    <div style="margin-top:10px; font-size:0.82rem; color:#6b7280; line-height:1.6;">
                        ℹ️ <strong>Cuota sugerida = total del apartado ÷ número de cuotas.</strong> El plazo de pago corre desde la fecha en que se creó el apartado.
                        Se aplica en la venta presencial, la tienda en línea y se muestra en cada apartado del Historial de Clientes.
                    </div>
                </form>

                <hr style="border:0; border-top:1px solid #eee; margin:25px 0;">

                <!-- 5. Pregunta de Seguridad (recuperación de clave) -->
                <form id="adminSeguridadForm" onsubmit="guardarSeguridadAdmin(event)">
                    <h3 style="display:flex; align-items:center; gap:8px;">🛟 Pregunta de Seguridad</h3>
                    <p style="color:var(--text-light, #666); font-size:0.88rem; margin:8px 0 0;">
                        Necesaria para recuperar la contraseña del panel si la olvidas.
                    </p>
                    <div id="adminAvisoSeguridad"
                         style="display:none; background:#fff4e5; color:#9a3412; border:1px solid #fed7aa; padding:12px 16px; border-radius:10px; margin-top:15px; font-size:0.9rem; font-weight:600;">
                        ⚠️ Tu cuenta todavía no tiene pregunta de seguridad. Sin ella no podrás recuperar la contraseña si la olvidas.
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:15px;">
                        <div style="grid-column: 1 / -1;">
                            <label style="font-weight:600; font-size:0.9rem;">Pregunta</label>
                            <select id="adminPreguntaSeguridad" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px; background:var(--bg, #fff); color:var(--text, #222);">
                                <option value="">Selecciona una pregunta...</option>
                                <?php foreach (listarPreguntasSeguridad($pdo) as $ps): ?>
                                    <option value="<?php echo (int)$ps['id']; ?>"><?php echo Sanitizer::escapeHtml($ps['pregunta']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Respuesta</label>
                            <input type="text" id="adminRespuestaSeguridad" maxlength="100" placeholder="Ej: Tecky" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Contraseña Actual</label>
                            <input type="password" id="adminPasswordActualSeguridad" placeholder="••••••••" autocomplete="current-password" style="width:100%; padding:10px 42px 10px 10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>
                    <div style="margin-top:15px; text-align:right;">
                        <button type="submit" class="btn-primary" style="padding:10px 22px;">🛡️ Guardar Pregunta</button>
                    </div>
                </form>

                <hr style="border:0; border-top:1px solid #eee; margin:25px 0;">

                <!-- 5. Cambiar Contraseña -->
                <form id="adminPasswordForm" onsubmit="guardarPasswordAdmin(event)">
                    <h3 style="display:flex; align-items:center; gap:8px;">🔒 Cambiar Contraseña</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:15px;">
                        <div style="grid-column: 1 / -1;">
                            <label style="font-weight:600; font-size:0.9rem;">Contraseña Actual</label>
                            <input type="password" id="adminPasswordActual" placeholder="••••••••" autocomplete="current-password" style="width:100%; padding:10px 42px 10px 10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Nueva Contraseña</label>
                            <input type="password" id="adminNuevaPassword" placeholder="Mínimo 8 caracteres" autocomplete="new-password" style="width:100%; padding:10px 42px 10px 10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                        <div>
                            <label style="font-weight:600; font-size:0.9rem;">Confirmar Nueva Contraseña</label>
                            <input type="password" id="adminConfirmarPassword" placeholder="Repite la contraseña" autocomplete="new-password" style="width:100%; padding:10px 42px 10px 10px; border:1px solid #ccc; border-radius:8px; margin-top:5px;">
                        </div>
                    </div>
                    <div id="adminHintConfirmar" style="font-size:0.85rem; color:var(--text-light,#666); margin-top:10px;">
                        Las dos contraseñas deben coincidir.
                    </div>
                    <div style="margin-top:15px; text-align:right;">
                        <button type="submit" class="btn-primary" style="padding:10px 22px;">🔑 Actualizar Contraseña</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 5. Módulo de Seguridad / Base de Datos -->
        <div id="admin-seccion-seguridad" class="admin-panel-content hidden">
            <div class="admin-card">
                <h2>🛡️ Seguridad y Respaldo de Base de Datos</h2>
                <p style="color:var(--text-light); margin-top:5px; margin-bottom:25px;">Gestiona las copias de seguridad (Backup) y la restauración del sistema.</p>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:25px;">
                    
                    <!-- CARD 1: GENERAR Y DESCARGAR BACKUP -->
                    <div style="background:var(--bg, #f8f9fa); border-radius:16px; padding:25px; border:1px solid rgba(0,0,0,0.08); display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div style="font-size:2.5rem; margin-bottom:12px;">💾</div>
                            <h3 style="margin-bottom:8px; font-size:1.15rem; color:var(--text, #222);">Generar Backup (.sql)</h3>
                            <p style="color:var(--text-light, #666); font-size:0.9rem; line-height:1.5;">
                                Descarga una copia completa de la base de datos (productos, clientes, pedidos, ventas y configuraciones) en un archivo ejecutable <strong>.sql</strong>.
                            </p>
                        </div>
                        <div style="margin-top:20px;">
                            <a href="../api/backup_db.php" class="btn-primary" style="display:block; text-align:center; padding:12px 20px; text-decoration:none; border-radius:10px; font-weight:700;">
                                📥 Generar y Descargar Backup
                            </a>
                        </div>
                    </div>

                    <!-- CARD 2: RESTAURAR BASE DE DATOS -->
                    <div style="background:var(--bg, #f8f9fa); border-radius:16px; padding:25px; border:1px solid rgba(0,0,0,0.08);">
                        <div style="font-size:2.5rem; margin-bottom:12px;">🔄</div>
                        <h3 style="margin-bottom:8px; font-size:1.15rem; color:var(--text, #222);">Restaurar Base de Datos</h3>
                        <p style="color:var(--text-light, #666); font-size:0.9rem; line-height:1.5; margin-bottom:15px;">
                            Sube un archivo de respaldo <strong>.sql</strong> para restaurar la información del sistema. 
                            <br><span style="color:var(--danger); font-weight:600;">⚠️ Advertencia: Esto sobreescribirá la información actual.</span>
                        </p>

                        <form id="restoreDbForm" onsubmit="restaurarBaseDeDatos(event)">
                            <div style="margin-bottom:15px;">
                                <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px; color:var(--text, #333);">Seleccionar archivo Backup (.sql):</label>
                                <input type="file" id="sqlFileInput" accept=".sql" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; background:#fff; color:#333;">
                            </div>
                            <button type="submit" id="btnRestoreSubmit" class="btn-primary" style="width:100%; padding:12px; border-radius:10px; font-weight:700; background:var(--warning); color:white; border:none; cursor:pointer;">
                                📤 Restaurar BD
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>

        <!-- Seccion Cierre de Caja y Arqueo -->
        <div id="admin-seccion-caja" class="admin-panel-content hidden">
            <div class="admin-card" style="margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom: 20px;">
                    <div>
                        <h2 style="margin:0; font-size:1.5rem; display:flex; align-items:center; gap:8px;">
                            💵 Cierre de Caja y Arqueo Diario
                        </h2>
                        <p style="margin:4px 0 0 0; color:var(--text-muted, #666); font-size:0.9rem;">
                            Control de apertura de turno, registro de efectivo, cuadrante de saldos y arqueo de caja chica.
                        </p>
                    </div>
                    <div id="cajaHeaderAcciones" style="display:flex; gap:10px;">
                        <!-- Cargado dinámicamente según si la caja está ABIERTA o CERRADA -->
                    </div>
                </div>

                <!-- Panel de Estado del Turno Activo -->
                <div id="cajaStatusBanner" style="background:var(--bg-card, #fff); border:1px solid var(--border-color, #eee); border-radius:12px; padding:20px; margin-bottom:24px;">
                    <div style="text-align:center; padding:30px; color:#888;">Cargando estado de la caja...</div>
                </div>

                <!-- Historial de Cierres de Caja -->
                <div style="margin-top:30px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:15px;">
                        <h3 style="margin:0; font-size:1.1rem; display:flex; align-items:center; gap:8px;">
                            📅 Historial de Turnos y Cierres de Caja
                        </h3>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <button type="button" class="btn-primary" onclick="imprimirReporteSeccion('Historial de Turnos y Cierres de Caja', 'cajaHistorialTableBody')" style="background:#4b5563; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📄 PDF / Imprimir</button>
                            <button type="button" class="btn-primary" onclick="exportarTablaCSV('Reporte_Cierres_Caja', 'cajaHistorialTableBody')" style="background:#16a34a; border:none; padding:8px 14px; border-radius:6px; font-weight:600; cursor:pointer; color:#fff;">📊 Exportar Excel</button>
                        </div>
                    </div>
                    <?php renderBarraFiltrosFechaHora('cajaHistorialTableBody', 'Buscar por turno, cajero, estado o fecha...'); ?>
                    <div class="table-responsive">
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Turno #</th>
                                    <th>Apertura</th>
                                    <th>Cierre</th>
                                    <th>Cajero / Admin</th>
                                    <th>Ventas Efectivo</th>
                                    <th>Egresos ($)</th>
                                    <th>Reembolsos ($)</th>
                                    <th>Esperado ($)</th>
                                    <th>Contado ($)</th>
                                    <th>Diferencia ($)</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody id="cajaHistorialTableBody">
                                <tr><td colspan="11" style="text-align:center; color:#aaa; padding:20px;">Cargando historial de cierres...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php renderPaginacionBar('cajaHistorialTableBody'); ?>
                </div>

            </div>
        </div>

<!-- Modal Arqueo y Cierre de Caja -->
<div id="modalCierreCaja" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; display:none; justify-content:center; align-items:center;">
    <div class="admin-card" style="width: 100%; max-width: 520px; background: var(--bg-card, #fff); padding: 24px; border-radius: 12px; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#e74c3c;">🔒 Arqueo Físico y Cierre de Caja</h3>
        <form onsubmit="guardarCierreCaja(event)">
            <input type="hidden" id="cierreTurnoId">
            <div style="display:flex; flex-direction:column; gap:12px;">
                <!-- Arqueo por Métodos de Pago -->
                <div style="margin-top:12px;">
                    <h4 style="margin:0 0 8px 0; font-size:0.95rem; font-weight:700; color:#1f2937;">Conciliación por Métodos de Pago</h4>
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                            <thead>
                                <tr style="background:#f1f5f9; color:#475569; font-weight:700;">
                                    <th style="text-align:left; padding:8px 10px; border-bottom:1px solid #e5e7eb;">Método de Pago</th>
                                    <th style="text-align:right; padding:8px 10px; border-bottom:1px solid #e5e7eb;">Esperado ($)</th>
                                    <th style="text-align:right; padding:8px 10px; border-bottom:1px solid #e5e7eb;">Contado ($)</th>
                                    <th style="text-align:right; padding:8px 10px; border-bottom:1px solid #e5e7eb;">Diferencia ($)</th>
                                </tr>
                            </thead>
                            <tbody id="cierreTablaMetodosBody"></tbody>
                        </table>
                    </div>
                    <small style="display:block; margin-top:6px; color:#64748b; font-size:0.78rem; line-height:1.5;">
                        El efectivo se cuenta físicamente en el cajón. Los demás métodos se concilian con lo que aparece en la app/banco (transferencias, pago móvil, Zelle, Biopago, Punto de Venta).
                    </small>
                </div>

                <div>
                    <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:4px;">Conteo Físico Real de Efectivo en USD ($)</label>
                    <input type="number" step="0.01" id="cierreTotalConteoUSD" required placeholder="0.00" oninput="calcularDiferenciaCierreCaja()" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; font-size:1.2rem; font-weight:700;">
                </div>

                <div id="cierreDiferenciaBox" style="padding:10px; border-radius:8px; text-align:center; font-weight:700; background:#eee; color:#333;">
                    Diferencia: $0.00 (Caja Cuadrada)
                </div>

                <div>
                    <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:4px;">Observaciones / Justificación de Arqueo:</label>
                    <textarea id="cierreObservaciones" rows="2" placeholder="Comentarios del cajero/administrador..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;"></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalCierreCaja()" style="padding:10px 18px;">Cancelar</button>
                    <button type="submit" class="btn-primary" style="padding:10px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer;">Finalizar Cierre de Caja</button>
                </div>
            </div>
        </form>
    </div>
</div>

        <!-- Modal Ajuste de Inventario (Kardex) -->
        <div id="modalAjusteKardex" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
            <div class="admin-card" style="width:100%; max-width:540px; background:var(--bg-card, #fff); padding:28px; border-radius:16px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
                <button type="button" onclick="cerrarModalAjusteKardex()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>

                <h3 style="margin-top:0; margin-bottom:8px; font-size:1.3rem; display:flex; align-items:center; gap:8px; color:#4f46e5;">
                    ⚖️ Ajuste de Inventario
                </h3>
                <p style="margin-top:0; margin-bottom:20px; font-size:0.85rem; color:#666;">
                    Corrige el stock cuando el conteo físico no coincide con el sistema (pérdida no registrada, error de carga, donación, etc.). Queda asentado en el Kardex con tu motivo. Úsalo solo si no corresponde a una compra, venta, devolución o pérdida.
                </p>

                <form onsubmit="guardarAjusteKardex(event)" id="formAjusteKardex">
                    <div style="display:flex; flex-direction:column; gap:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Producto *</label>
                            <select id="kardexAjusteProducto" required onchange="actualizarInfoAjusteKardex()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.92rem; background:#fff;">
                                <option value="">-- Seleccionar producto --</option>
                            </select>
                        </div>

                        <div id="kardexAjusteInfo" style="display:none; background:#f3f4f6; padding:12px; border-radius:8px; font-size:0.85rem; border-left:4px solid #6366f1;">
                            <div>Stock actual en el sistema: <strong id="kardexAjusteStockActual">0</strong> unids</div>
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Tipo de ajuste *</label>
                            <select id="kardexAjusteTipo" required onchange="actualizarPreviewAjusteKardex()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.92rem; background:#fff;">
                                <option value="1">📥 Sumar unidades (entrada)</option>
                                <option value="-1">📤 Restar unidades (salida)</option>
                            </select>
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Cantidad *</label>
                            <input type="number" id="kardexAjusteCantidad" required min="1" step="1" placeholder="1" oninput="actualizarPreviewAjusteKardex()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.95rem; font-weight:700;">
                        </div>

                        <div id="kardexAjustePreview" style="background:#eef2ff; border:1px solid #c7d2fe; padding:10px 14px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:0.85rem; color:#3730a3; font-weight:600;">Stock después del ajuste:</span>
                            <strong id="kardexAjustePreviewValor" style="font-size:1.15rem; color:#4338ca;">-</strong>
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo del ajuste *</label>
                            <textarea id="kardexAjusteMotivo" required rows="3" placeholder="Ej. conteo físico: había 7 unidades y el sistema marcaba 5 (2 hurtadas sin reportar)." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" class="btn-secondary" onclick="cerrarModalAjusteKardex()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                            <button type="submit" class="btn-primary" style="padding:11px 22px; background:#4f46e5; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Registrar Ajuste</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        </main>
</div>

<!-- Modal Registrar Pérdida / Salida No Operativa -->
        <div id="modalRegistrarMerma" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
            <div class="admin-card" style="width:100%; max-width:550px; background:var(--bg-card, #fff); padding:28px; border-radius:16px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
                <button type="button" onclick="cerrarModalRegistrarMerma()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
                
                <h3 style="margin-top:0; margin-bottom:8px; font-size:1.3rem; display:flex; align-items:center; gap:8px; color:#dc2626;">
                    ⚠️ Registrar Salida No Operativa (Pérdida)
                </h3>
                <p style="margin-top:0; margin-bottom:20px; font-size:0.85rem; color:#666;">
                    Esta acción descontará las unidades del stock actual del producto. Queda registrada con su motivo y el usuario que la hizo, y se puede anular después sin borrar el historial.
                </p>

                <form onsubmit="guardarSalidaMerma(event)" id="formMerma">
                    <div style="display:flex; flex-direction:column; gap:14px;">
                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Seleccionar Producto *</label>
                            <select id="mermaProductoId" required onchange="actualizarInfoProductoMerma()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.92rem; background:#fff;">
                                <option value="">-- Seleccionar producto --</option>
                            </select>
                        </div>

                        <div id="mermaInfoBox" style="display:none; background:#f3f4f6; padding:12px; border-radius:8px; font-size:0.85rem; border-left:4px solid #3b82f6;">
                            <div>Stock Actual disponible: <strong id="mermaInfoStock">0</strong> unids</div>
                            <div>Costo Unitario Estimado: <strong id="mermaInfoCosto">$0.00</strong></div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div>
                                <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Tipo de Salida *</label>
                                <select id="mermaTipoSalida" required onchange="calcularTotalPerdidaMerma()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff;">
                                    <option value="DAÑADO">📦 Producto Dañado</option>
                                    <option value="ROBADO">🥷 Robado / Sustraído</option>
                                    <option value="EXTRAVIADO">❓ Extraviado / Perdido</option>
                                    <option value="VENCIDO">⏳ Vencido / Expirado</option>
                                    <option value="USO_INTERNO">🏢 Uso Interno / Muestra</option>
                                </select>
                            </div>

                            <div>
                                <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Cantidad a Descontar *</label>
                                <input type="number" id="mermaCantidad" required min="1" placeholder="1" oninput="calcularTotalPerdidaMerma()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.95rem; font-weight:700;">
                            </div>
                        </div>

                        <div style="background:#fef2f2; border:1px solid #fecaca; padding:10px 14px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:0.85rem; color:#991b1b; font-weight:600;">Total Pérdida Valorizada:</span>
                            <strong id="mermaTotalCalculadoUSD" style="font-size:1.15rem; color:#dc2626;">$0.00 USD</strong>
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo / Detalles de la Pérdida *</label>
                            <textarea id="mermaMotivo" required rows="3" placeholder="Describe brevemente la causa (ej. empaque roto durante traslado, vencimiento de lote, insumo para demostración...)" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                            <button type="button" class="btn-secondary" onclick="cerrarModalRegistrarMerma()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                            <button type="submit" class="btn-primary" style="padding:11px 22px; background:#dc2626; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar y Descontar Stock</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <!-- Modal Anular Pérdida -->
        <div id="modalAnularMerma" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
            <div class="admin-card" style="width:100%; max-width:520px; background:var(--bg-card, #fff); padding:28px; border-radius:16px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
                <button type="button" onclick="cerrarModalAnularMerma()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>

                <h3 style="margin-top:0; margin-bottom:8px; font-size:1.25rem; display:flex; align-items:center; gap:8px; color:#b45309;">
                    ↩️ Anular Pérdida #<span id="anularMermaId">0</span>
                </h3>
                <p style="margin-top:0; margin-bottom:18px; font-size:0.85rem; color:#666;">
                    Esta acción devolverá las unidades al inventario y marcará el registro como <strong>Anulado</strong>. El registro original <em>no se borra</em>, queda como evidencia con el motivo y el usuario que anuló.
                </p>

                <div id="anularMermaResumen" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 14px; border-radius:8px; font-size:0.85rem; margin-bottom:18px; line-height:1.7;">
                </div>

                <form onsubmit="confirmarAnulacionMerma(event)">
                    <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo de la anulación *</label>
                    <textarea id="anularMermaMotivo" required rows="3" placeholder="Ej. se descontaron 3 unidades de más, la cantidad correcta era 1..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; margin-bottom:18px;"></textarea>

                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="btn-secondary" onclick="cerrarModalAnularMerma()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                        <button type="submit" class="btn-primary" style="padding:11px 22px; background:#b45309; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar Anulación</button>
                    </div>
                </form>
            </div>
        </div>

    <!-- Modal Registrar Devolución -->
    <div id="modalRegistrarDevolucion" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:flex-start; padding:30px 10px; overflow-y:auto;">
        <div class="admin-card" style="width:100%; max-width:680px; background:var(--bg-card, #fff); padding:28px; border-radius:16px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <button type="button" onclick="cerrarModalRegistrarDevolucion()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>

            <h3 style="margin-top:0; margin-bottom:8px; font-size:1.3rem; display:flex; align-items:center; gap:8px; color:#7e22ce;">
                ↩️ Registrar Devolución y Reembolso
            </h3>
            <p style="margin-top:0; margin-bottom:20px; font-size:0.85rem; color:#666;">
                La mercancía vuelve al inventario y el monto se reembolsa usando el precio de la venta original.
            </p>

            <form onsubmit="guardarDevolucion(event)" id="formDevolucion">
                <div style="display:flex; flex-direction:column; gap:14px;">

                    <!-- Paso 1: elegir venta -->
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Venta a devolver *</label>
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="devolucionBuscarVenta" placeholder="🔍 Buscar por #venta, cédula o nombre del cliente..." style="flex:1; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem;">
                            <button type="button" onclick="buscarVentasDevolubles()" style="padding:11px 16px; background:#8e44ad; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; white-space:nowrap;">Buscar</button>
                        </div>
                        <select id="devolucionVentaId" onchange="seleccionarVentaDevolucion()" style="width:100%; margin-top:8px; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff;">
                            <option value="">-- Busca y selecciona una venta --</option>
                        </select>
                        <div id="devolucionListaVentas" style="display:none; margin-top:8px; max-height:220px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;"></div>
                    </div>

                    <!-- Paso 2: productos -->
                    <div id="devolucionDetalleBox" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; padding:14px; border-radius:10px;">
                        <div id="devolucionVentaResumen" style="font-size:0.85rem; color:#475569; line-height:1.7; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;"></div>
                        <div class="table-responsive">
                            <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                                <thead>
                                    <tr style="background:#f1f5f9;">
                                        <th style="text-align:left; padding:7px; border-radius:4px;">Producto</th>
                                        <th style="text-align:center; padding:7px;">Vendidas</th>
                                        <th style="text-align:center; padding:7px;">Devueltas</th>
                                        <th style="text-align:center; padding:7px;">Pendiente</th>
                                        <th style="text-align:center; padding:7px;">A devolver</th>
                                        <th style="text-align:right; padding:7px;">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody id="devolucionItemsBody"></tbody>
                            </table>
                        </div>
                        <div id="devolucionTodoDevuelto" style="display:none; margin-top:10px; padding:10px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; font-size:0.82rem; color:#92400e;">
                            ⚠️ Esta venta ya no tiene mercancía por devolver.
                        </div>
                    </div>

                    <!-- Paso 3: reembolso -->
                    <div id="devolucionCamposPago" style="display:none; flex-direction:column; gap:14px;">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div>
                                <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Método de Reembolso *</label>
                                <select id="devolucionMetodo" required onchange="actualizarResumenDevolucion()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff;">
                                    <option value="efectivo">💵 Efectivo (sale del cajón)</option>
                                    <option value="pago_movil">📱 Pago Móvil (transferencia de vuelta)</option>
                                    <option value="transferencia">🏦 Transferencia bancaria</option>
                                    <option value="credito">🎫 Crédito para próxima compra</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Total a devolver</label>
                                <div id="devolucionTotalCalculado" style="width:100%; padding:11px; background:#f3e8ff; border:1px solid #d8b4fe; border-radius:8px; font-size:1rem; font-weight:800; color:#7e22ce;">
                                    $0.00 USD
                                </div>
                            </div>
                        </div>

                        <div id="devolucionAvisoEfectivo" style="display:none; background:#fff7ed; border:1px solid #fed7aa; border-left:5px solid #f97316; padding:11px 14px; border-radius:8px; font-size:0.82rem; color:#7c2d12; line-height:1.6;">
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo de la devolución *</label>
                            <textarea id="devolucionMotivo" required rows="2" placeholder="Ej. el cliente lo devolvió dañado, no le gustó el modelo, lo compró por error..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                        </div>

                        <div>
                            <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Notas internas (opcional)</label>
                            <textarea id="devolucionNotas" rows="2" placeholder="Solo para consulta interna del personal..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                        <button type="button" class="btn-secondary" onclick="cerrarModalRegistrarDevolucion()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                        <button type="submit" id="devolucionBtnGuardar" style="padding:11px 22px; background:#8e44ad; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar Devolución</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Anular Devolución -->
    <div id="modalAnularDevolucion" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
        <div class="admin-card" style="width:100%; max-width:540px; background:var(--bg-card, #fff); padding:28px; border-radius:16px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <button type="button" onclick="cerrarModalAnularDevolucion()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>

            <h3 style="margin-top:0; margin-bottom:8px; font-size:1.25rem; display:flex; align-items:center; gap:8px; color:#b45309;">
                ↩️ Anular Devolución #<span id="anularDevolucionId">0</span>
            </h3>
            <p style="margin-top:0; margin-bottom:18px; font-size:0.85rem; color:#666;">
                El registro <em>no se borra</em>: queda como evidencia con el motivo y el usuario que anuló.
                Se deshace el reingreso de la mercancía al inventario.
            </p>

            <div id="anularDevolucionResumen" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 14px; border-radius:8px; font-size:0.85rem; margin-bottom:18px; line-height:1.7;">
            </div>

            <form onsubmit="confirmarAnulacionDevolucion(event)">
                <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo de la anulación *</label>
                <textarea id="anularDevolucionMotivo" required rows="3" placeholder="Ej. se registró la devolución por error, el cliente no devolvió el producto..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; margin-bottom:18px;"></textarea>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalAnularDevolucion()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                    <button type="submit" class="btn-primary" style="padding:11px 22px; background:#b45309; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar Anulación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Historial de Registro de Productos -->
    <div id="modalHistorialProductos" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
        <div class="admin-card" style="width:100%; max-width:800px; max-height:85vh; overflow-y:auto; background: var(--bg-card, #fff); padding: 28px; border-radius: 14px; position:relative; box-shadow:0 8px 40px rgba(0,0,0,0.18);">
            <button type="button" onclick="cerrarModalHistorialProductos()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
            <h3 style="margin-top:0; margin-bottom:20px; font-size:1.2rem;">📅 Historial de Ingreso de Productos</h3>
            
            <div class="table-responsive">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th>Precio Venta</th>
                            <th>Stock Inicial</th>
                            <th>Fecha y Hora de Ingreso</th>
                        </tr>
                    </thead>
                    <tbody id="historialProductosBody">
                        <tr><td colspan="6" style="text-align:center; color:#aaa; padding:20px;">Cargando historial...</td></tr>
                    </tbody>
                </table>
            </div>
            
            <div style="display:flex; justify-content:flex-end; margin-top:20px;">
                <button type="button" class="btn-secondary" onclick="cerrarModalHistorialProductos()" style="padding:10px 22px;">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Registrar Devolución a Proveedor -->
    <div id="modalRegistrarDevolucionProveedor" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
        <div class="admin-card" style="width:100%; max-width:920px; max-height:90vh; overflow-y:auto; background:var(--bg-card, #fff); padding:26px; border-radius:14px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <button type="button" onclick="cerrarModalRegistrarDevolucionProveedor()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
            <h3 style="margin-top:0; margin-bottom:8px; font-size:1.25rem; display:flex; align-items:center; gap:8px;">↩️ Registrar Devolución a Proveedor</h3>
            <p style="margin-top:0; margin-bottom:18px; font-size:0.85rem; color:#666;">Selecciona una compra con mercancía pendiente de devolver. El stock se restará al confirmar.</p>

            <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:16px;">
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="text" id="devolucionProvBuscarCompra" placeholder="🔍 Buscar por #compra, #factura o proveedor..." style="flex:1; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem;">
                    <button type="button" class="btn-secondary" onclick="buscarComprasDevolubles()" style="padding:11px 16px; border-radius:8px;">Buscar</button>
                </div>
                <select id="devolucionProvCompraId" onchange="seleccionarCompraDevolucion()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff;">
                    <option value="">-- Selecciona una compra pendiente de devolución --</option>
                </select>
                <div id="devolucionProvListaCompras" style="display:none; margin-top:6px; max-height:200px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;"></div>
            </div>

            <div id="devolucionProvDetalleBox" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; padding:14px; border-radius:10px; margin-bottom:16px;">
                <div id="devolucionProvCompraResumen" style="font-size:0.85rem; color:#475569; line-height:1.7; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e2e8f0;"></div>
                <div class="table-responsive">
                    <table class="inventory-table" style="font-size:0.85rem;">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Comprado</th>
                                <th>Ya Devuelto</th>
                                <th>Pendiente</th>
                                <th>Precio Unit. (USD)</th>
                                <th>Cant. a Devolver</th>
                                <th>Subtotal (USD)</th>
                            </tr>
                        </thead>
                        <tbody id="devolucionProvItemsBody"></tbody>
                    </table>
                </div>
                <div id="devolucionProvTodoDevuelto" style="display:none; margin-top:10px; padding:10px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; font-size:0.82rem; color:#92400e;">Todo lo pendiente ya fue devuelto para esta compra.</div>
            </div>

            <div id="devolucionProvCamposPago" style="display:none; flex-direction:column; gap:14px;">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Método de Reembolso *</label>
                        <select id="devolucionProvMetodo" required onchange="actualizarResumenDevolucionProveedor()" style="width:100%; padding:11px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; background:#fff;">
                            <option value="nota_credito" selected>Nota de Crédito</option>
                            <option value="efectivo">Efectivo</option>
                            <option value="pago_movil">Pago Móvil</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="otros">Otros</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Total a Devolver (USD)</label>
                        <div id="devolucionProvTotalCalculado" style="width:100%; padding:11px; background:#f3e8ff; border:1px solid #d8b4fe; border-radius:8px; font-size:1rem; font-weight:800; color:#7e22ce;">$0.00</div>
                    </div>
                </div>

                <div>
                    <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo de la devolución *</label>
                    <textarea id="devolucionProvMotivo" required rows="2" placeholder="Ej. producto defectuoso, sobre recepción, acuerdo con proveedor..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                </div>

                <div>
                    <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Notas (Internas)</label>
                    <textarea id="devolucionProvNotas" rows="2" placeholder="Solo para consulta interna..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end;">
                    <button type="button" id="devolucionProvBtnGuardar" onclick="guardarDevolucionProveedor()" style="padding:11px 22px; background:#8e44ad; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar Devolución a Proveedor</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Anular Devolución a Proveedor -->
    <div id="modalAnularDevolucionProveedor" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
        <div class="admin-card" style="width:100%; max-width:520px; background:var(--bg-card, #fff); padding:24px; border-radius:14px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <button type="button" onclick="cerrarModalAnularDevolucionProveedor()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
            <h3 style="margin-top:0; margin-bottom:8px; font-size:1.2rem; color:#b45309;">⚠️ Anular Devolución a Proveedor</h3>
            <p style="margin-top:0; margin-bottom:14px; font-size:0.85rem; color:#666;">El registro no se borra. Se deshace el ajuste de stock (la mercancía vuelve al inventario).</p>
            <div id="anularDevolucionProvResumen" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 14px; border-radius:8px; font-size:0.85rem; margin-bottom:16px; line-height:1.7;"></div>
            <form onsubmit="confirmarAnulacionDevolucionProveedor(event)">
                <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo de la anulación *</label>
                <textarea id="anularDevolucionProvMotivo" required rows="3" placeholder="Ej. se registró por error, se revirtió el acuerdo..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; margin-bottom:16px;"></textarea>
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalAnularDevolucionProveedor()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                    <button type="submit" class="btn-primary" style="padding:11px 22px; background:#b45309; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar Anulación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Registrar Abono Apartado -->
    <div id="modalRegistrarAbonoApartado" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
        <div class="admin-card" style="width:100%; max-width:540px; background:var(--bg-card, #fff); padding:26px; border-radius:14px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <button type="button" onclick="cerrarModalRegistrarAbonoApartado()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
            <h3 style="margin-top:0; margin-bottom:6px; font-size:1.25rem; display:flex; align-items:center; gap:8px; color:#15803d;">
                💵 Registrar Abono de Apartado
            </h3>
            <p style="margin-top:0; margin-bottom:16px; font-size:0.85rem; color:#666;">Registra un pago parcial o total para este apartado.</p>

            <div id="abonoApartadoResumen" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 14px; border-radius:10px; font-size:0.85rem; margin-bottom:16px; line-height:1.6;">
            </div>

            <form onsubmit="guardarAbonoApartado(event)" style="display:flex; flex-direction:column; gap:14px;">
                <input type="hidden" id="abonoApartadoId">
                <input type="hidden" id="abonoApartadoSaldoPendiente">

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Monto del Abono (USD) *</label>
                        <input type="number" id="abonoMontoUSD" step="0.01" min="0.01" required oninput="calcularEquivalenteAbonoBs()" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:1rem; font-weight:700;">
                    </div>
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Equivalente en Bs</label>
                        <input type="text" id="abonoMontoBsCalculado" readonly style="width:100%; padding:10px; border:1px solid #e5e7eb; background:#f9fafb; border-radius:8px; font-size:0.95rem; color:#475569;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Método de Pago *</label>
                        <select id="abonoMetodoPago" required style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem; background:#fff;">
                            <option value="efectivo_usd">💵 Efectivo USD</option>
                            <option value="efectivo_bs">💵 Efectivo Bs</option>
                            <option value="pago_movil" selected>📱 Pago Móvil</option>
                            <option value="transferencia">🏦 Transferencia</option>
                            <option value="zelle">💳 Zelle</option>
                            <option value="punto_venta">💳 Punto de Venta</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Referencia / Comprobante</label>
                        <input type="text" id="abonoReferencia" placeholder="Ej. 123456" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;">
                    </div>
                </div>

                <div>
                    <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Notas / Observaciones</label>
                    <input type="text" id="abonoNotas" placeholder="Opcional..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:6px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalRegistrarAbonoApartado()" style="padding:11px 20px; border-radius:8px;">Cancelar</button>
                    <button type="submit" id="btnGuardarAbono" class="btn-primary" style="padding:11px 22px; background:#15803d; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">💾 Registrar Abono</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Cancelar Apartado -->
    <div id="modalCancelarApartado" class="modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; display:none; justify-content:center; align-items:center;">
        <div class="admin-card" style="width:100%; max-width:520px; background:var(--bg-card, #fff); padding:24px; border-radius:14px; position:relative; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <button type="button" onclick="cerrarModalCancelarApartado()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
            <h3 style="margin-top:0; margin-bottom:8px; font-size:1.2rem; color:#b91c1c;">⚠️ Cancelar Apartado</h3>
            <p style="margin-top:0; margin-bottom:14px; font-size:0.85rem; color:#666;">Al cancelar el apartado, la venta pasa a estado cancelado y la mercancía reservada puede devolverse al inventario.</p>
            <div id="cancelarApartadoResumen" style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px 14px; border-radius:8px; font-size:0.85rem; margin-bottom:16px; line-height:1.6;"></div>
            
            <form onsubmit="confirmarCancelacionApartado(event)">
                <input type="hidden" id="cancelarApartadoId">
                <div style="margin-bottom:14px;">
                    <label style="display:flex; align-items:center; gap:8px; font-size:0.88rem; font-weight:600; cursor:pointer;">
                        <input type="checkbox" id="cancelarReingresarStock" checked style="cursor:pointer; width:18px; height:18px;">
                        Reingresar la mercancía apartada al inventario (liberar stock)
                    </label>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-weight:700; font-size:0.85rem; margin-bottom:5px;">Motivo de la cancelación *</label>
                    <textarea id="cancelarApartadoMotivo" required rows="2" placeholder="Ej. vencimiento de plazo, solicitud del cliente..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88rem;"></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="btn-secondary" onclick="cerrarModalCancelarApartado()" style="padding:11px 20px; border-radius:8px;">Volver</button>
                    <button type="submit" id="btnConfirmarCancelacionAp" class="btn-primary" style="padding:11px 22px; background:#b91c1c; color:white; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Confirmar Cancelación</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notificación Toast -->
    <div id="notification-toast"></div>

    <script src="admin.js?v=<?php echo time(); ?>"></script>
    <script src="historial.js?v=<?php echo time(); ?>"></script>
    <script src="../assets/js/toggle-password.js?v=<?php echo time(); ?>"></script>
    <script src="../assets/js/validaciones.js?v=1.0"></script>
    <script src="../assets/js/manual.js?v=1.0"></script>
</body>
</html>
