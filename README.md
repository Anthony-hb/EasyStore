# EasyStore – Sistema de Punto de Venta y Gestión de Tienda

Sistema de catálogo digital con carrito de compras, punto de venta presencial y
panel de administración. PHP + MySQL/MariaDB (PDO) + JavaScript vanilla, sin frameworks.

> **Identidad del software:** **EasyStore** (v1.0.0) es el nombre propio del sistema.
> El nombre del **negocio** (por defecto `Tecnology` en este instalador) es configurable
> desde el panel: **Mi Cuenta → Información del Negocio**. Las constantes viven en
> `includes/db.php` (`SISTEMA_NOMBRE`, `SISTEMA_VERSION`, `SISTEMA_TAGLINE`).

## Requisitos

- Apache y MySQL/MariaDB ejecutándose (XAMPP u otro servidor local)
- PHP 8.0 o superior (probado en PHP 8.2)

## Instalación

1. **Base de datos**
   - Crear la base `tienda` e importar `database.sql`:
     ```
     mysql -u root -p < database.sql
     ```
   - `database.sql` contiene toda la estructura (**16 tablas**) y la tasa de cambio
     inicial. Es **idempotente**: se puede reejecutar sin duplicar datos.
   - Alternativa: instalar en fresco con `setup.php`, que además crea el esquema
     automáticamente al abrirlo en el navegador.

2. **Administrador**
   - Abrir `http://localhost/tienda/admin_setup.php` y crear el usuario admin.
     (El login redirige a esta pantalla si no existe ningún administrador.)

3. **Configuración**
   - Verificar credenciales en `includes/db.php`.
   - `includes/schema.php` aplica migraciones idempotentes en cada arranque, por
     lo que una base desactualizada se actualiza sola al entrar al panel.

4. **Permisos de escritura**
   - Las carpetas `uploads/productos/` y `uploads/comprobantes/` deben ser
     escribibles por el usuario que corre Apache.

5. **Datos iniciales**
   - Cargar productos desde el panel, sección **Inventario**.
   - Ajustar la tasa del dólar en la sección **Tasa de Cambio**.

## Estructura

| Ruta | Contenido |
|------|-----------|
| `index.html` | Tienda pública: catálogo, carrito, pago |
| `assets/css/estilo.css` | Estilos (único CSS, compartido) |
| `assets/js/` | `catalogo.js`, `carrito.js`, `manual.js`, `validaciones.js` |
| `auth/` | `login.php`, `register.php`, `logout.php` |
| `api/` | Endpoints JSON del cliente |
| `admin/` | Panel: `index.php` + `admin.js` + `historial.js` + endpoints de administración |
| `customer/` | `mi_cuenta.php`, `mis_pedidos.php`, `mis_favoritos.php` |
| `includes/` | `db.php`, `sanitizer.php`, `schema.php`, `kardex.php` |
| `uploads/` | `productos/`, `comprobantes/` |
| `scratch/` | Scripts de mantenimiento (no se usan en producción) |

## Funcionalidades

### Cliente
- Navegar catálogo sin registro
- Agregar productos al carrito
- Login/registro requerido solo para finalizar compra
- Pago por pago móvil o transferencia con carga de comprobante
- Seguimiento de pedidos, favoritos y perfil

### Administrador
- Inventario: CRUD de productos, publicación, imágenes, alertas de stock
- Compras a proveedores y ventas presenciales
- Validación de pagos y cambio de estados de pedido
- Tasa de cambio
- **Pérdidas y mermas**: registro de salidas no operativas (dañado, robado,
  extraviado, vencido, uso interno) y **anulación** de mermas mal registradas
- **Historial de Clientes**: módulo único que reúne, por cliente, todas sus
  compras, apartados y devoluciones, con búsqueda y filtros por tipo y período.
  Desde ahí se registran ventas presenciales y devoluciones, se abonan, entregan
  o cancelan apartados, y se anulan devoluciones
- **Devoluciones a proveedores**: devolución de compras a proveedores, independiente
  de las ventas a clientes
- **Caja**: apertura y cierre de turno con arqueo
- Gestión de clientes

## Módulo de Historial de Clientes

