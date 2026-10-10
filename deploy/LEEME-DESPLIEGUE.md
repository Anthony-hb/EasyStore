# EasyStore – Guía de despliegue en InfinityFree (hosting gratuito)

Este paquete contiene tu **sistema EasyStore listo para producción**:

- `easystore/` → los archivos de la aplicación (PHP + JS + CSS), ya sin scripts de
  diagnóstico/mantenimiento y con `includes/db.php` de producción.
- `database.sql` → estructura de la base de datos (versión limpia, sin
  `CREATE DATABASE` ni `USE`, lista para importar en la base que crees).
- `plantillas/db-produccion.php` → plantilla de conexión (la usó el build).

> La primera vez que alguien abra el sitio en el hosting, `includes/schema.php`
> completa automáticamente las tablas y migraciones que falten (pedidos, unidad
> de medida, IVA/IGTF, caja...). Es idempotente y seguro.

---

## Paso 1 — Crear la cuenta y el subdominio

1. Entra a <https://infinityfree.com/> y crea una cuenta gratuita (solo email, sin tarjeta).
2. En el panel, crea tu **subdominio** (ej. `easystore.rf.gd`) o conecta un dominio propio.
3. En el mismo panel, revisa que el **PHP** esté en **8.2 o 8.3** (aplicación → opciones / versión PHP).
4. Activa el **SSL gratuito** para tu subdominio (puede tardar unas horas).

## Paso 2 — Crear la base de datos

1. En el panel abre **MySQL Databases** → *Create New Database*.
2. Inventa un nombre (ej. `easystore`). El panel lo guardará como `if0_12345678_easystore`.
3. El panel te muestra los **4 datos** que necesitarás:
   - Database name, Database user, Database password y **MySQL Hostname**.
4. Anótalos. El hostname **NO es `localhost`**.

## Paso 3 — Subir los archivos

1. Descarga [FileZilla](https://filezilla-project.org/) (cliente FTP).
2. Datos de conexión FTP (están en el panel del hosting → FTP):
   - Host: el FTP del hosting (ej. `ftpupload.net` o similar), Usuario y Contraseña del FTP.
3. Conéctate y sube **todo el contenido de `easystore/`** a la carpeta `htdocs/`
   **del subdominio** (cada subdominio tiene su propio `htdocs`).
   - Debe quedar: `htdocs/index.html`, `htdocs/admin/`, `htdocs/api/`, `htdocs/includes/`, etc.
   - No olvides el archivo oculto `.htaccess` (dile a FileZilla que muestre archivos ocultos: Servidor → Forzar mostrar archivos ocultos).

## Paso 4 — Configurar la conexión a la BD

1. En el hosting subido, edita `htdocs/includes/db.php`.
   (Puedes editarlo localmente con el Bloc de notas y volver a subirlo, o usar el
   **File Manager** del panel → clic derecho → *Edit*.)
2. Pega los 4 datos del Paso 2 en `$host`, `$dbname`, `$user`, `$pass`.

## Paso 5 — Importar la base de datos

1. Abre **phpMyAdmin** desde el panel del hosting.
2. Selecciona la **base de datos que ya creaste** (la `if0_...`).
3. Pestaña **Importar** → sube el archivo `database.sql` de este paquete → **Continuar**.
4. Verifica que aparezcan las tablas (productos, ventas, proveedores, ...).

> Si ya tienes datos en tu tienda local que quieras conservar, en vez de importar
> `database.sql` exporta tu base local desde XAMPP/phpMyAdmin e impórtala aquí
> (y sube las imágenes de `uploads/productos/` que vayas a conservar).

## Paso 6 — Crear el administrador

`admin_setup.php` viene **bloqueado por `.htaccess`** (protección). Para usarlo una vez:

1. En FTP / File Manager, renombra `htdocs/.htaccess` a `htdocs/.htaccess.bak`.
2. Abre en el navegador: `https://TUSUBDOMINIO.rf.gd/admin_setup.php`
3. Crea tu usuario administrador (nombre del negocio, usuario, clave, datos fiscales si aplica).
4. Vuelve a renombrar `htdocs/.htaccess.bak` → `htdocs/.htaccess`.

> Alternativa si no quieres tocar el `.htaccess`: inserta el admin directo en
> phpMyAdmin (filas en `administrador` con `password` = `password_hash(...)`).
> Aviso: **nunca** dejes `admin_setup.php` accesible sin el bloqueo.

## Paso 7 — Permisos de escritura (imágenes y comprobantes)

Las carpetas `uploads/productos/` y `uploads/comprobantes/` deben poder escribir los
archivos. Desde el File Manager: clic derecho sobre cada carpeta → **Change Permissions**
→ marca `755` (o `775`). Si al subir una imagen del producto aparece error 500, prueba `775`/`777`.

## Paso 8 — Revisar y limpiar

1. Abre `https://TUSUBDOMINIO.rf.gd` → debe verse la tienda.
2. Entra a `/admin/index.php` y verifica que carguen panel, inventario y caja.
3. **Apaga la visualización de errores** en producción: en `includes/db.php`, pon
   `ini_set('display_errors', 0);` y sube de nuevo. Deja `error_log` activo.
4. No subas nunca los scripts de la raíz local tipo `check_*`, `update_*`, `reset_all.php`,
   `show_*`, `debug_*`, `test_insert.php` ni la carpeta `scratch/` (el `.htaccess` los bloquea,
   pero mejor que no existan en el hosting).
5. Activa el **SSL** si no estaba y verifica que el candado aparezca.

---

## Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `Error de conexión a la base de datos` | Host/user/clave mal puestos en `db.php` | Revisa los 4 datos en el panel; el hostname nunca es `localhost` |
| `Access denied for user` | Clave o usuario de BD incorrectos | Regenera la clave en el panel y actualiza `db.php` |
| Página en blanco | `display_errors` en 0 sin revisar antes | Activa temporalmente `display_errors = 1`, ve el error, corrige y vuelve a apagarlo |
| **500 Internal Server Error** | Permisos de `uploads/` o error de PHP | Sube permisos a 775/777 en uploads o revisa el log de errores del hosting |
| No se ven imágenes de productos | `uploads/productos/` sin permiso de escritura | Aplica chmod 755/775/777 |
| El sitio "se cae" o tarda | Límites de CPU del plan gratis (suspensión 24 h) | Optimiza (menos consultas), o pasa a un plan pago cuando el tráfico crezca |
| `https` no carga / candado rojo | SSL no activado o pendiente de emisión | Activa SSL en el panel y espera; fuerza `https://` en el navegador |

## Nota legal / fiscal

El sistema es de **control interno** (no emite factura fiscal). El libro de IVA/IGTF y el
cierre de caja sirven para tu soporte contable, pero no sustituyen la declaración ni el
libro legal. Guarda respaldos de la base regularmente (exportar desde phpMyAdmin): el
hosting gratuito no garantiza copias de seguridad.