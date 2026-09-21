# Auditoría de seguridad y refactorización

## Hallazgos principales corregidos

| Área | Hallazgo | Corrección |
|---|---|---|
| SQL Injection | Se revisaron consultas dinámicas; los parámetros de usuario ahora pasan por PDO prepared statements. Los identificadores dinámicos del provisioning se validan con whitelist estricta. | PDO emulated prepares desactivados + validación de identificadores. |
| XSS | Había múltiples plantillas `innerHTML` con valores procedentes de API. | Escape contextual en portal/admin/soporte y CSP. |
| Broken Authentication | Tokens POS eran HMAC pero aceptaban query-string y no tenían revocación. | Bearer únicamente, `jti`, expiración 24h y tabla de sesiones revocables. |
| Passwords | Existía fallback de contraseña POS en texto plano. | Eliminado; únicamente `password_verify` + `password_needs_rehash`. |
| Credenciales | POS y servidor central tenían defaults `root`/vacío. | Configuración mediante `.env`; provisioning crea usuario DB dedicado por tenant. |
| Brute force | No había límite de intentos efectivo. | 8 fallos/15 minutos por usuario+IP en central y POS. |
| Token leakage | Admin/portal enviaban token en URL. | Eliminado; `Authorization: Bearer` y `sessionStorage`. |
| Multi-tenancy | Todos los tenants podían compartir DB/credenciales. | BD + usuario MySQL + `.env` + POS filesystem independientes. |
| Tickets | POS tenía tablas locales y el central tenía otro módulo. | POS actúa como proxy autenticado hacia `api/tickets.php`; tickets viven centralmente. |
| Rutas sensibles | Config/storage/libs/docs podían ser navegables según despliegue. | Reglas 403, no directory listing y deny de extensiones sensibles. |
| Uploads | Se validaban tipos de imagen por contenido. | Se mantiene y refuerza con nombre aleatorio, `getimagesize`, `finfo` y bloqueo de ejecución. |

## OWASP Top 10

### A01 Broken Access Control
Los endpoints POS exigen sesión y rol; los tickets centralizados exigen además una credencial server-to-server del tenant. La BD de cada tenant solo puede ser accedida por su usuario MySQL.

### A02 Cryptographic Failures
No se almacenan contraseñas reversibles. Los secretos de aplicación salen del código fuente y pasan a `.env`. Los tokens usan HMAC-SHA256 y expiración.

### A03 Injection
Las consultas usan parámetros PDO. Solo se construyen identificadores SQL después de validar `[A-Za-z0-9_]`.

### A04 Insecure Design
El aislamiento dejó de depender de una columna `tenant_id`: cada tenant tiene una BD independiente. El canal de tickets central usa una API dedicada.

### A05 Security Misconfiguration
Se eliminaron defaults de credenciales, se añadieron headers de seguridad, CSP, 403 para rutas críticas y se deshabilitó directory listing.

### A06 Vulnerable Components
PHPMailer existente se conserva. Debe mantenerse actualizado según la política de dependencias del despliegue.

### A07 Identification/Auth Failures
Tokens con `jti`, expiración y revocación; password hashing moderno; rate limiting.

### A08 Software/Data Integrity
Provisioning genera secretos aleatorios por tenant. La API central valida el hash de la clave server-to-server.

### A09 Logging/Monitoring
Se mantienen logs de auditoría y se agregan eventos de provisioning/tickets. En producción, enviar `error_log` y auditoría a un colector central.

### A10 SSRF
Las URLs remotas de licencia/pasarelas deben salir de configuración administrativa. No se aceptan URLs arbitrarias desde el usuario final.

## Limitación inevitable del frontend

JavaScript que debe ejecutarse en el navegador no puede cifrarse de forma que el usuario del navegador no pueda inspeccionarlo. Se aplican CSP, tokens fuera de URLs, `sessionStorage`, reducción de información sensible y validación server-side. Minificar/ofuscar puede dificultar lectura casual, pero no constituye un control de seguridad.

## Validaciones ejecutadas

- `php -l`: todos los PHP sin errores de sintaxis.
- `node --check`: todos los JavaScript sin errores de sintaxis.
- No quedaron referencias a las contraseñas demo originales.
- No quedaron consultas SQL concatenando directamente `$_GET`, `$_POST`, `$_REQUEST` o `$input`.
- No se detectaron llamadas `eval()`.