Reemplaza a los antiguos módulos separados de Ventas, Apartados y Devoluciones.
La pantalla inicial lista todos los clientes con sus totales; al elegir uno se
abre su historial completo.

### Qué muestra

- **Compras**: ventas en cualquier estado, incluidas las no pagadas y las que
  quedaron en apartado. Las ventas en estado `Apartado` se contabilizan aparte,
  para no mostrar la misma deuda dos veces.
- **Apartados**: una tarjeta por apartado con el porcentaje pagado, la barra de
  progreso, el historial de abonos plegado y botones para **abonar**, **entregar**
  y **cancelar**.
- **Devoluciones**: solo las de ese cliente, con opción de **anular** una
  devolución activa.

### Acciones sobre el cliente

Desde el detalle se puede **registrar una venta presencial** (con el cliente ya
preseleccionado), **registrar una devolución** (acotada a sus compras) y descargar
el **reporte en PDF** o en **Excel**. Los reportes respetan los filtros que se
están viendo en pantalla.

### Filtros

Por tipo (todo, compras, apartados, devoluciones), por período (hoy, últimos 7,
30 o 90 días) y por texto libre.

## Devoluciones y reembolsos

Las devoluciones cubren tanto las ventas presenciales como los pedidos online, y
se registran desde el historial del cliente o desde el botón
**↩️ Registrar Devolución** de la barra de acciones. Solo entran ventas con
unidades pendientes de devolver y en estados que ya descontaron stock:
`Pago Validado`, `Preparando` y `Entregado`.

### Cómo funciona

1. Se busca la venta (por número, cédula o nombre del cliente). Solo aparecen las
   ventas con unidades pendientes de devolver, y solo en estados que ya
   descontaron stock: `Pago Validado`, `Preparando` y `Entregado`.
2. Se marca, línea por línea, cuántas unidades se reciben.
3. Se elige el método de reembolso y el motivo.
4. La mercancía vuelve al inventario y el monto se reembolsa **al precio de la
   venta original**, no al precio actual del producto.

La devolución se clasifica sola: `TOTAL` si cubre todas las unidades pendientes de
la venta, `PARCIAL` si solo cubre algunas.

### Los cuatro métodos de reembolso

| Método | ¿Sale dinero del cajón? |
|---|---|
| `efectivo` | **Sí.** Se descuenta del saldo esperado del turno de caja. |
| `pago_movil` | No. Se transfiere de vuelta por esa vía. |
| `transferencia` | No. Se transfiere de vuelta por esa vía. |
| `credito` | No. Queda como crédito del cliente para una próxima compra. |

Solo `efectivo` descuenta del arqueo. Los otros tres existen justamente para poder
registrar un reembolso real sin registrar una salida de efectivo que nunca
sucedió, lo que corrompería el saldo de caja.

### Anulación

Como las mermas, la anulación **no borra**: marca `estado = 'ANULADA'` y guarda
`anulada_por`, `fecha_anulacion` y `motivo_anulacion`. Saca del inventario las
unidades que la devolución había reingresado. Si el stock actual **no alcanza**
para hacerlo, la anulación se rechaza en vez de dejar el stock negativo.

### Endpoints

| Archivo | Función |
|---|---|
| `admin/get_ventas_devolubles.php` | Selector de ventas con unidades pendientes |
| `admin/get_items_venta_devolucion.php` | Líneas de una venta con su pendiente |
| `admin/save_devolucion.php` | Registra la devolución (transacción con `FOR UPDATE`) |
| `admin/anular_devolucion.php` | Anula la devolución y deshace el stock |

### Módulo de Contabilidad: retirado por completo

El módulo se eliminó en tres pasos: primero su interfaz, después su motor, y por
último sus tablas. **Ya no queda nada de él en el sistema.** No hay pantalla, no
hay `includes/contabilidad.php`, no hay endpoints propios y no existen las tablas
`contabilidad_asientos`, `contabilidad_asiento_detalles`, `contabilidad_cuentas`
ni `gastos_operativos`.

Lo que dejó de hacer cada módulo:

| Módulo | Antes | Ahora |
|---|---|---|
| Caja | Asiento `AJUSTE` por diferencia de arqueo; leía `gastos_operativos` | Solo registra el arqueo; el saldo esperado no descuenta gastos |

