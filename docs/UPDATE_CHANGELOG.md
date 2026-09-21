# Update — Security + Multi-Tenant SaaS

## Entregado

1. Auditoría OWASP y refactorización de autenticación/SQL/XSS.
2. Password hashing moderno y eliminación de fallback plaintext.
3. Tokens HMAC con `jti`, expiración y revocación server-side.
4. Rate limiting de login.
5. Eliminación de tokens Bearer de query strings.
6. CORS estricto y headers de seguridad/CSP.
7. Multi-tenant por BD independiente + usuario MySQL dedicado.
8. Aprovisionamiento automático de filesystem POS desde `/systems`.
9. `.env` individual por tenant con secreto POS y clave server-to-server.
10. Routing `/tenant/<id>/...` sin extensiones.
11. Tickets centrales para portal + POS tenant.
12. Polling periódico de tickets para actualización casi inmediata.
13. Migración SQL documentada.
14. Hardening Apache + configuración Nginx de referencia.
15. Validación `php -l` y `node --check` de todo el proyecto.

## Importante antes de producción

- Crear `.env` a partir de `.env.example`.
- No usar `root` en MySQL.
- Configurar `LS_DB_USER`, `LS_DB_PASS`, `LS_SECRET`.
- Configurar `LS_TENANT_DB_ADMIN_USER/PASS` con privilegios solo de provisioning.
- Preferir `LS_TENANT_STORAGE` fuera del document root.
- Ejecutar la migración central.
- Preparar la BD plantilla `freakers_pos` y verificar que `/systems` funciona.
- No activar `SEED_DEMO_DATA`.
- Usar HTTPS.
- Tras instalar, deshabilitar/eliminar los endpoints de setup si no son necesarios.
