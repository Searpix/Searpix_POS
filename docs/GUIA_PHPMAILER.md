# Guía de configuración — Correos con PHPMailer (SMTP)

Comandix envía correos por **SMTP** usando **PHPMailer 6.9.3** (ya incluido en
`libs/PHPMailer/`, no necesitas Composer). Se usa para:

- Notificar al administrador (`searpix@gmail.com`) cada evento importante:
  nueva orden, comprobante recibido, pago aprobado o rechazado.
- Enviar al cliente el aviso de que su licencia quedó activa.
- Procesar el formulario de contacto de la página de inicio (`enviar.php`).

---

## 1. Dónde se configura

Todo el correo se configura en el bloque **SMTP** de `config/config.php`:

```php
define('LS_MAIL_ENABLED', true);               // false = desactiva el envío
define('LS_MAIL_HOST',     'smtp.gmail.com');   // host SMTP
define('LS_MAIL_PORT',     587);                // 587 (TLS) o 465 (SSL)
define('LS_MAIL_SECURE',   'tls');              // 'tls' o 'ssl'
define('LS_MAIL_USER',     'searpix@gmail.com');    // usuario SMTP
define('LS_MAIL_PASS',     'PON_AQUI_TU_APP_PASSWORD'); // App Password
define('LS_MAIL_FROM',     'searpix@gmail.com');    // remitente
define('LS_MAIL_FROM_NAME','Comandix Licencias');     // nombre remitente
define('LS_NOTIFY_EMAIL',  'searpix@gmail.com');     // recibe notificaciones
```

---

## 2. Gmail: crear una "Contraseña de aplicación" (App Password)

Gmail **no** permite usar tu contraseña normal para SMTP. Debes generar una
contraseña de aplicación:

1. Entra a tu cuenta de Google → **Seguridad**.
2. Activa la **Verificación en dos pasos** (obligatorio).
3. Busca **Contraseñas de aplicaciones** (App Passwords).
4. Crea una nueva (tipo "Correo" / "Otra": Comandix).
5. Copia la contraseña de 16 caracteres y pégala en `LS_MAIL_PASS`.

> Con Gmail usa `smtp.gmail.com`, puerto **587** con `tls`, o **465** con `ssl`.

Otros proveedores (Outlook, Zoho, tu hosting) funcionan igual: solo cambia
`LS_MAIL_HOST`, `LS_MAIL_PORT` y `LS_MAIL_SECURE` según su documentación.

---

## 3. Habilitar extensiones en `php.ini` (XAMPP)

Para que PHPMailer pueda conectarse por SMTP con TLS/SSL necesitas **openssl**
y para las pasarelas (Wompi/PayPal) también **curl**. En XAMPP:

1. Abre el **Panel de Control de XAMPP → Apache → Config → `php.ini`**.
2. Busca y **descomenta** (quita el `;` inicial) estas líneas:

```ini
extension=openssl
extension=curl
extension=mbstring
extension=fileinfo
```

3. (Recomendado) Configura el certificado raíz para validar HTTPS/SMTP.
   Descarga `cacert.pem` de <https://curl.se/ca/cacert.pem>, guárdalo por
   ejemplo en `C:\xampp\php\extras\ssl\cacert.pem` y añade en `php.ini`:

```ini
[curl]
curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"

[openssl]
openssl.cafile = "C:\xampp\php\extras\ssl\cacert.pem"
```

4. **Reinicia Apache** desde el panel de XAMPP para aplicar los cambios.

> Si no configuras `cacert.pem`, puede fallar la verificación del certificado
> al conectar por SMTP o a las APIs de pago. Es la causa más comúnn del error
> "SSL certificate problem".

---

## 4. Cómo se usan los correos en el código

- `api/mailer.php` expone estas funciones:
  - `lsSendMail($para, $asunto, $htmlCuerpo)` — envía un correo.
  - `lsNotifyAdmin($asunto, $htmlCuerpo)` — envía a `LS_NOTIFY_EMAIL`.
  - `lsMailWrap`, `lsMailTable`, `lsRow` — arman el HTML del correo.
- Las notificaciones ya están integradas en `api/payments.php` y
  `api/webhooks.php` (orden creada, comprobante, aprobado, rechazado).
- El formulario de contacto de `index.html` envía por `fetch()` a `enviar.php`,
  que valida, sanitiza (incluye honeypot anti-spam) y notifica al admin.

---

## 5. Probar que el correo funciona

1. Pon `LS_MAIL_ENABLED = true` y completa `LS_MAIL_PASS` con tu App Password.
2. Abre la página de inicio y envía el **formulario de contacto**.
3. Debe llegar un correo a `searpix@gmail.com` con los datos del mensaje.
4. Si no llega, revisa el paso 3 (openssl/curl y `cacert.pem`) y los datos SMTP.

---

## 6. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---------|----------------|----------|
| "SMTP connect() failed" | openssl no habilitado o puerto/host erróneo | Habilita `extension=openssl` y verifica host/puerto |
| "SSL certificate problem" | Falta `cacert.pem` | Configúralo en `php.ini` (paso 3) |
| "Username and Password not accepted" (Gmail) | Usas la clave normal | Usa una **App Password** |
| No llega ningún correo | `LS_MAIL_ENABLED = false` | Ponlo en `true` |
| El contacto responde error | SMTP mal configurado | Revisa la consola del navegador y los datos SMTP |