**Al reincorporar cualquier parte de este módulo hay que tocar `includes/schema.php`
y los módulos que lo usaban, no solo la interfaz.**

### Kardex de Inventario: reincorporado (sin contabilidad)

El **Kardex** sí volvió a existir, pero aislado de la contabilidad: es el historial
de entradas y salidas de stock, nada de doble partida.

`productos.stock` guarda el saldo actual; `kardex_inventario` guarda **cómo se
llegó a ese saldo**. Cada movimiento queda con su fecha, tipo, concepto,
documento que lo originó, cantidad, costo unitario y el saldo antes y después.

| Archivo | Función |
|---|---|
| `includes/kardex.php` | `aplicarMovimientoKardex()` actualiza el stock y asienta el movimiento en la misma operación; `registrarAjusteKardex()` para correcciones manuales; `kardexResumen()` para los KPI |
| `admin/get_kardex.php` | Listado con filtros (producto, fechas, concepto, tipo, texto) + resumen |
| `admin/save_ajuste_kardex.php` | Ajuste manual de inventario con motivo obligatorio |
| `includes/schema.php` | Crea `kardex_inventario` y la migra si ya existía con menos columnas |

**Todos los módulos que mueven stock pasan por `aplicarMovimientoKardex()`.** Si se
agrega una operación nueva que sume o reste stock, hay que pasar por ahí o el
Kardex queda desfasado del inventario.

| Operación | Movimiento |
|---|---|
| `save_compra.php` | `ENTRADA` / `COMPRA` |
| `save_venta_presencial.php` | `SALIDA` / `VENTA` |
| `validar_pago.php` | `SALIDA` / `VENTA` |
| `update_order_status.php` | `SALIDA` / `VENTA` al pasar a un estado validado, `ENTRADA` / `ANULACION_VENTA` al salir de él |
| `cancelar_apartado.php` | `ENTRADA` / `ANULACION_VENTA` |
| `save_devolucion.php` | `ENTRADA` / `DEVOLUCION_VENTA` |
| `anular_devolucion.php` | `SALIDA` / `ANULACION_VENTA` |
| `save_devolucion_proveedor.php` | `SALIDA` / `DEVOLUCION_PROVEEDOR` |
| `anular_devolucion_proveedor.php` | `ENTRADA` / `ANULACION_DEVOLUCION_PROVEEDOR` |
| `save_merma.php` | `SALIDA` / `MERMA` |
| `anular_merma.php` | `ENTRADA` / `ANULACION_MERMA` |

Tres reglas que `aplicarMovimientoKardex()` garantiza:

1. **Bloquea el producto con `FOR UPDATE`** antes de leer el saldo, para que el
   stock y el Kardex no se desincronicen bajo concurrencia.
2. **`estricto => true` lanza excepción si no hay stock.** Es lo que se usa en
   ventas, pagos y anulaciones. Sin esa bandera el saldo se topa en 0 y solo se
   asienta lo que había, con una nota en las observaciones.
3. **`cantidad` con signo decide el tipo**: positiva = `ENTRADA`, negativa = `SALIDA`.

Las devoluciones arman el movimiento en un arreglo durante el primer recorrido de
los ítems y lo aplican al final, cuando ya existe el id de la devolución: por eso
el Kardex puede trazarla completa (`devolucion_id` / `devolucion_proveedor_id`).

El historial arranca **vacío** aunque ya existan compras y ventas antiguas: el
Kardex se llena desde el momento en que se instaló. Para el pasado hay que usar
una carga inicial o un ajuste de inventario documentado.

## IVA y costos (control interno)

Los precios del catálogo (`productos.precio_usd`) son **P.V.P. con IVA incluido**.
Cada producto declara su `iva_alicuota` (16 general, 31 adicional = 16 + 15, 0
exento/exonerado). Al vender, el sistema desglosa la base y el IVA línea por línea:

```
base_unit = precio_usd / (1 + iva_alicuota/100)
iva_unit  = precio_usd - base_unit
```

`venta_detalles.precio_unitario_usd` y `subtotal_usd` siguen siendo el **bruto**
(lo que paga el cliente); `base_usd`, `iva_alicuota` e `iva_usd` son el desglose.
En la cabecera, `ventas.base_gravada_usd` + `ventas.iva_usd` = `ventas.total_usd`.
`ventas.igtf_usd` es informativo: solo se llena si el cobro es en divisas, no se
calcula solo.

