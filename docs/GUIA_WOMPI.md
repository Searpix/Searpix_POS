# Guía de configuración — WOMPI (Producción)

Esta guía explica cómo dejar funcionando los pagos reales con **Wompi** en
Comandix. Wompi procesa pagos en **pesos colombianos (COP)** con tarjeta, PSE,
Nequi y otros medios.

---

## 1. Requisitos previos

- Una cuenta de comercio activa en <https://comercios.wompi.co>.
- Tu comercio **aprobado y en modo producción** (no sandbox).
- El sitio publicado en una **URL real y accesible por internet** con HTTPS.
  Wompi necesita redirigir al cliente de vuelta a tu sitio tras el pago, así
  que `localhost` **no sirve** en producción.

---

## 2. Dónde van las llaves

Todas las llaves se configuran en **`config/config.php`** (nunca en el
frontend). Este proyecto ya viene con el modo producción activado:

```php
define('WOMPI_MODE', 'production'); // 'sandbox' | 'production'
```

Las cuatro llaves de producción se definen en el mismo archivo:

| Constante | Descripción | ¿Se expone al frontend? |
|-----------|-------------|--------------------------|
| `WOMPI_PROD_PUBLIC`    | Llave pública (`pub_prod_...`)        | ✅ Sí (es pública) |
| `WOMPI_PROD_PRIVATE`   | Llave privada (`prv_prod_...`)        | ❌ NO — secreta |
| `WOMPI_PROD_INTEGRITY` | Secreto de integridad (firma)         | ❌ NO — secreta |
| `WOMPI_PROD_EVENTS`    | Secreto de eventos (webhooks)         | ❌ NO — secreta |

> ⚠️ **Seguridad:** solo la llave pública puede viajar al navegador. La
> privada, la de integridad y la de eventos deben quedarse **siempre** en el
> servidor. Nunca las publiques en repositorios ni las imprimas en pantalla.

Puedes copiar cada llave desde el panel de Wompi:
**Comercios → Configuración → Llaves de API (Producción).**

---

## 3. URL base del sitio (¡importante!)

En `config/config.php` ajusta la URL real de tu sitio:

```php
define('LS_BASE_URL', 'https://licencias.tudominio.com'); // sin barra final
```

Esta URL se usa para armar la **redirect-url** a la que Wompi devuelve al
cliente después de pagar:

```
LS_BASE_URL + /views/portal.html?pago=PAY-xxxx&via=wompi
```

Si la dejas en `http://localhost/...`, el pago se procesará pero el cliente
**no volverá** a tu sitio en producción.

---

## 4. Cómo funciona el flujo de pago

1. El cliente elige un plan en el portal y pulsa **Pagar con Wompi**.
2. El backend (`api/payments.php` → `create_order`) crea una licencia y un
   pago en estado `PENDIENTE`, y genera la firma de integridad.
3. El navegador redirige al **Checkout de Wompi** con la llave pública,
   el monto en centavos, la referencia y la firma.
4. Tras pagar, Wompi redirige al cliente a `views/portal.html?...&via=wompi`.
5. El portal llama a `wompi_confirm`, que consulta la transacción en la API de
   Wompi. Si el estado es `APPROVED`, la licencia se **activa** automáticamente.
6. En paralelo, el **webhook** (`api/webhooks.php`) recibe el evento de Wompi
   y confirma el pago aunque el cliente cierre el navegador.

---

## 5. Configurar el Webhook (eventos)

En el panel de Wompi, sección **Eventos / Webhooks**, registra la URL:

```
https://licencias.tudominio.com/api/webhooks.php
```

Wompi firmará cada evento con `WOMPI_PROD_EVENTS`; el backend valida esa
firma antes de activar la licencia. Así el pago se confirma de forma segura
aunque el cliente no regrese al sitio.

---

## 6. Montos y moneda

- Todos los montos se envían a Wompi **en centavos de COP** (el backend
  multiplica por 100 automáticamente).
- Los planes están en `api/setup.php` (COP): Básico 49.900 / 499.900,
  Profesional 89.900 / 899.900, Enterprise 149.900 / 1.499.900.

---

## 7. Prueba rápida en producción

1. Verifica en `config/config.php` que `WOMPI_MODE = 'production'` y que
   `LS_BASE_URL` es tu dominio real con HTTPS.
2. Compra el plan más barato con una tarjeta real (puedes reembolsarte luego
   desde el panel de Wompi).
3. Confirma que la licencia pasa a **ACTIVA** en el portal y en el panel de
   administración (sección **Pagos** e **Historial**).

---

## 8. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---------|----------------|----------|
| "Wompi no está configurado" | La llave pública todavía tiene `XXXX` | Pega las llaves reales de producción |
| El cliente no vuelve al sitio | `LS_BASE_URL` es `localhost` | Pon tu dominio real con HTTPS |
| El pago queda PENDIENTE | Webhook no registrado o firma inválida | Registra el webhook y revisa `WOMPI_PROD_EVENTS` |
| "La referencia no coincide" | La orden fue manipulada | Genera una nueva orden desde el portal |
| Error de certificado SSL en la API | `cacert.pem` no configurado en PHP | Ver `GUIA_PHPMAILER.md` → sección php.ini (curl/openssl) |
