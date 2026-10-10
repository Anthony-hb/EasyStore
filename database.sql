-- =============================================================================
--  Tienda Digital "Tecnology" - Estructura de base de datos
--  Motor: MySQL / MariaDB (probado en MariaDB 10.4)
--  Charset: utf8mb4
--
--  Este archivo es la fuente de verdad del esquema. Está sincronizado con
--  includes/schema.php, que además aplica migraciones idempotentes en tiempo
--  de ejecución por si la base ya existe y está desactualizada.
--
--  IMPORTANTE sobre admin/index.php, auth/* y api/*:
--    el administrador se crea mediante admin_setup.php o setup.php, NO aquí.
--    Tampoco se insertan usuarios ni productos reales: este script es solo
--    estructura + datos de base (tasa inicial del dólar).
-- =============================================================================

CREATE DATABASE IF NOT EXISTS tienda CHARACTER SET utf8mb4;
USE tienda;

-- El orden no importa gracias a esto, pero se mantiene agrupado por módulos.
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
--  CATÁLOGO
-- =============================================================================

CREATE TABLE IF NOT EXISTS productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    precio_usd DECIMAL(10, 2) NOT NULL,
    iva_alicuota DECIMAL(5, 2) NOT NULL DEFAULT 16.00, -- 0 = exento/exonerado
    precio_compra DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    stock_minimo INT NOT NULL DEFAULT 0,
    destacado TINYINT(1) DEFAULT 0,
    publicado TINYINT(1) DEFAULT 0,
    categoria VARCHAR(100) NOT NULL,
    subcategoria VARCHAR(100),
    marca VARCHAR(100) DEFAULT NULL, -- marca del producto (SanDisk, Kingston, HP...)
    imagen TEXT, -- URL absoluta o ruta relativa dentro de uploads/
    imagenes TEXT, -- JSON con URLs de imágenes adicionales
    ficha_tecnica TEXT,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS favoritos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    producto_id INT NOT NULL,
    fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY cliente_producto (cliente_id, producto_id),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasa_dolar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tasa DECIMAL(10, 2) NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
--  USUARIOS
-- =============================================================================

CREATE TABLE IF NOT EXISTS administrador (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- hash de password_hash()
    nombre_completo VARCHAR(100),
    email VARCHAR(100),
    telefono VARCHAR(20),
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Datos del negocio (editables desde "Mi Cuenta")
    negocio_nombre VARCHAR(255),
    negocio_telefono VARCHAR(50),
    negocio_horario VARCHAR(255),
    negocio_ubicacion VARCHAR(255),
    -- Pago móvil
    pm_banco VARCHAR(100),
    pm_telefono VARCHAR(50),
    pm_cedula VARCHAR(50),
    -- Transferencia bancaria
    transf_banco VARCHAR(100),
    transf_cuenta VARCHAR(100),
    transf_titular VARCHAR(150),
    transf_cedula VARCHAR(50),
    -- Datos fiscales (IVA / IGTF), solo para control interno
    negocio_rif VARCHAR(20) DEFAULT NULL,
    direccion_fiscal VARCHAR(255) DEFAULT NULL,
    iva_incluido TINYINT(1) NOT NULL DEFAULT 1,
    iva_general_pct DECIMAL(5,2) NOT NULL DEFAULT 16.00,
    igtf_pct DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    es_agente_retencion TINYINT(1) NOT NULL DEFAULT 0,
    retencion_iva_pct DECIMAL(5,2) NOT NULL DEFAULT 75.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL, -- hash de password_hash()
    nombre_completo VARCHAR(100),
    cedula VARCHAR(50),
    email VARCHAR(100),
    telefono VARCHAR(20),
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
--  COMPRAS A PROVEEDORES
-- =============================================================================

CREATE TABLE IF NOT EXISTS proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_negocio VARCHAR(255) NOT NULL,
    nombre VARCHAR(100),
    apellido VARCHAR(100),
    documento_identidad VARCHAR(50),
    email VARCHAR(100),
    telefono VARCHAR(20),
    direccion VARCHAR(255),
    banco VARCHAR(100),
    cuenta_bancaria VARCHAR(100),
    telefono_pago_movil VARCHAR(20),
    tipo_producto VARCHAR(100) DEFAULT NULL,
    marca_producto VARCHAR(255) DEFAULT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proveedor_id INT NOT NULL,
    numero_factura VARCHAR(100),
    fecha_factura DATE NULL,
    moneda ENUM('USD','BS') DEFAULT 'USD',
    impuestos_pct DECIMAL(5,2) DEFAULT 0.00,
    descuento_total DECIMAL(10,2) DEFAULT 0.00,
    total_usd DECIMAL(10, 2) NOT NULL,
    base_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,           -- subtotal neto (sin IVA)
    iva_credito_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,    -- IVA recuperable
    retencion_iva_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    igtf_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,           -- IGTF pagado a proveedor (divisas)
    total_bs DECIMAL(10, 2) NOT NULL,
    tasa_cambio DECIMAL(10, 2) NOT NULL,
    fecha_compra TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_recepcion TIMESTAMP NULL,
    estado ENUM('Pendiente', 'Recibido', 'Anulado') NOT NULL DEFAULT 'Pendiente',
    usuario_admin_id INT NULL,
    observaciones TEXT,
    KEY (proveedor_id),
    KEY (usuario_admin_id),
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE RESTRICT,
    FOREIGN KEY (usuario_admin_id) REFERENCES administrador(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compra_detalles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compra_id INT NOT NULL,
    producto_id INT DEFAULT NULL,
    codigo_producto VARCHAR(100) DEFAULT NULL,
    nombre_producto VARCHAR(255) DEFAULT NULL,
    marca_producto VARCHAR(100) DEFAULT NULL,
    lote VARCHAR(100) DEFAULT NULL,
    serie VARCHAR(100) DEFAULT NULL,
    cantidad INT NOT NULL,
    precio_unitario DECIMAL(10, 2) NOT NULL,
    impuesto_unitario DECIMAL(10,2) DEFAULT 0.00,
    descuento_unitario DECIMAL(10,2) DEFAULT 0.00,
    subtotal_usd DECIMAL(10, 2) NOT NULL,
    KEY (compra_id),
    KEY (producto_id),
    FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
--  VENTAS Y PAGOS
-- =============================================================================

CREATE TABLE IF NOT EXISTS ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    total_usd DECIMAL(10, 2) NOT NULL,
    total_bs DECIMAL(10, 2) NOT NULL,
    base_gravada_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00, -- venta sin IVA
    iva_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,          -- IVA cobrado
    igtf_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,         -- solo cobros en divisas
    tasa_cambio DECIMAL(10, 2) NOT NULL,
    usuario_id INT,
    fecha_venta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente') NOT NULL DEFAULT 'Pendiente Pago',
    KEY (usuario_id),
    FOREIGN KEY (usuario_id) REFERENCES clientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS venta_detalles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    producto_id INT DEFAULT NULL,
    nombre_producto VARCHAR(255) NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario_usd DECIMAL(10, 2) NOT NULL,
    base_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    iva_alicuota DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    iva_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    costo_unitario_usd DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    subtotal_usd DECIMAL(10, 2) NOT NULL,
    KEY (venta_id),
    KEY (producto_id),
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    metodo_pago ENUM('pago_movil', 'transferencia') NOT NULL,
    banco_origen VARCHAR(100) NOT NULL,
    telefono_origen VARCHAR(20),
    referencia VARCHAR(100) NOT NULL,
    monto DECIMAL(10, 2) NOT NULL,
    receipt_path VARCHAR(500) NULL,
    correccion_mensaje TEXT NULL,
    fecha_pago TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('pendiente', 'en_verificacion', 'validado', 'rechazado') DEFAULT 'en_verificacion',
    KEY (venta_id),
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
--  INVENTARIO: MERMAS / PÉRDIDAS
-- =============================================================================

-- Salidas no operativas (pérdidas y mermas).
-- No se borran al anular: pasan a estado ANULADA conservando la evidencia,
-- junto con el motivo y el usuario que anuló.
CREATE TABLE IF NOT EXISTS mermas_perdidas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    tipo_salida ENUM('DAÑADO','ROBADO','EXTRAVIADO','VENCIDO','USO_INTERNO') NOT NULL DEFAULT 'DAÑADO',
    costo_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    costo_total_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    motivo TEXT NULL,
    usuario_id INT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
    anulada_por INT NULL,
    fecha_anulacion TIMESTAMP NULL DEFAULT NULL,
    motivo_anulacion TEXT NULL,
    asiento_reversion INT NULL, -- asiento contable inverso generado al anular
    KEY (producto_id),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abastecimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT DEFAULT NULL,
    nombre_producto VARCHAR(255) NOT NULL,
    cantidad INT NOT NULL,
    precio_compra_unitario DECIMAL(10, 2) NOT NULL,
    precio_venta_unitario DECIMAL(10, 2) NOT NULL,
    fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cajas_turnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_admin_id INT DEFAULT NULL,
    fecha_apertura TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre TIMESTAMP NULL DEFAULT NULL,
    ventas_efectivo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ventas_efectivo_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ventas_pago_movil_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ventas_transferencia_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    egresos_caja_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reembolsos_efectivo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_esperado_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_conteo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    diferencia_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tasa_cambio DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    estado ENUM('ABIERTA', 'CERRADA') NOT NULL DEFAULT 'ABIERTA',
    observaciones TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
--  DEVOLUCIONES Y REEMBOLSOS
-- =============================================================================

-- Una devolucion es el acto de recibir de vuelta mercaderia y devolver (o
-- dejar a credito) el dinero. Una venta puede tener varias devoluciones
-- parciales; solo se marca TOTAL cuando queda sin unidades por devolver.
--
-- No se borra al anular: pasa a estado ANULADA conservando la evidencia,
-- el motivo y el usuario que anulo (mismo criterio que mermas_perdidas).
--
-- El saldo pendiente de cada linea de venta se calcula restando las
-- devoluciones ACTIVAS, por eso no hace falta marcar cada fila.
CREATE TABLE IF NOT EXISTS devoluciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    fecha_devolucion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    tipo ENUM('TOTAL','PARCIAL') NOT NULL DEFAULT 'PARCIAL',
    estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
    metodo_reembolso ENUM('efectivo','pago_movil','transferencia','credito') NOT NULL DEFAULT 'efectivo',
    monto_devuelto_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monto_devuelto_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tasa_cambio DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    motivo TEXT NULL,
    notas TEXT NULL,
    usuario_admin_id INT DEFAULT NULL,
    anulada_por INT DEFAULT NULL,
    fecha_anulacion TIMESTAMP NULL DEFAULT NULL,
    motivo_anulacion TEXT NULL,
    KEY (venta_id),
    KEY (fecha_devolucion),
    KEY (estado),
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Detalle de la mercaderia devuelta. Guarda copia del nombre y del precio
-- del momento de la venta (snapshot), igual que venta_detalles.
CREATE TABLE IF NOT EXISTS devolucion_detalles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    devolucion_id INT NOT NULL,
    venta_detalle_id INT NOT NULL,
    producto_id INT DEFAULT NULL,
    nombre_producto VARCHAR(255) NOT NULL,
    cantidad INT NOT NULL,
    precio_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    subtotal_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    KEY (devolucion_id),
    KEY (venta_detalle_id),
    FOREIGN KEY (devolucion_id) REFERENCES devoluciones(id) ON DELETE CASCADE,
    FOREIGN KEY (venta_detalle_id) REFERENCES venta_detalles(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kardex de Inventario: historial de entradas y salidas de cada producto.
-- productos.stock guarda el saldo actual; esta tabla guarda COMO se llego a ese
-- saldo. La escribe includes/kardex.php en cada operación que mueve stock.
CREATE TABLE IF NOT EXISTS kardex_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT DEFAULT NULL,
    nombre_producto VARCHAR(255) NOT NULL,
    fecha_movimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    tipo_movimiento ENUM('ENTRADA','SALIDA') NOT NULL,
    concepto ENUM(
        'COMPRA',
        'ABASTECIMIENTO',
        'AJUSTE_INVENTARIO',
        'VENTA',
        'DEVOLUCION_VENTA',
        'ANULACION_VENTA',
        'DEVOLUCION_PROVEEDOR',
        'ANULACION_DEVOLUCION_PROVEEDOR',
        'MERMA',
        'ANULACION_MERMA'
    ) NOT NULL,
    documento_tipo VARCHAR(50) NULL,
    documento_id INT NULL,
    numero_referencia VARCHAR(100) NULL,
    cantidad_entrada INT NOT NULL DEFAULT 0,
    cantidad_salida INT NOT NULL DEFAULT 0,
    stock_anterior INT NOT NULL DEFAULT 0,
    stock_nuevo INT NOT NULL DEFAULT 0,
    costo_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    costo_total_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    compra_id INT NULL,
    compra_detalle_id INT NULL,
    venta_id INT NULL,
    venta_detalle_id INT NULL,
    merma_id INT NULL,
    devolucion_id INT NULL,
    devolucion_proveedor_id INT NULL,
    usuario_id INT NULL,
    observaciones VARCHAR(255) NULL,
    KEY idx_kardex_producto (producto_id),
    KEY idx_kardex_fecha (fecha_movimiento),
    KEY idx_kardex_concepto (concepto),
    KEY idx_kardex_tipo (tipo_movimiento),
    KEY idx_kardex_compra (compra_id),
    KEY idx_kardex_venta (venta_id),
    KEY idx_kardex_merma (merma_id),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
--  DATOS BASE (idempotentes: se pueden reejecutar sin duplicar)
-- =============================================================================

-- Tasa de cambio inicial, solo si la tabla está vacía
INSERT INTO tasa_dolar (tasa)
SELECT 50.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tasa_dolar);

-- =============================================================================
--  PASOS SIGUIENTES TRAS IMPORTAR
-- =============================================================================
--  1. Crear el administrador:  http://localhost/tienda/admin_setup.php
--     (o setup.php, que además crea el esquema automáticamente)
--  2. Verificar credenciales en includes/db.php
--  3. Cargar productos desde el panel, sección "Inventario"
--  4. Ajustar la tasa del dólar, sección "Tasa de Cambio"