En compras, `precio_unitario` y `subtotal_usd` de `compra_detalles` son **netos**
(costo sin IVA): el IVA va en `impuesto_unitario` y en `compras.iva_credito_usd`
(crédito fiscal). **Nunca cargar el IVA de compras al costo**: infla el COGS y
hunde el margen mostrado.

La ganancia (`get_stats.php`, historial de clientes) se calcula sobre la base,
porque el IVA cobrado no es ingreso sino un pasivo:

```
ganancia = venta_neta - costo_neto
```

En ventas anteriores a este cambio (`base_gravada_usd = 0`) se usa `total_usd`
como base para no romper el histórico: el margen viejo queda igual que antes.

`administrador` guarda los datos fiscales (`negocio_rif`, `direccion_fiscal`,
`iva_incluido`, `iva_general_pct`, `igtf_pct`, `es_agente_retencion`,
`retencion_iva_pct`), editables en **Mi Cuenta → Información del Negocio**.
Todo esto es **control interno**: no emite factura fiscal, no genera número de
control y no sustituye el libro de compras y ventas legal ni la declaración.

### Libro de IVA y cuadro débito/crédito (Fase 2)

La sección **🧮 Libro de IVA** del panel genera, para un rango de fechas, el
libro de ventas y el de compras con su cuadro resumen. El endpoint es
`admin/get_libro_iva.php?desde=YYYY-MM-DD&hasta=YYYY-MM-DD` (sin parámetros
toma el mes actual) y devuelve:

- **Libro de ventas**: cada venta no cancelada con `base_gravada_usd`,
  `iva_usd`, `igtf_usd` y `total_usd`. Las ventas previas al desglose de IVA
  aparecen con un `*` (sin datos de IVA reconstruibles).
- **Ventas por alícuota**: base e IVA agrupados por `venta_detalles.iva_alicuota`
  (16%, 31%, 0% exento/exonerado).
- **Libro de compras**: compras no anuladas con `base_usd`, `iva_credito_usd`,
  `retencion_iva_usd` e `igtf_usd`.
- **Cuadro débito/crédito**: `iva_neto = IVA débito − IVA crédito`. Si es
  positivo es IVA a pagar; si es negativo, crédito a favor.

El IGTF **no se calcula solo**: se carga a mano en el modal de venta presencial
o de compra solo cuando la operación se pagó/cobró en divisas. El campo
residencia en `ventas.igtf_usd` y `compras.igtf_usd`; `administrador.igtf_pct`
documenta la alícuota aplicable (3% por defecto) pero no dispara el cálculo.
`exportarTablaCSV()` e `imprimirReporteSeccion()` reutilizan el motor de reportes
existente para exportar el libro a Excel o PDF.

## Notas de diseño importantes

Estas reglas existen por bugs ya encontrados; no romperlas.

### Cómo probar el panel sin abrir el navegador

El panel se validó ejecutando el `admin.js` real en `jsdom` (Node), con el HTML
que devuelve el servidor y el `fetch` proxied al backend real. Eso prueba la
interfaz y los endpoints a la vez, y es como se detectaron bugs que las pruebas
por HTTP no veían.

```js
const dom = new JSDOM(htmlDelServidor, { url, runScripts: 'outside-only' });
win.fetch = async (url, opts) => fetch(url, { ...opts, headers: { ...opts.headers, Cookie } });
win.eval(fs.readFileSync('admin/admin.js', 'utf8'));
win.mostrarSeccionAdmin('devoluciones');
```

Antes de dar por buena cualquier cambio de interfaz: `php -l` en los `.php`,
`node --check admin.js`, y **recorrer el flujo en el DOM**, no solo comprobar que
los endpoints devuelven 200. Una referencia a un id mal escrito o un
`===` entre un string y un número no se ven desde curl.

### Sesión de prueba

Para curlar endpoints sin la contraseña del admin, se puede escribir a mano un
archivo de sesión en el directorio de sesiones de PHP:

```
usuario_id|i:4;rol|s:5:"admin";usuario_nombre|s:4:"Lore";
```

