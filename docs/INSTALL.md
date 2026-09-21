# Instalación — Comandix License Server

Guía paso a paso para poner en marcha el servidor de licencias en **XAMPP** (Windows/Mac/Linux).

---

## 1. Requisitos

- XAMPP con **PHP 7.4+** y **MySQL/MariaDB**
- Extensiones PHP: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`
  (vienen activas por defecto en XAMPP; si el correo o las pasarelas fallan,
  revisa **`docs/GUIA_PHPMAILER.md` → sección php.ini**).
- Un navegador moderno

---

## 1.1. Logo de la marca

El logo se muestra desde `assets/img/logo.png`. Se incluye un logo de ejemplo
(placeholder). **Para usar tu propio logo**, copia tu archivo
`C:\xampp\htdocs\assets\img\logo.png` sobre `assets/img/logo.png` del proyecto
(mismo nombre `logo.png`, formato PNG con fondo transparente recomendado).
No hace falta tocar el código: todas las páginas ya apuntan a esa ruta y
usan un respaldo automático con el texto "SC" si la imagen no carga.

---

## Estructura del proyecto

```
license-server/
├─ index.html              Página pública (hero, servicios, planes, contacto)
├─ enviar.php              Procesa el formulario de contacto (async, JSON)
├─ .htaccess
├─ config/
│  └─ config.php           ⚙️  Único archivo con datos sensibles (DB, WOMPI, SMTP)
├─ api/
│  ├─ setup.php            Crea la base de datos y los datos demo
│  ├─ auth.php             Login / sesiones (admin y cliente)
│  ├─ licenses.php         CRUD de licencias + estadísticas
│  ├─ payments.php         Órdenes, pagos, aprobar/rechazar, historial
│  ├─ webhooks.php         Recibe eventos de las pasarelas
│  ├─ mailer.php           Envío de correos (PHPMailer)
│  └─ gateways/            wompi.php, paypal.php
├─ libs/
│  └─ PHPMailer/           Librería PHPMailer 6.9.3 (incluida, sin Composer)
├─ assets/
│  ├─ css/                 main.css, auth.css, dashboard.css
│  └─ js/                  main.js, auth.js, portal.js, admin.js
├─ views/
│  ├─ login.html  register.html
│  ├─ portal.html          Portal del cliente
│  └─ admin.html           Panel de administración
├─ storage/                Comprobantes subidos
└─ docs/                   INSTALL, GUIA_WOMPI, GUIA_PHPMAILER, SQL
```

---

## 2. Copiar el proyecto

1. Copia toda la carpeta del proyecto dentro de `htdocs`, por ejemplo:
   ```
   xampp/htdocs/license-server/
   ```
2. Inicia **Apache** y **MySQL** desde el panel de XAMPP.

> La URL base quedará como `http://localhost/license-server/`.
> Si usas otra carpeta o dominio, ajusta `RewriteBase` en `.htaccess`
> y `LS_BASE_URL` en `config/config.php`.

---

## 3. Configurar credenciales

Edita **`config/config.php`** (único archivo con datos sensibles):

- **Base de datos**: `LS_DB_HOST`, `LS_DB_NAME`, `LS_DB_USER`, `LS_DB_PASS`
  (por defecto XAMPP usa `root` sin contraseña).
- **URL base**: `LS_BASE_URL` (ej. `http://localhost/license-server`).
- **Pasarelas** (opcional, ver sección 5).

---

## 4. Crear la base de datos

Abre en el navegador:

```
http://localhost/license-server/api/setup.php
```

Esto crea la base `freakers_licenses`, todas las tablas y los datos demo:

| Rol     | Usuario               | Contraseña      |
|---------|-----------------------|-----------------|
| Admin/Cliente | Configure las credenciales mediante las variables de entorno del instalador. No existen contraseñas predeterminadas. |

> ⚠️ **Seguridad**: borra o protege `api/setup.php` después de la instalación,
> ya que recrea la base de datos.

---

## 5. Pasarelas de pago (Wompi + PayPal)

Las pasarelas son **opcionales**. Si no las configuras, el sistema seguirá
funcionando solo con el método **manual** (transferencia + comprobante).

### Wompi (Colombia — COP)
1. Crea tu comercio en <https://comercios.wompi.co>.
2. Copia tus llaves en `config/config.php`:
   - `WOMPI_SANDBOX_PUBLIC`, `WOMPI_SANDBOX_PRIVATE`,
     `WOMPI_SANDBOX_INTEGRITY`, `WOMPI_SANDBOX_EVENTS` (pruebas).
   - Sus equivalentes `WOMPI_PROD_*` para producción.
3. Cambia `WOMPI_MODE` a `'production'` cuando salgas a producción.
4. En el panel de Wompi, configura la **URL de eventos (webhook)**:
   ```
   http://TU-DOMINIO/license-server/api/webhooks.php?gateway=wompi
   ```

### PayPal (internacional — USD)
1. Crea una app en <https://developer.paypal.com>.
2. Copia el Client ID y Secret en `config/config.php`:
   - `PAYPAL_SANDBOX_CLIENT_ID`, `PAYPAL_SANDBOX_SECRET` (pruebas).
   - `PAYPAL_PROD_CLIENT_ID`, `PAYPAL_PROD_SECRET` (producción).
3. Cambia `PAYPAL_MODE` a `'production'` cuando corresponda.
4. Ajusta la tasa `PAYPAL_COP_TO_USD` (COP → USD) según tu criterio.

> Solo la **llave pública** (Wompi) y el **Client ID** (PayPal) se exponen al
> frontend. Las llaves privadas y secretos **nunca** salen del servidor.

> 📘 Para la puesta en producción de Wompi con llaves reales, sigue la guía
> detallada **`docs/GUIA_WOMPI.md`**. Para el envío de correos (notificaciones
> y formulario de contacto) sigue **`docs/GUIA_PHPMAILER.md`**.

---

## 6. Migración (si ya tenías una base de datos previa)

Si instalaste una versión anterior **sin** soporte de pasarelas, ejecuta una
sola vez el script de migración en phpMyAdmin (pestaña SQL):

```
docs/migracion_pasarelas.sql
```

Añade `PAYPAL` al ENUM de métodos y la columna `gateway_ref`. **No borra datos.**

---

## 6.5. Correo / Notificaciones (SMTP)

El sistema envía notificaciones por correo a `searpix@gmail.com` (nueva orden,
comprobante recibido, pago aprobado/rechazado) y procesa el formulario de
contacto de la página de inicio. Configúralo en el bloque SMTP de
`config/config.php` (`LS_MAIL_*`, `LS_NOTIFY_EMAIL`).

Guía completa (incluye App Password de Gmail y ajustes de `php.ini`):
**`docs/GUIA_PHPMAILER.md`**.

---

## 7. Usar el sistema

- **Página pública**: `http://localhost/license-server/`
- **Portal cliente**: `views/login.html` → comprar / gestionar licencias
- **Panel admin**: `views/login.html` (con las credenciales de admin)

Flujo de compra en el portal del cliente:
1. **Comprar Licencia** → elige plan y periodo (mensual/anual).
2. Se abre el **checkout** con 3 métodos: Wompi, PayPal o Transferencia.
3. Al aprobarse el pago, la licencia pasa automáticamente a **ACTIVA**.

---

## Soporte

WhatsApp: +57 323 563 6580 · Email: searpix@gmail.com
