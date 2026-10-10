<?php
/**
 * Verificación y auto-reparación del esquema de la base de datos.
 * Garantiza que todas las tablas y columnas requeridas existan en la BD.
 */

function asegurarEsquemaBD($pdo) {
    try {
        // 1. Tabla productos
        $pdo->exec("CREATE TABLE IF NOT EXISTS productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(255) NOT NULL,
            descripcion TEXT,
            precio_compra DECIMAL(10,2) DEFAULT 0.00,
            precio_usd DECIMAL(10,2) NOT NULL,
            iva_alicuota DECIMAL(5,2) NOT NULL DEFAULT 16.00,
            stock INT DEFAULT 0,
            stock_minimo INT DEFAULT 0,
            destacado TINYINT(1) DEFAULT 0,
            publicado TINYINT(1) DEFAULT 1,
            categoria VARCHAR(100) DEFAULT 'General',
            subcategoria VARCHAR(100) DEFAULT NULL,
            marca VARCHAR(100) DEFAULT NULL,
            imagen VARCHAR(255) DEFAULT '',
            imagenes TEXT DEFAULT NULL,
            ficha_tecnica VARCHAR(255) DEFAULT NULL,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migración: la marca del producto se elige al publicarlo.
        try {
            $colsProd = $pdo->query("SELECT column_name FROM information_schema.COLUMNS
                                      WHERE table_schema = DATABASE()
                                        AND table_name = 'productos'")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($colsProd) && !in_array('marca', $colsProd, true)) {
                $pdo->exec("ALTER TABLE productos ADD COLUMN marca VARCHAR(100) NULL AFTER subcategoria");
            }

            // IVA: precio_usd es el P.V.P. (con IVA incluido). La alicuota permite
            // desglosar base e IVA al vender. 0 = exento / exonerado.
            if (!empty($colsProd) && !in_array('iva_alicuota', $colsProd, true)) {
                $pdo->exec("ALTER TABLE productos ADD COLUMN iva_alicuota DECIMAL(5,2) NOT NULL DEFAULT 16.00 AFTER precio_usd");
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // 2. Tabla administrador
        $pdo->exec("CREATE TABLE IF NOT EXISTS administrador (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            nombre_completo VARCHAR(200),
            email VARCHAR(255),
            telefono VARCHAR(50),
            negocio_nombre VARCHAR(255) DEFAULT NULL,
            negocio_telefono VARCHAR(50) DEFAULT NULL,
            negocio_horario VARCHAR(255) DEFAULT NULL,
            negocio_ubicacion VARCHAR(255) DEFAULT NULL,
            pm_banco VARCHAR(100) DEFAULT NULL,
            pm_telefono VARCHAR(50) DEFAULT NULL,
            pm_cedula VARCHAR(50) DEFAULT NULL,
            transf_banco VARCHAR(100) DEFAULT NULL,
            transf_cuenta VARCHAR(100) DEFAULT NULL,
            transf_titular VARCHAR(150) DEFAULT NULL,
            transf_cedula VARCHAR(50) DEFAULT NULL,
            zelle_correo VARCHAR(120) DEFAULT NULL,
            zelle_titular VARCHAR(150) DEFAULT NULL,
            biopago_banco VARCHAR(100) DEFAULT NULL,
            biopago_cedula VARCHAR(50) DEFAULT NULL,
            biopago_titular VARCHAR(150) DEFAULT NULL,
            -- Datos fiscales (IVA / IGTF), solo para control interno
            negocio_rif VARCHAR(20) DEFAULT NULL,
            direccion_fiscal VARCHAR(255) DEFAULT NULL,
            iva_incluido TINYINT(1) NOT NULL DEFAULT 1,
            iva_general_pct DECIMAL(5,2) NOT NULL DEFAULT 16.00,
            igtf_pct DECIMAL(5,2) NOT NULL DEFAULT 3.00,
            es_agente_retencion TINYINT(1) NOT NULL DEFAULT 0,
            retencion_iva_pct DECIMAL(5,2) NOT NULL DEFAULT 75.00,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. Tabla clientes
        $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            nombre_completo VARCHAR(200) NOT NULL,
            cedula VARCHAR(50) DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            telefono VARCHAR(50),
            direccion TEXT,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Tabla proveedores
        $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre_negocio VARCHAR(255) NOT NULL,
            nombre VARCHAR(100),
            apellido VARCHAR(100),
            documento_identidad VARCHAR(50),
            email VARCHAR(100),
            telefono VARCHAR(50),
            direccion TEXT,
            banco VARCHAR(100),
            cuenta_bancaria VARCHAR(100),
            telefono_pago_movil VARCHAR(50),
            tipo_producto VARCHAR(100) DEFAULT NULL,
            marca_producto VARCHAR(255) DEFAULT NULL,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 5. Tabla compras
        $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proveedor_id INT,
            numero_factura VARCHAR(100),
            fecha_factura DATE,
            moneda ENUM('USD', 'BS') DEFAULT 'USD',
            impuestos_pct DECIMAL(5,2) DEFAULT 0,
            descuento_total DECIMAL(10,2) DEFAULT 0,
            total_usd DECIMAL(10,2) NOT NULL,
            base_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            iva_credito_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            retencion_iva_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            igtf_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_bs DECIMAL(10,2) NOT NULL,
            tasa_cambio DECIMAL(10,2) NOT NULL,
            fecha_compra TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_recepcion DATETIME,
            estado ENUM('Pendiente', 'Recibido', 'Cancelado') DEFAULT 'Recibido',
            usuario_admin_id INT,
            FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 6. Tabla compra_detalles
        $pdo->exec("CREATE TABLE IF NOT EXISTS compra_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            producto_id INT,
            codigo_producto VARCHAR(100),
            nombre_producto VARCHAR(255) NOT NULL,
            marca_producto VARCHAR(100) DEFAULT NULL,
            cantidad INT NOT NULL,
            precio_unitario DECIMAL(10,2) NOT NULL,
            impuesto_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            descuento_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subtotal_usd DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migracion: guarda la marca con la que se compro cada linea.
        try {
            $colsDet = $pdo->query("SELECT column_name, character_maximum_length
                                      FROM information_schema.COLUMNS
                                     WHERE table_schema = DATABASE()
                                       AND table_name = 'compra_detalles'")->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($colsDet)) {
                $ancho = null;
                foreach ($colsDet as $c) {
                    if ($c['column_name'] === 'marca_producto') {
                        $ancho = (int)$c['character_maximum_length'];
                    }
                }

                if ($ancho === null) {
                    $pdo->exec("ALTER TABLE compra_detalles ADD COLUMN marca_producto VARCHAR(100) NULL AFTER nombre_producto");
                } elseif ($ancho < 100) {
                    $pdo->exec("ALTER TABLE compra_detalles MODIFY COLUMN marca_producto VARCHAR(100) NULL DEFAULT NULL");
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: IVA crédito fiscal en compras. El precio_unitario y el
        // subtotal de compra_detalles son NETOS (costo sin IVA); el IVA va aparte
        // para no inflar el costo ni el margen en get_stats / historial.
        try {
            $colsCompras = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                        WHERE TABLE_SCHEMA = DATABASE()
                                          AND TABLE_NAME = 'compras'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsCompras)) {
                $nuevasCompras = [
                    'base_usd'          => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'iva_credito_usd'   => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'retencion_iva_usd' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'igtf_usd'          => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                ];
                foreach ($nuevasCompras as $col => $def) {
                    if (!in_array($col, $colsCompras, true)) {
                        $pdo->exec("ALTER TABLE compras ADD COLUMN $col $def");
                    }
                }
            }

            $colsCompraDet = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                          WHERE TABLE_SCHEMA = DATABASE()
                                            AND TABLE_NAME = 'compra_detalles'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsCompraDet)) {
                $nuevasCompraDet = [
                    'impuesto_unitario'  => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'descuento_unitario' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                ];
                foreach ($nuevasCompraDet as $col => $def) {
                    if (!in_array($col, $colsCompraDet, true)) {
                        $pdo->exec("ALTER TABLE compra_detalles ADD COLUMN $col $def");
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // 7. Tabla tasa_dolar
        $pdo->exec("CREATE TABLE IF NOT EXISTS tasa_dolar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tasa DECIMAL(10,2) NOT NULL,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 8. Tabla ventas
        $pdo->exec("CREATE TABLE IF NOT EXISTS ventas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT,
            cliente_nombre VARCHAR(200),
            cliente_cedula VARCHAR(50),
            cliente_telefono VARCHAR(50),
            total_usd DECIMAL(10,2) NOT NULL,
            tasa_cambio DECIMAL(10,2) NOT NULL,
            total_bs DECIMAL(10,2) NOT NULL,
            base_gravada_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            iva_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            igtf_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            metodo_pago VARCHAR(50) DEFAULT 'pago_movil',
            referencia_pago VARCHAR(100),
            estado ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente') NOT NULL DEFAULT 'Pendiente Pago',
            fecha_venta TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 9. Tabla venta_detalles
        $pdo->exec("CREATE TABLE IF NOT EXISTS venta_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            venta_id INT NOT NULL,
            producto_id INT,
            nombre_producto VARCHAR(255) NOT NULL,
            cantidad INT NOT NULL,
            precio_unitario_usd DECIMAL(10,2) NOT NULL,
            base_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            iva_alicuota DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            iva_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            costo_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subtotal_usd DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 10. Tabla pagos
        $pdo->exec("CREATE TABLE IF NOT EXISTS pagos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            venta_id INT NOT NULL,
            metodo_pago ENUM('pago_movil', 'transferencia', 'zelle', 'biopago', 'punto_venta') NOT NULL,
            banco_origen VARCHAR(100) NOT NULL,
            telefono_origen VARCHAR(20),
            referencia VARCHAR(100) NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            receipt_path VARCHAR(500) NULL,
            correccion_mensaje TEXT NULL,
            fecha_pago TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            estado ENUM('pendiente', 'en_verificacion', 'validado', 'rechazado') DEFAULT 'en_verificacion',
            FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 11. Tabla abastecimientos
        $pdo->exec("CREATE TABLE IF NOT EXISTS abastecimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT,
            nombre_producto VARCHAR(255) NOT NULL,
            cantidad INT NOT NULL,
            precio_compra_unitario DECIMAL(10,2) NOT NULL,
            precio_venta_unitario DECIMAL(10,2) NOT NULL,
            fecha_abastecimiento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Tabla pedidos (pedidos de reposición al proveedor)
        $pdo->exec("CREATE TABLE IF NOT EXISTS pedidos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proveedor_id INT DEFAULT NULL,
            estado ENUM('pendiente','enviado','recibido','cancelado') NOT NULL DEFAULT 'pendiente',
            observaciones TEXT,
            fecha_pedido TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            usuario_admin_id INT DEFAULT NULL,
            FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pedido_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pedido_id INT NOT NULL,
            producto_id INT DEFAULT NULL,
            nombre_producto VARCHAR(255) NOT NULL,
            cantidad INT NOT NULL DEFAULT 1,
            unidad_medida ENUM('unidad','caja','paquete') NOT NULL DEFAULT 'unidad',
            precio_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 12. Tabla favoritos
        $pdo->exec("CREATE TABLE IF NOT EXISTS favoritos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            producto_id INT NOT NULL,
            fecha_agregado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY cliente_producto (cliente_id, producto_id),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 18. Tabla cajas_turnos (Cierre de Caja y Arqueos)
        $pdo->exec("CREATE TABLE IF NOT EXISTS cajas_turnos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_admin_id INT DEFAULT NULL,
            fecha_apertura TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_cierre TIMESTAMP NULL DEFAULT NULL,
            ventas_efectivo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ventas_efectivo_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ventas_pago_movil_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ventas_transferencia_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ventas_zelle_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            ventas_biopago_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            egresos_caja_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_esperado_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_conteo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            diferencia_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tasa_cambio DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            estado ENUM('ABIERTA', 'CERRADA') NOT NULL DEFAULT 'ABIERTA',
            observaciones TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 19. Tabla mermas_perdidas (Salidas No Operativas: Dañados, Robados, Extraviados, Vencidos, Uso Interno)
        $pdo->exec("CREATE TABLE IF NOT EXISTS mermas_perdidas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            producto_id INT NOT NULL,
            cantidad INT NOT NULL,
            tipo_salida ENUM('DAÑADO', 'ROBADO', 'EXTRAVIADO', 'VENCIDO', 'USO_INTERNO') NOT NULL DEFAULT 'DAÑADO',
            costo_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            costo_total_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            motivo TEXT NULL,
            usuario_id INT NULL,
            fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
            anulada_por INT NULL,
            fecha_anulacion TIMESTAMP NULL DEFAULT NULL,
            motivo_anulacion TEXT NULL,
            asiento_reversion INT NULL,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migración: agregar las columnas de anulación si la tabla ya existía sin ellas
        try {
            $colsMermas = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                       WHERE TABLE_SCHEMA = DATABASE()
                                         AND TABLE_NAME = 'mermas_perdidas'")->fetchAll(PDO::FETCH_COLUMN);

            $altersMermas = [
                'estado'            => "ADD COLUMN estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA' AFTER fecha_registro",
                'anulada_por'       => "ADD COLUMN anulada_por INT NULL AFTER estado",
                'fecha_anulacion'   => "ADD COLUMN fecha_anulacion TIMESTAMP NULL DEFAULT NULL AFTER anulada_por",
                'motivo_anulacion'  => "ADD COLUMN motivo_anulacion TEXT NULL AFTER fecha_anulacion",
                'asiento_reversion' => "ADD COLUMN asiento_reversion INT NULL AFTER motivo_anulacion",
            ];
            foreach ($altersMermas as $col => $sqlAlter) {
                if (!in_array($col, $colsMermas, true)) {
                    $pdo->exec("ALTER TABLE mermas_perdidas $sqlAlter");
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // 20. Tabla devoluciones (Devoluciones y reembolsos de mercaderia)
        $pdo->exec("CREATE TABLE IF NOT EXISTS devoluciones (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 21. Tabla devolucion_detalles (Mercaderia devuelta, con snapshot del precio)
        $pdo->exec("CREATE TABLE IF NOT EXISTS devolucion_detalles (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 21b. Tabla devoluciones_proveedor (Devoluciones a proveedores)
        $pdo->exec("CREATE TABLE IF NOT EXISTS devoluciones_proveedor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            fecha_devolucion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            tipo ENUM('TOTAL','PARCIAL') NOT NULL DEFAULT 'PARCIAL',
            estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
            metodo_reembolso ENUM('efectivo','pago_movil','transferencia','nota_credito','otros') NOT NULL DEFAULT 'nota_credito',
            monto_devuelto_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            monto_devuelto_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tasa_cambio DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            motivo TEXT NULL,
            notas TEXT NULL,
            usuario_admin_id INT DEFAULT NULL,
            anulada_por INT DEFAULT NULL,
            fecha_anulacion TIMESTAMP NULL DEFAULT NULL,
            motivo_anulacion TEXT NULL,
            KEY (compra_id),
            KEY (fecha_devolucion),
            KEY (estado),
            FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 21c. Tabla devolucion_proveedor_detalles (Mercaderia devuelta al proveedor)
        $pdo->exec("CREATE TABLE IF NOT EXISTS devolucion_proveedor_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            devolucion_proveedor_id INT NOT NULL,
            compra_detalle_id INT NOT NULL,
            producto_id INT DEFAULT NULL,
            nombre_producto VARCHAR(255) NOT NULL,
            cantidad INT NOT NULL,
            precio_unitario_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subtotal_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            KEY (devolucion_proveedor_id),
            KEY (compra_detalle_id),
            KEY (producto_id),
            FOREIGN KEY (devolucion_proveedor_id) REFERENCES devoluciones_proveedor(id) ON DELETE CASCADE,
            FOREIGN KEY (compra_detalle_id) REFERENCES compra_detalles(id) ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migracion: reembolsos de efectivo restados del arqueo.
        // Sin esta columna, un reembolso en efectivo haria que el cierre de caja
        // reportara un sobrante inexistente.
        try {
            $colsCaja = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = DATABASE()
                                       AND TABLE_NAME = 'cajas_turnos'")->fetchAll(PDO::FETCH_COLUMN);

            if (!in_array('reembolsos_efectivo_usd', $colsCaja, true)) {
                $pdo->exec("ALTER TABLE cajas_turnos
                            ADD COLUMN reembolsos_efectivo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00
                            AFTER egresos_caja_usd");
            }

            // Conteos y diferencias por método de pago al cierre
            $addsCaja = [
                'conteo_efectivo_usd'      => "ADD COLUMN conteo_efectivo_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_conteo_usd",
                'conteo_pago_movil_usd'    => "ADD COLUMN conteo_pago_movil_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER conteo_efectivo_usd",
                'conteo_transferencia_usd' => "ADD COLUMN conteo_transferencia_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER conteo_pago_movil_usd",
                'conteo_zelle_usd'         => "ADD COLUMN conteo_zelle_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER conteo_transferencia_usd",
                'conteo_biopago_usd'       => "ADD COLUMN conteo_biopago_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER conteo_zelle_usd",
                'conteo_punto_venta_usd'   => "ADD COLUMN conteo_punto_venta_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER conteo_biopago_usd",
                'conteo_otros_usd'         => "ADD COLUMN conteo_otros_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER conteo_punto_venta_usd",
                'dif_pago_movil_usd'       => "ADD COLUMN dif_pago_movil_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER diferencia_usd",
                'dif_transferencia_usd'    => "ADD COLUMN dif_transferencia_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dif_pago_movil_usd",
                'dif_zelle_usd'            => "ADD COLUMN dif_zelle_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dif_transferencia_usd",
                'dif_biopago_usd'          => "ADD COLUMN dif_biopago_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dif_zelle_usd",
                'dif_punto_venta_usd'      => "ADD COLUMN dif_punto_venta_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dif_biopago_usd",
                'dif_otros_usd'            => "ADD COLUMN dif_otros_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER dif_punto_venta_usd",
            ];
            foreach ($addsCaja as $col => $sqlAlter) {
                if (!in_array($col, $colsCaja, true)) {
                    $pdo->exec("ALTER TABLE cajas_turnos $sqlAlter");
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: asegurar columna nombre_negocio y otras en proveedores
        try {
            $colsProv = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = DATABASE()
                                       AND TABLE_NAME = 'proveedores'")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($colsProv)) {
                if (!in_array('nombre_negocio', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN nombre_negocio VARCHAR(255) NOT NULL DEFAULT '' AFTER id");
                    if (in_array('nombre', $colsProv, true)) {
                        $pdo->exec("UPDATE proveedores SET nombre_negocio = nombre WHERE nombre_negocio = '' OR nombre_negocio IS NULL");
                    }
                }
                if (!in_array('apellido', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN apellido VARCHAR(100) NULL AFTER nombre");
                }
                if (!in_array('documento_identidad', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN documento_identidad VARCHAR(50) NULL AFTER apellido");
                }
                if (!in_array('banco', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN banco VARCHAR(100) NULL");
                }
                if (!in_array('cuenta_bancaria', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN cuenta_bancaria VARCHAR(100) NULL");
                }
                if (!in_array('telefono_pago_movil', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN telefono_pago_movil VARCHAR(50) NULL");
                }
                if (!in_array('tipo_producto', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN tipo_producto VARCHAR(100) NULL AFTER telefono_pago_movil");
                }
                if (!in_array('marca_producto', $colsProv, true)) {
                    $pdo->exec("ALTER TABLE proveedores ADD COLUMN marca_producto VARCHAR(255) NULL AFTER tipo_producto");
                } else {
                    // Guarda la lista de marcas (SanDisk, Kingston, HP): 255 antes que 100.
                    $anchoMarca = (int)($pdo->query("SELECT character_maximum_length FROM information_schema.COLUMNS
                                                      WHERE table_schema = DATABASE()
                                                        AND table_name = 'proveedores'
                                                        AND column_name = 'marca_producto'")->fetchColumn() ?: 0);
                    if ($anchoMarca > 0 && $anchoMarca < 255) {
                        $pdo->exec("ALTER TABLE proveedores MODIFY COLUMN marca_producto VARCHAR(255) NULL DEFAULT NULL");
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: asegurar columna tasa_cambio y otras en ventas
        try {
            $colsVentas = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                       WHERE TABLE_SCHEMA = DATABASE()
                                         AND TABLE_NAME = 'ventas'")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($colsVentas)) {
                if (!in_array('tasa_cambio', $colsVentas, true)) {
                    $pdo->exec("ALTER TABLE ventas ADD COLUMN tasa_cambio DECIMAL(10,2) NOT NULL DEFAULT 50.00 AFTER total_usd");
                    if (in_array('tasa_dolar', $colsVentas, true)) {
                        $pdo->exec("UPDATE ventas SET tasa_cambio = tasa_dolar WHERE tasa_dolar IS NOT NULL AND tasa_dolar > 0");
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: desglose de IVA en ventas (precios P.V.P. con IVA incluido)
        try {
            $colsVentasIva = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                          WHERE TABLE_SCHEMA = DATABASE()
                                            AND TABLE_NAME = 'ventas'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsVentasIva)) {
                $nuevasVentas = [
                    'base_gravada_usd' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'iva_usd'          => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'igtf_usd'         => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                ];
                foreach ($nuevasVentas as $col => $def) {
                    if (!in_array($col, $colsVentasIva, true)) {
                        $pdo->exec("ALTER TABLE ventas ADD COLUMN $col $def");
                    }
                }
            }

            $colsVentaDet = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                         WHERE TABLE_SCHEMA = DATABASE()
                                           AND TABLE_NAME = 'venta_detalles'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsVentaDet)) {
                $nuevasVentaDet = [
                    'base_usd'     => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'iva_alicuota' => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00',
                    'iva_usd'      => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                ];
                foreach ($nuevasVentaDet as $col => $def) {
                    if (!in_array($col, $colsVentaDet, true)) {
                        $pdo->exec("ALTER TABLE venta_detalles ADD COLUMN $col $def");
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: métodos de pago Zelle y Biopago
        try {
            $colsAdmin = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'administrador'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsAdmin)) {
                $nuevasAdmin = [
                    'zelle_correo'    => 'VARCHAR(120) DEFAULT NULL',
                    'zelle_titular'   => 'VARCHAR(150) DEFAULT NULL',
                    'biopago_banco'   => 'VARCHAR(100) DEFAULT NULL',
                    'biopago_cedula'  => 'VARCHAR(50) DEFAULT NULL',
                    'biopago_titular' => 'VARCHAR(150) DEFAULT NULL',
                    'negocio_rif'         => 'VARCHAR(20) DEFAULT NULL',
                    'direccion_fiscal'    => 'VARCHAR(255) DEFAULT NULL',
                    'iva_incluido'        => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'iva_general_pct'     => 'DECIMAL(5,2) NOT NULL DEFAULT 16.00',
                    'igtf_pct'            => 'DECIMAL(5,2) NOT NULL DEFAULT 3.00',
                    'es_agente_retencion' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'retencion_iva_pct'   => 'DECIMAL(5,2) NOT NULL DEFAULT 75.00',
                ];
                foreach ($nuevasAdmin as $col => $def) {
                    if (!in_array($col, $colsAdmin, true)) {
                        $pdo->exec("ALTER TABLE administrador ADD COLUMN $col $def");
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        try {
            $colsCaja = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cajas_turnos'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsCaja)) {
                if (!in_array('ventas_efectivo_bs', $colsCaja, true)) {
                    $pdo->exec("ALTER TABLE cajas_turnos ADD COLUMN ventas_efectivo_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ventas_efectivo_usd");
                }
                if (!in_array('ventas_zelle_usd', $colsCaja, true)) {
                    $pdo->exec("ALTER TABLE cajas_turnos ADD COLUMN ventas_zelle_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ventas_transferencia_usd");
                }
                if (!in_array('ventas_biopago_usd', $colsCaja, true)) {
                    $pdo->exec("ALTER TABLE cajas_turnos ADD COLUMN ventas_biopago_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ventas_zelle_usd");
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: unidad de medida en los items de pedido (unidad/caja/paquete)
        try {
            $colsPedidoItems = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_items'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsPedidoItems) && !in_array('unidad_medida', $colsPedidoItems, true)) {
                $pdo->exec("ALTER TABLE pedido_items ADD COLUMN unidad_medida ENUM('unidad','caja','paquete') NOT NULL DEFAULT 'unidad' AFTER cantidad");
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        try {
            $pdo->exec("ALTER TABLE pagos MODIFY COLUMN metodo_pago ENUM('pago_movil','transferencia','zelle','biopago','punto_venta') NOT NULL");
        } catch (Exception $e) {
            // Silencioso si la tabla pagos todavía no existe
        }

        // Asegurar tablas de autenticacion si no existen
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS administrador (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                nombre_completo VARCHAR(100),
                email VARCHAR(100),
                telefono VARCHAR(20),
                fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                negocio_nombre VARCHAR(255),
                negocio_telefono VARCHAR(50),
                negocio_horario VARCHAR(255),
                negocio_ubicacion VARCHAR(255),
                pm_banco VARCHAR(100),
                pm_telefono VARCHAR(50),
                pm_cedula VARCHAR(50),
                transf_banco VARCHAR(100),
                transf_cuenta VARCHAR(100),
                transf_titular VARCHAR(150),
                transf_cedula VARCHAR(50),
                zelle_correo VARCHAR(120),
                zelle_titular VARCHAR(150),
                biopago_banco VARCHAR(100),
                biopago_cedula VARCHAR(50),
                biopago_titular VARCHAR(150)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                nombre_completo VARCHAR(100),
                email VARCHAR(100),
                telefono VARCHAR(20),
                fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}

        // 22. Tabla apartados (Sistema de Apartados con tiempo límite)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS apartados (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venta_id INT NOT NULL,
                usuario_id INT NULL,
                cliente_nombre VARCHAR(200),
                cliente_cedula VARCHAR(50),
                cliente_telefono VARCHAR(50),
                total_usd DECIMAL(10,2) NOT NULL,
                total_bs DECIMAL(10,2) NOT NULL,
                tasa_cambio DECIMAL(10,2) NOT NULL,
                monto_minimo_inicial_usd DECIMAL(10,2) NOT NULL,
                porcentaje_inicial DECIMAL(5,2) DEFAULT 30.00,
                monto_abonado_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                monto_abonado_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                monto_pendiente_usd DECIMAL(10,2) NOT NULL,
                fecha_apartado DATETIME DEFAULT CURRENT_TIMESTAMP,
                fecha_limite DATE NOT NULL,
                dias_limite INT DEFAULT 15,
                cuotas INT NULL DEFAULT NULL,
                estado ENUM('Activo', 'Completado', 'Vencido', 'Cancelado') NOT NULL DEFAULT 'Activo',
                fecha_completado DATETIME NULL,
                fecha_cancelacion DATETIME NULL,
                motivo_cancelacion TEXT NULL,
                notas TEXT NULL,
                KEY (venta_id),
                KEY (usuario_id),
                KEY (estado),
                KEY (fecha_limite),
                FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 23. Tabla apartado_abonos (Historial de abonos/pagos parciales)
            $pdo->exec("CREATE TABLE IF NOT EXISTS apartado_abonos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                apartado_id INT NOT NULL,
                monto_usd DECIMAL(10,2) NOT NULL,
                monto_bs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                tasa_cambio DECIMAL(10,2) NOT NULL,
                metodo_pago VARCHAR(50) NOT NULL,
                referencia VARCHAR(100) NULL,
                comprobante_img VARCHAR(500) NULL,
                fecha_abono DATETIME DEFAULT CURRENT_TIMESTAMP,
                usuario_admin_id INT NULL,
                notas TEXT NULL,
                KEY (apartado_id),
                FOREIGN KEY (apartado_id) REFERENCES apartados(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Asegurar que ventas.estado acepte 'Apartado'
            $stmtColV = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'estado'");
            $colV = $stmtColV->fetch();
            $typeV = $colV['type'] ?? $colV['Type'] ?? '';
            if ($typeV && !str_contains($typeV, 'Apartado')) {
                $pdo->exec("ALTER TABLE ventas MODIFY COLUMN estado ENUM('Pendiente Pago','Pago Enviado','Pago Validado','Preparando','Entregado','Cancelado','Pendiente','Apartado') NOT NULL DEFAULT 'Pendiente Pago'");
            }
        } catch (Exception $e) {
            error_log("Error creando tablas de apartados: " . $e->getMessage());
        }

        // Migración: configuración global de apartados que edita el admin
        // (número de cuotas a pagar y días límite del plazo).
        try {
            $colsAdminCfg = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'administrador'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsAdminCfg)) {
                $colsNuevasAdmin = [
                    'apartado_cuotas'      => 'INT NOT NULL DEFAULT 3',
                    'apartado_dias_limite' => 'INT NOT NULL DEFAULT 15',
                ];
                foreach ($colsNuevasAdmin as $col => $def) {
                    if (!in_array($col, $colsAdminCfg, true)) {
                        $pdo->exec("ALTER TABLE administrador ADD COLUMN $col $def");
                    }
                }
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // Migración: cada apartado guarda las cuotas pactadas al momento de
        // crearse, para que un cambio posterior de la configuración global no
        // altere el plan de pagos de los apartados ya existentes.
        try {
            $colsApartados = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'apartados'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($colsApartados) && !in_array('cuotas', $colsApartados, true)) {
                $pdo->exec("ALTER TABLE apartados ADD COLUMN cuotas INT NULL DEFAULT NULL AFTER dias_limite");
            }
        } catch (Exception $e) {
            // Silencioso si no es posible alterar
        }

        // 24. Tabla kardex_inventario (Historial de movimientos de stock)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS kardex_inventario (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {
            error_log("Error creando tabla kardex_inventario: " . $e->getMessage());
        }

        // 24b. Migracion: Ampliar las columnas de referencia del Kardex (por si
        // la tabla ya existia de una version anterior con menos campos).
        try {
            $colsKardex = $pdo->query("SELECT column_name FROM information_schema.columns
                                        WHERE table_schema = DATABASE()
                                          AND table_name = 'kardex_inventario'")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($colsKardex)) {
                $columnasKardex = [
                    'documento_tipo'       => 'VARCHAR(50) NULL',
                    'numero_referencia'    => 'VARCHAR(100) NULL',
                    'costo_unitario_usd'   => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'costo_total_usd'      => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'compra_id'            => 'INT NULL',
                    'compra_detalle_id'    => 'INT NULL',
                    'venta_id'             => 'INT NULL',
                    'venta_detalle_id'     => 'INT NULL',
                    'merma_id'             => 'INT NULL',
                    'devolucion_id'        => 'INT NULL',
                    'devolucion_proveedor_id' => 'INT NULL',
                    'usuario_id'           => 'INT NULL',
                    'observaciones'        => 'VARCHAR(255) NULL',
                ];
                foreach ($columnasKardex as $col => $definicion) {
                    if (!in_array($col, $colsKardex, true)) {
                        $pdo->exec("ALTER TABLE kardex_inventario ADD COLUMN {$col} {$definicion}");
                    }
                }

                // El concepto debe admitir los tipos de movimiento actuales.
                $tipoConcepto = (string)$pdo->query("SELECT column_type FROM information_schema.columns
                                                     WHERE table_schema = DATABASE()
                                                       AND table_name = 'kardex_inventario'
                                                       AND column_name = 'concepto'")->fetchColumn();
                if ($tipoConcepto && !str_contains($tipoConcepto, 'AJUSTE_INVENTARIO')) {
                    $pdo->exec("ALTER TABLE kardex_inventario MODIFY COLUMN concepto ENUM(
                        'COMPRA','ABASTECIMIENTO','AJUSTE_INVENTARIO','VENTA',
                        'DEVOLUCION_VENTA','ANULACION_VENTA',
                        'DEVOLUCION_PROVEEDOR','ANULACION_DEVOLUCION_PROVEEDOR',
                        'MERMA','ANULACION_MERMA') NOT NULL");
                }
            }
        } catch (Exception $e) {
            error_log("Error migrando kardex_inventario: " . $e->getMessage());
        }

    // 25. Recuperacion de clave por preguntas de seguridad.
        // Catalogo de preguntas + columnas pregunta/respuesta en ambas
        // tablas de cuentas. Es self-service: no necesita correo ni SMTP.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS preguntas_seguridad (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pregunta VARCHAR(255) NOT NULL UNIQUE,
                activa TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $preguntas = [
                '¿Cuál fue el nombre de tu primera mascota?',
                '¿En qué ciudad naciste?',
                '¿Cuál es el nombre de tu madre?',
                '¿Cuál fue tu primer trabajo?',
                '¿Cómo se llama tu mejor amigo de la infancia?',
                '¿Cuál era el nombre de tu escuela primaria?',
                '¿Qué marca de teléfono usabas antes?',
                '¿Cuál es tu comida favorita?',
                '¿Cuál fue el nombre de tu primer carro o bicicleta?',
                '¿Qué película o serie veías de niño?',
            ];
            $stmtPregunta = $pdo->prepare("INSERT IGNORE INTO preguntas_seguridad (pregunta) VALUES (?)");
            foreach ($preguntas as $pregunta) {
                $stmtPregunta->execute([$pregunta]);
            }

            // Cada tabla de cuentas guarda su propia pregunta y su respuesta
            // hasheada (nunca en texto plano).
            $columnasSeguridad = [
                'pregunta_seguridad_id' => 'INT NULL DEFAULT NULL',
                'respuesta_seguridad'   => 'VARCHAR(255) NULL DEFAULT NULL',
            ];

            foreach (['administrador', 'clientes'] as $tablaCuentas) {
                // $tablaCuentas viene de una lista fija de arriba, no de entrada del usuario.
                $colsCuentas = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                            WHERE TABLE_SCHEMA = DATABASE()
                                              AND TABLE_NAME = '$tablaCuentas'")->fetchAll(PDO::FETCH_COLUMN);

                if (empty($colsCuentas)) continue;

                foreach ($columnasSeguridad as $columna => $definicion) {
                    if (!in_array($columna, $colsCuentas, true)) {
                        $pdo->exec("ALTER TABLE $tablaCuentas ADD COLUMN $columna $definicion");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error creando tabla de preguntas de seguridad: " . $e->getMessage());
        }

    } catch (Exception $e) {
        error_log("Error en asegurarEsquemaBD: " . $e->getMessage());
    }
}