y enviar `curl.exe -b "PHPSESSID=<id>"`. Ojo: `Invoke-WebRequest` descarta las
cookies, hay que usar `curl.exe`.

- **La tabla `ventas` no tiene columna `metodo_pago`; el método de pago vive en
  `pagos`.** Y una venta **en efectivo no genera fila en `pagos`**:
  `save_venta_presencial.php` solo inserta un pago si el método es `pago_movil` o
  `transferencia`. Por eso el resumen de caja clasifica una venta como
  **efectivo por la ausencia de un pago validado**, no por un texto. Los
  summaries de caja (`get_caja_turno.php`, `save_caja_cierre.php`) usan un
  `LEFT JOIN` sobre `pagos` agregado con `GROUP BY venta_id`, para que una venta
  con varios pagos no se cuente dos veces. Nunca volver a leer `metodo_pago` de
  `ventas`: no existe y da error 1054.

- **`Sanitizer::int($valor, $min, $max, $default)`**: el segundo parámetro es el
  **mínimo**, no el valor por defecto. Pasar `Sanitizer::int($x, 1)` convierte un
  `0`, `null` o texto inválido en `1`, y la validación posterior nunca dispara.
  Para "cualquier entero, 0 si es inválido" use `Sanitizer::int($x)`.

- **Los endpoints de administración** (`admin/*.php`) siguen esta estructura:
  `session_start()` → `require '../includes/db.php'` → `require 'check_session.php'`
  → `requerirAdmin()` → `header('Content-Type: application/json')`.

- **`db.php` fuerza `PDO::CASE_LOWER`**, así que todas las columnas llegan en
  minúsculas a los `fetch()`.

- **Las devoluciones NO tocan `ventas.estado`.** Una venta puede tener devoluciones
  parciales y un solo estado no lo expresa. El pendiente por línea se **calcula**
  restando las devoluciones `ACTIVA` de `venta_detalles.cantidad`, sin columnas
  nuevas en `venta_detalles`. Por eso `update_order_status.php` sigue restaurando
  stock por su cuenta al cambiar de estado, y por eso los lectores de `ventas`
  (`get_stats.php`, caja, `mis_pedidos.php`) no se tocaron.
  No reintroducir esta lógica en el estado de la venta.

- **El monto a reembolsar sale del precio snapshot de `venta_detalles`**, nunca del
  precio actual del producto. Si el precio cambió después de la venta, el
  reembolso tiene que ser lo que el cliente pagó.

- **Solo la devolución en efectivo descuenta de caja.** El arqueo suma
  `devoluciones` con `metodo_reembolso = 'efectivo'`, `estado = 'ACTIVA'` y
  `fecha_devolucion >= cajas_turnos.fecha_apertura`. **Limitación conocida:** anular
  un reembolso después de cerrado el turno no reescribe el arqueo histórico de ese
  turno; solo afecta al turno abierto en el momento. Es intencional: el arqueo ya
  firmado es un hecho del pasado.

- **El saldo esperado puede quedar negativo, y es aritmética correcta.** Pasa
  cuando se reembolsa en efectivo una venta que el cliente pagó por pago móvil o
  transferencia: el dinero nunca entró al cajón y sí sale. El panel lo muestra con
  una advertencia roja que pide fondear el cajón, **no** como un error.

- **`db.php` usa `PDO::ATTR_EMULATE_PREPARES = 1`**, así que las columnas `INT`
  llegan como `integer` pero los `DECIMAL` como `string`. En el JavaScript,
  envolver siempre los importes en `parseFloat()` y comparar ids con `parseInt()`
  en lugar de igualdad estricta.

- **`Sanitizer::readJsonInput()` quita el BOM UTF-8.** Un BOM nunca es válido al
  inicio de un JSON (RFC 8259), así que `json_decode` fallaba siempre; quitarlo
  solo convierte un fallo en un éxito.

- **`La interfaz de Contabilidad se eliminó y con ella el motor y sus tablas`**
  (ver la sección anterior). No reincorporar llamadas desde JavaScript a
  funciones contables inexistentes: `guardarCierreCaja()` ya no refresca la
  contabilidad al cerrar turno, y `get_contabilidad.php`, `save_gasto.php` y
  `save_asiento_manual.php` fueron borrados. El Kardex sí existe, pero es un
  módulo independiente de inventario, no de contabilidad.

