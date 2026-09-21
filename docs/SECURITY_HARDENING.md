# Security Hardening / SaaS Multi-Tenant

## Modelo de aislamiento

- La BD central contiene clientes, licencias, tenants, sesiones y tickets.
- Cada licencia obtiene una BD MySQL independiente.
- Cada tenant obtiene un usuario MySQL independiente con `GRANT ALL` únicamente sobre su propia BD.
- El aprovisionador clona la estructura de `systems`, pero no copia usuarios ni contraseñas.
- Cada instancia recibe un `.env` propio con DB credentials, `POS_SECRET`, `TENANT_API_KEY` y `TENANT_ID`.
- `TENANT_API_KEY` se guarda como SHA-256 en la BD central y solo existe en el servidor del tenant.

## Autenticación

- Contraseñas: `password_hash(..., PASSWORD_DEFAULT)` y `password_verify`.
- Tokens: HMAC-SHA256 con `jti`, expiración y revocación server-side.
- Los tokens ya no se aceptan por query string; únicamente `Authorization: Bearer`.
- Rate limiting: 8 intentos fallidos por usuario/IP bloquean durante 15 minutos.
- Las contraseñas legacy en texto plano ya no se aceptan.

## Tickets

El POS usa `systems/api/tickets.php` como proxy servidor-a-servidor. El navegador nunca conoce la clave del tenant. El panel central consulta la misma tabla `TICKETS`. El frontend refresca periódicamente para aproximar tiempo real sin exponer un canal WebSocket innecesario.

## Rutas

Apache bloquea acceso directo a `config`, `docs`, `libs`, `storage`, `tenants` y archivos `.env`, `.sql`, `.bak`, `.log`, etc. Las APIs se publican mediante rutas limpias (`/api/auth`, `/tenant/<id>/api/tickets`, etc.).

## Frontend

No existe cifrado que pueda ocultar de forma segura JavaScript ejecutado en el navegador: el navegador necesita recibirlo. Se aplican CSP, `nosniff`, `frame-ancestors`, `Referrer-Policy`, reducción de tokens en URLs y escape contextual. La ofuscación/minificación puede dificultar lectura casual, pero nunca debe considerarse un control de seguridad.

## Producción

1. Configure `.env` fuera de Git.
2. Use HTTPS obligatorio.
3. Cree un usuario MySQL central sin privilegios de DROP salvo el usuario de provisioning.
4. Mantenga el usuario provisioning separado del usuario de la aplicación.
5. Sitúe `LS_TENANT_STORAGE` fuera del document root cuando sea posible.
6. Ejecute las migraciones antes de habilitar tráfico.
7. No habilite `SEED_DEMO_DATA`.
8. No use credenciales de `root`.
9. Elimine o deshabilite los endpoints de setup después de instalar.