- **Nunca des la vuelta a `UPDATE productos SET stock = ...` para mover inventario.**
  Pasar por `aplicarMovimientoKardex()`: es lo único que garantiza que el saldo y
  el Kardex queden cuadrados. Un `UPDATE` a mano deja el historial mintiendo.

## Base de datos

### productos
- Información básica: nombre, descripcion, precio_usd (P.V.P. con IVA), precio_compra (neto), iva_alicuota, stock
- Categorización: categoria, subcategoria
- Multimedia: imagen, imagenes (JSON), ficha_tecnica

### administrador / clientes
- Autenticación: usuario, password (hash), rol (derivado en el login)
- Datos de contacto: nombre_completo, cedula, email, telefono
- El administrador además guarda los datos del negocio y de las cuentas de pago

### mermas_perdidas
- Salidas no operativas: tipo_salida (DAÑADO, ROBADO, EXTRAVIADO, VENCIDO, USO_INTERNO)
- Anulación sin borrado: estado, anulada_por, fecha_anulacion, motivo_anulacion
- La columna `asiento_reversion` quedó inútil al retirar el motor; se conserva por
  no tocar el esquema de una tabla con datos

### devoluciones
- Cabecera de la devolución: `venta_id`, `tipo` (TOTAL, PARCIAL), `estado`
  (ACTIVA, ANULADA), `metodo_reembolso` (efectivo, pago_movil, transferencia,
  credito), `motivo`, `notas`
- Importes en las dos monedas: `monto_devuelto_usd`, `monto_devuelto_bs`, más la
  `tasa_cambio` del momento (snapshot, como las ventas)
- Anulación sin borrado: `anulada_por`, `fecha_anulacion`, `motivo_anulacion`

### devolucion_detalles
- Una fila por línea de venta devuelta: `venta_detalle_id`, `cantidad`, y el
  precio snapshot `precio_unitario_usd` con su `subtotal_usd`
- `producto_id` es `ON DELETE SET NULL`: si el producto se borra del catálogo, el
  histórico de la devolución sobrevive

### cajas_turnos
- Además del arqueo, guarda `reembolsos_efectivo_usd`: el efectivo devuelto en
  ese turno. Es lo que hace auditable el saldo esperado
- `ventas_efectivo_usd` es el efectivo cobrado en divisas (método "Efectivo USD")
  y `ventas_efectivo_bs` el monto real cobrado con el método "Efectivo Bs", en
  bolívares. Este último no entra al arqueo en dólares del cajón

### kardex_inventario
- Un renglón por cada entrada o salida de stock, con `tipo_movimiento`
  (ENTRADA/SALIDA) y `concepto` (COMPRA, ABASTECIMIENTO, AJUSTE_INVENTARIO,
  VENTA, DEVOLUCION_VENTA, ANULACION_VENTA, DEVOLUCION_PROVEEDOR,
  ANULACION_DEVOLUCION_PROVEEDOR, MERMA, ANULACION_MERMA)
- El saldo de la fila: `cantidad_entrada`, `cantidad_salida`, `stock_anterior`,
  `stock_nuevo`. Es lo que permite auditar un producto línea por línea
- Trazabilidad: `documento_tipo`, `documento_id`, `numero_referencia` más las
  columnas de referencia (`compra_id`, `compra_detalle_id`, `venta_id`,
  `venta_detalle_id`, `merma_id`, `devolucion_id`, `devolucion_proveedor_id`)
- `nombre_producto` es una copia del nombre al momento del movimiento, no una
  referencia: el historial tiene que seguir siendo legible aunque el producto se
  renombre. Por eso `producto_id` es `ON DELETE SET NULL` y el nombre no se borra

## Seguridad

### Controles implementados
- Contraseñas hasheadas con `password_hash()` / verificadas con `password_verify()`
- Sesiones PHP con `session_regenerate_id()` en el login
- Validación de roles (`rol === 'admin'`) en operaciones administrativas
- Entrada saneada con la clase `Sanitizer` (`includes/sanitizer.php`)
- Salida escapada en el renderizado del panel con `escaparHTML()`
- `.htaccess` bloqueando los scripts de diagnóstico, migración e instalación que
  viven en la raíz, y bloqueando por completo la carpeta `scratch/`

### Todo endpoint nuevo debe declarar su autenticación

No basta con que el archivo esté en `admin/`: es público por URL. Los endpoints
de administración **deben** empezar así:

```php
session_start();
require '../includes/db.php';
require 'check_session.php';
requerirAdmin();
header('Content-Type: application/json; charset=utf-8');
```

Los únicos endpoints sin chequeo de rol son los públicos por diseño:
`api/get_productos.php`, `api/get_config.php` y `api/get_metodos_pago.php`.

Endpoints de cliente: basta con `$_SESSION['usuario_id']`, pero hay que
comprobar también el `rol` si el dato es del negocio, no del cliente.

### `escaparHTML()` no sirve para atributos: usar `escaparAtributo()`

`escaparHTML()` funciona poniendo el valor en un nodo de texto y leyendo el
`innerHTML`:

```js
div.textContent = valor;  return div.innerHTML;
```

Un nodo de texto **no necesita escapar comillas**, así que las comillas
sobreviven. Para contenido entre etiquetas está bien, pero dentro de un atributo
(`href="..."`, `src="..."`, `value="..."`) una comilla **cierra el atributo** y
permite colar otro, por ejemplo `onmouseover="alert(1)"`.

`escaparAtributo()` añade el escapado que falta y debe usarse en todo valor que
vaya dentro de un atributo:

```js
function escaparAtributo(valor) {
    return escaparHTML(valor).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
```

Hoy no hay ninguna exploitation posible por `pagos.receipt_path` porque
`api/subir_comprobante.php` solo acepta las extensiones `pdf, jpg, jpeg, png,
webp` y arma el nombre con `$ventaId`, `time()` y la extensión. Pero
`escapeHTML()` dentro de atributos aparece en varios sitios, así que **no
confiar en la lista blanca como única defensa**.

### El id de una tabla debe existir de verdad en el HTML

Bug real que estuvo semanas en producción: la tabla de **pagos pendientes** se
quedaba eternamente en "Cargando...". La causa era que el JavaScript pedía
`pagosPendientesBody`, pero la tabla en `index.php` se llama `pagosTableBody`:

```js
const tbody = document.getElementById('pagosPendientesBody');
if (!tbody) return;              // <-- aborta aqui, ni siquiera hace el fetch
```

El `return` silencioso es lo que lo hace tan difícil de detectar: no hay error en
consola, no hay alerta, el endpoint responde bien y la tabla simplemente nunca
cambia. Ojo: hay tablas que el JS **crea al vuelo** con `insertAdjacentHTML`
(`modalRechazo`, la de motivo de rechazo) y esas no están en el HTML, así que un
"id no existe en el HTML" no siempre es un bug. Antes de corregir uno, comprobar
si el elemento se crea en JavaScript.

Regla: `node --check` **no** detecta ni esto ni un `id` mal escrito. Solo se ve
ejecutando la interfaz de verdad (ver "Cómo probar el panel sin abrir el
navegador").

### Trampas ya corregidas (revisar antes de copiar código de otro endpoint)

- `Sanitizer::int($x, 1)` en una comprobación de sesión **desactiva la
  autenticación**: el segundo parámetro es el mínimo, así que `int(0, 1)`
  devuelve `1` y `if (!$usuarioId)` nunca se cumple. Para "cualquier entero
  informado, 0 si no hay" usar `Sanitizer::int($x)` y comparar contra `<= 0`.
- Lo mismo con ids y cantidades: `int($item['id'] ?? 0, 1)` convertía un
  `id: 0` en `id: 1` (compraba el producto equivocado) y `int($c, 1, 100)`
  convertía `cantidad: 0` o negativa en `1`.
- `admin/index.php` hace el control de sesión, pero los endpoints son
  peticiones HTTP independientes: cada uno se valida solo.

### Reinstalar la base

`setup.php` y `admin_setup.php` están bloqueados por `.htaccess`. Para usarlos:

```
ren .htaccess .htaccess.bak
php setup.php
ren .htaccess.bak .htaccess
```

O renombrar/eliminar temporalmente el `.htaccess` de la raíz.# EasyStore
