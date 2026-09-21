/* ============================================
   Comandix — Client Portal JS
   ============================================ */

const API = '../api';

// ---- Auth Guard ----
const token = localStorage.getItem('ls_token');
const userType = localStorage.getItem('ls_type');
if (!token || userType !== 'client') {
  window.location.href = 'login';
}

const user = JSON.parse(localStorage.getItem('ls_user') || '{}');
let ticketPollTimer = null;

// ---- Scroll reveal + logo fallback ----
const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); revealObserver.unobserve(e.target); } });
}, { threshold: 0.12 });
function applyReveal(scope) {
  (scope || document).querySelectorAll('.reveal:not(.in)').forEach(el => revealObserver.observe(el));
}
document.addEventListener('DOMContentLoaded', () => {
  const logo = document.getElementById('brandLogo');
  if (logo) logo.addEventListener('error', () => {
    const span = document.createElement('span');
    span.className = 'nav-logo-icon';
    span.textContent = 'Cx';
    span.style.fontSize = '1rem'; span.style.fontWeight = '900';
    logo.replaceWith(span);
  });
});

const KEY_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="#c9b8ff" stroke-width="1.8" style="width:18px;height:18px;vertical-align:-3px"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.5 12.5 8-8m-2 2 2 2m-4 0 2 2"/></svg>';

// ---- API Helper ----
function lsApi(endpoint, method = 'POST', body = null) {
  const opts = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + token
    }
  };
  if (body) opts.body = JSON.stringify(body);
  const ep = /\.[a-z0-9]+$/i.test(String(endpoint).split('?')[0]) ? endpoint
    : String(endpoint).replace(/(\?|$)/, '.php$1');
  return fetch(API + '/' + ep, opts).then(r => r.json());
}

// ---- Toast ----
function showToast(msg, type = 'success') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

// ---- Init User Info ----
document.getElementById('userName').textContent = user.nombre || 'Usuario';
document.getElementById('userAvatar').textContent = (user.nombre || 'U')[0].toUpperCase();

// ---- Section Switch ----
function switchSection(name, el) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-' + name).classList.add('active');
  if (el) el.classList.add('active');

  const titles = {
    licenses: 'Mis Licencias',
    systems: 'Mis Sistemas',
    purchase: 'Comprar Licencia',
    payments: 'Mis Pagos',
    support: 'Soporte',
    profile: 'Mi Perfil'
  };
  document.getElementById('pageTitle').textContent = titles[name] || name;

  if (name === 'licenses') loadLicenses();
  if (name === 'systems') loadSystems();
  if (name === 'payments') loadMyPayments();
  if (name === 'profile') renderProfile();
  if (name === 'purchase') loadPurchaseGrid();
  if (name === 'support') {
    loadTickets();
    if (!ticketPollTimer) ticketPollTimer = setInterval(() => {
      const sec = document.getElementById('sec-support');
      if (sec && sec.classList.contains('active')) loadTickets();
    }, 7000);
  }
}

// ---- Logout ----
function logout() {
  sessionStorage.removeItem('ls_token');
  sessionStorage.removeItem('ls_user');
  sessionStorage.removeItem('ls_type');
  localStorage.removeItem('ls_token');
  localStorage.removeItem('ls_user');
  localStorage.removeItem('ls_type');
  window.location.href = 'login';
}

// ---- Load Licenses ----
function loadLicenses() {
  const container = document.getElementById('licenseCards');
  if (!container) return;
  container.innerHTML = '<div class="empty-state"><p>Cargando tus licencias…</p></div>';
  lsApi('licenses', 'POST', { action: 'client_licenses' }).then(data => {
    const licencias = (data && data.licencias) ? data.licencias : [];
    if (!data || !data.exito) {
      container.innerHTML = '<div class="empty-state"><p>No pudimos cargar tus licencias'
        + (data && data.mensaje ? ': ' + escapeHtml(data.mensaje) : '.') + '</p>'
        + '<button class="btn btn-outline" onclick="loadLicenses()">Reintentar</button></div>';
      return;
    }
    if (!licencias.length) {
      container.innerHTML = '<div class="empty-state"><p>No tienes licencias activas.</p><button class="btn btn-primary" onclick="switchSection(\'purchase\',document.querySelector(\'[data-section=purchase]\'))">Comprar Licencia</button></div>';
      return;
    }
    container.innerHTML = licencias.map(lic => {
      const esActiva = lic.estado === 'ACTIVA';
      const idLic = escapeHtml(lic.idLicencia);
      const clave = escapeHtml(lic.clave_licencia || '—');
      return `
      <div class="lic-card reveal">
        <div class="lic-key">${KEY_SVG} ${clave}</div>
        <div class="lic-meta">
          <span class="lic-tag plan">${escapeHtml(lic.plan_nombre || lic.plan || '—')}</span>
          <span class="lic-tag ${statusClass(lic.estado)}">${escapeHtml(lic.estado || '—')}</span>
        </div>
        <div class="lic-dates">
          Activación: ${escapeHtml(lic.fecha_activacion || '—')} · Expira: ${escapeHtml(lic.fecha_expiracion || '—')}
        </div>
        <div class="lic-dates">
          Dispositivos: ${lic.activaciones_usadas || 0}/${lic.dispositivos_maximos || 0}
        </div>
        ${esActiva ? `
        <div class="lic-manage">
          <span class="lic-manage-label">Gestionar licencia</span>
          <div class="lic-manage-btns">
            <button type="button" class="lic-mbtn up" onclick="planChange('${idLic}','upgrade')"><span class="lmi-ic up">&#8593;</span> Upgrade</button>
            <button type="button" class="lic-mbtn down" onclick="planChange('${idLic}','downgrade')"><span class="lmi-ic down">&#8595;</span> Downgrade</button>
            <button type="button" class="lic-mbtn reset" onclick="openResetModal('${idLic}','${clave}')"><span class="lmi-ic reset">&#8635;</span> Resetear</button>
          </div>
        </div>` : ''}
        <div class="lic-actions">
          ${esActiva ? '<span style="color:var(--green);font-weight:600">&#10003; Activa</span>' : ''}
          ${lic.estado === 'PRUEBA' ? '<span style="color:var(--blue);font-weight:600">&#9679; Prueba</span>' : ''}
          ${lic.estado === 'EXPIRADA' ? '<button class="btn btn-sm btn-primary" onclick="switchSection(\'purchase\',document.querySelector(\'[data-section=purchase]\'))">Renovar</button>' : ''}
          ${lic.estado === 'PENDIENTE' ? '<span style="color:var(--yellow);font-weight:600">&#9679; Pendiente de pago</span>' : ''}
          ${lic.url_instancia ? '<a class="btn btn-sm btn-outline" href="'+escapeHtml(lic.url_instancia)+'" target="_blank" rel="noopener">Abrir sistema</a>' : ''}
          ${lic.estado === 'PRUEBA' ? '<button class="btn btn-sm btn-danger" onclick="openResetModal(\''+idLic+'\',\''+clave+'\')">Resetear licencia</button>' : ''}
        </div>
      </div>`;
    }).join('');
    // Garantiza visibilidad aunque el observer de scroll no dispare
    requestAnimationFrame(() => {
      container.querySelectorAll('.reveal:not(.in)').forEach(el => el.classList.add('in'));
    });
    animateCards();
  }).catch(err => {
    container.innerHTML = '<div class="empty-state"><p>Error de conexión al cargar licencias.</p>'
      + '<button class="btn btn-outline" onclick="loadLicenses()">Reintentar</button></div>';
  });
}

function statusClass(status) {
  const map = { ACTIVA: 'activo', PENDIENTE: 'pendiente', EXPIRADA: 'expirado', PRUEBA: 'prueba', SUSPENDIDA: 'suspendido' };
  return map[status] || 'pendiente';
}

// ---- Purchase Grid ----
const PLAN_ICONS = {
  'PLAN-BAS': '<svg viewBox="0 0 24 24" fill="none" stroke="#38BDF8" stroke-width="1.7" style="width:36px;height:36px"><path d="M12 2c3 2 5 5 5 9a5 5 0 0 1-10 0c0-4 2-7 5-9z"/><path d="M9 15c-2 1-3 3-3 6M15 15c2 1 3 3 3 6"/></svg>',
  'PLAN-PRO': '<svg viewBox="0 0 24 24" fill="none" stroke="#A855F7" stroke-width="1.7" style="width:36px;height:36px"><path d="M12 2 15 9l7 .6-5.3 4.6L18.5 21 12 17l-6.5 4 1.8-6.8L2 9.6 9 9z"/></svg>',
  'PLAN-ENT': '<svg viewBox="0 0 24 24" fill="none" stroke="#E94560" stroke-width="1.7" style="width:36px;height:36px"><path d="M3 20h18M5 20V8l4 2 3-6 3 6 4-2v12"/></svg>'
};
const PLANS = [
  { id: 'PLAN-BAS', name: 'Básico', mensual: 49900, anual: 499900, features: ['Punto de venta','Facturación','1 dispositivo','Soporte por tickets'], maxDevices: 1 },
  { id: 'PLAN-PRO', name: 'Profesional', featured: true, mensual: 89900, anual: 899900, features: ['Todo Básico +','Domicilios unificados','Soporte prioritario por tickets','3 dispositivos','Comisiones por app'], maxDevices: 3 },
  { id: 'PLAN-ENT', name: 'Enterprise', mensual: 149900, anual: 1499900, features: ['Todo Profesional +','10 dispositivos','Multi-sucursal','API personalizada','Soporte 24/7'], maxDevices: 10 }
];

let purchasePeriod = 'mensual';

function loadPurchaseGrid() {
  const grid = document.getElementById('purchaseGrid');
  grid.innerHTML = PLANS.map(p => {
    const price = purchasePeriod === 'mensual' ? p.mensual : p.anual;
    const period = purchasePeriod === 'mensual' ? '/mes' : '/año';
    const feat = p.featured ? 'featured' : '';
    const btnClass = p.featured ? 'btn-plan-primary' : 'btn-plan-outline';
    return `
      <div class="purchase-card reveal ${feat}">
        <div style="margin-bottom:8px">${PLAN_ICONS[p.id] || ''}</div>
        <div class="p-name">${p.name}</div>
        <div class="p-price"><span class="cur">$</span>${price.toLocaleString('es-CO')}<span class="per">${period}</span></div>
        <ul class="p-features">${p.features.map(f => '<li>' + f + '</li>').join('')}</ul>
        <button class="btn-plan ${btnClass}" onclick="purchasePlan('${escapeHtml(p.id)}')">Comprar ${p.name}</button>
      </div>`;
  }).join('');

  // Add period toggle
  grid.innerHTML = `
    <div style="grid-column:1/-1;display:flex;gap:4px;background:var(--bg-card);border:1px solid var(--border);border-radius:100px;padding:4px;margin-bottom:12px;max-width:340px;">
      <button class="toggle-opt ${purchasePeriod==='mensual'?'active':''}" onclick="purchasePeriod='mensual';loadPurchaseGrid()" style="flex:1;padding:10px;border:none;border-radius:100px;font-weight:600;font-size:0.9rem;cursor:pointer;background:${purchasePeriod==='mensual'?'linear-gradient(135deg,var(--primary),var(--accent))':'transparent'};color:${purchasePeriod==='mensual'?'#fff':'var(--text-muted)'};transition:0.3s;">Mensual</button>
      <button class="toggle-opt ${purchasePeriod==='anual'?'active':''}" onclick="purchasePeriod='anual';loadPurchaseGrid()" style="flex:1;padding:10px;border:none;border-radius:100px;font-weight:600;font-size:0.9rem;cursor:pointer;background:${purchasePeriod==='anual'?'linear-gradient(135deg,var(--primary),var(--accent))':'transparent'};color:${purchasePeriod==='anual'?'#fff':'var(--text-muted)'};transition:0.3s;">Anual <span style="background:var(--green);color:#000;padding:1px 6px;border-radius:100px;font-size:0.65rem;font-weight:700;">-17%</span></button>
    </div>
  ` + grid.innerHTML;
  applyReveal(grid);
}

function purchasePlan(planId) {
  const plan = PLANS.find(p => p.id === planId);
  if (!plan) return;
  openCheckout(plan);
}

/* ============================================
   CHECKOUT — Wompi / PayPal / Manual
   ============================================ */
let gatewayConfig = null;
let paypalSdkLoaded = false;
let currentCheckout = null; // { plan, periodo, monto }

// Cargar configuración de pasarelas una vez
function loadGatewayConfig() {
  return lsApi('payments', 'POST', { action: 'gateway_config' })
    .then(data => {
      if (data.exito) gatewayConfig = data.gateways;
      return gatewayConfig;
    })
    .catch(() => null);
}

function openCheckout(plan) {
  const monto = purchasePeriod === 'mensual' ? plan.mensual : plan.anual;
  currentCheckout = { plan, periodo: purchasePeriod, monto };

  // Rellenar resumen
  document.getElementById('csIcon').innerHTML = PLAN_ICONS[plan.id] || '';
  document.getElementById('csName').textContent = 'Plan ' + plan.name;
  document.getElementById('csPeriod').textContent = purchasePeriod === 'mensual' ? 'Suscripción mensual' : 'Suscripción anual';
  document.getElementById('csAmount').textContent = '$' + monto.toLocaleString('es-CO') + ' COP';

  // Mostrar/ocultar métodos según disponibilidad
  applyGatewayAvailability();

  // Reset a método por defecto disponible
  const firstAvailable = pickDefaultMethod();
  const btn = document.querySelector('.pay-method[data-method="' + firstAvailable + '"]');
  selectMethod(firstAvailable, btn);

  document.getElementById('checkoutModal').style.display = 'flex';
}

function applyGatewayAvailability() {
  const wompiOn  = gatewayConfig && gatewayConfig.wompi && gatewayConfig.wompi.enabled;
  const paypalOn = gatewayConfig && gatewayConfig.paypal && gatewayConfig.paypal.enabled;
  toggleMethodBtn('wompi', wompiOn);
  toggleMethodBtn('paypal', paypalOn);
  // Manual siempre disponible
}

function toggleMethodBtn(method, enabled) {
  const btn = document.querySelector('.pay-method[data-method="' + method + '"]');
  if (!btn) return;
  btn.style.display = enabled ? '' : 'none';
}

function pickDefaultMethod() {
  if (gatewayConfig && gatewayConfig.wompi && gatewayConfig.wompi.enabled) return 'wompi';
  if (gatewayConfig && gatewayConfig.paypal && gatewayConfig.paypal.enabled) return 'paypal';
  return 'manual';
}

function selectMethod(method, el) {
  document.querySelectorAll('.pay-method').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.pay-panel').forEach(p => p.classList.remove('active'));
  if (el) el.classList.add('active');
  const panel = document.getElementById('panel-' + method);
  if (panel) panel.classList.add('active');

  if (method === 'paypal') renderPaypalButtons();
}

/* ---- WOMPI: crear orden y redirigir al checkout ---- */
function payWithWompi() {
  if (!currentCheckout) return;
  const btn = document.getElementById('btnWompi');
  btn.disabled = true; btn.textContent = 'Generando orden…';
  lsApi('payments', 'POST', {
    action: 'create_order',
    idPlan: currentCheckout.plan.id,
    periodo: currentCheckout.periodo,
    gateway: 'wompi'
  }).then(data => {
    if (!data.exito || !data.wompi) {
      showToast(data.mensaje || 'No se pudo iniciar Wompi', 'error');
      btn.disabled = false; btn.textContent = 'Pagar con Wompi';
      return;
    }
    // Recordar la orden para confirmar al volver
    localStorage.setItem('ls_pending_wompi', data.idPago);
    const w = data.wompi;
    const url = w.checkout_base +
      '?public-key=' + encodeURIComponent(w.public_key) +
      '&currency=' + encodeURIComponent(w.currency) +
      '&amount-in-cents=' + w.amount_in_cents +
      '&reference=' + encodeURIComponent(w.reference) +
      '&signature:integrity=' + encodeURIComponent(w.signature) +
      '&redirect-url=' + encodeURIComponent(w.redirect_url);
    window.location.href = url;
  }).catch(() => {
    showToast('Error de conexión con Wompi', 'error');
    btn.disabled = false; btn.textContent = 'Pagar con Wompi';
  });
}

/* ---- PAYPAL: cargar SDK + botones ---- */
function renderPaypalButtons() {
  const container = document.getElementById('paypalButtons');
  const loading = document.getElementById('paypalLoading');
  container.innerHTML = '';
  if (!gatewayConfig || !gatewayConfig.paypal || !gatewayConfig.paypal.enabled) {
    loading.textContent = 'PayPal no está disponible.';
    return;
  }
  loading.style.display = 'block';

  const mount = () => {
    loading.style.display = 'none';
    if (!window.paypal) { loading.style.display = 'block'; loading.textContent = 'No se pudo cargar PayPal.'; return; }
    let idPagoRef = null;
    window.paypal.Buttons({
      style: { layout: 'vertical', color: 'gold', shape: 'pill', label: 'pay' },
      createOrder: function() {
        return lsApi('payments', 'POST', {
          action: 'create_order',
          idPlan: currentCheckout.plan.id,
          periodo: currentCheckout.periodo,
          gateway: 'paypal'
        }).then(data => {
          if (!data.exito || !data.paypal_order_id) throw new Error(data.mensaje || 'Error PayPal');
          idPagoRef = data.idPago;
          if (data.usd) document.getElementById('paypalUsd').textContent = 'USD ' + Number(data.usd).toFixed(2);
          return data.paypal_order_id;
        });
      },
      onApprove: function(dataAppr) {
        return lsApi('payments', 'POST', {
          action: 'paypal_capture',
          idPago: idPagoRef,
          order_id: dataAppr.orderID
        }).then(res => {
          if (res.exito) {
            showToast('¡Pago completado! Tu licencia está activa.');
            closeModal('checkoutModal');
            switchSection('licenses', document.querySelector('[data-section=licenses]'));
          } else {
            showToast(res.mensaje || 'El pago no se completó', 'error');
          }
        });
      },
      onError: function() { showToast('Error al procesar el pago con PayPal', 'error'); }
    }).render('#paypalButtons');
  };

  if (paypalSdkLoaded && window.paypal) { mount(); return; }
  const s = document.createElement('script');
  s.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(gatewayConfig.paypal.client_id) + '&currency=USD&intent=capture';
  s.onload = () => { paypalSdkLoaded = true; mount(); };
  s.onerror = () => { loading.textContent = 'No se pudo cargar PayPal.'; };
  document.head.appendChild(s);
}

/* ---- MANUAL: crear orden y abrir subida de comprobante ---- */
function payManual() {
  if (!currentCheckout) return;
  lsApi('payments', 'POST', {
    action: 'create_order',
    idPlan: currentCheckout.plan.id,
    periodo: currentCheckout.periodo,
    gateway: 'manual'
  }).then(data => {
    if (data.exito) {
      closeModal('checkoutModal');
      showToast('Orden creada. Sube tu comprobante para activar la licencia.');
      switchSection('payments', document.querySelector('[data-section=payments]'));
      loadMyPayments();
      setTimeout(() => openUploadModal(data.idPago), 400);
    } else {
      showToast(data.mensaje || 'Error al crear la orden', 'error');
    }
  });
}

/* ---- Confirmación al volver de Wompi (?pago=&via=wompi) ---- */
function handleGatewayReturn() {
  const params = new URLSearchParams(window.location.search);
  const pago = params.get('pago');
  const via = params.get('via');
  if (!pago) return;

  if (via === 'wompi') {
    showToast('Verificando tu pago con Wompi…', 'info');
    lsApi('payments', 'POST', {
      action: 'wompi_confirm',
      idPago: pago,
      transaction_id: params.get('id') || ''
    }).then(res => {
      if (res.exito) {
        showToast('¡Pago aprobado! Tu licencia está activa.');
        switchSection('licenses', document.querySelector('[data-section=licenses]'));
      } else {
        showToast(res.mensaje || 'El pago no fue aprobado aún.', 'error');
        switchSection('payments', document.querySelector('[data-section=payments]'));
      }
      localStorage.removeItem('ls_pending_wompi');
      // Limpiar la URL
      window.history.replaceState({}, '', window.location.pathname);
    });
  }
}

// ---- My Payments (historial detallado) ----
function loadMyPayments() {
  lsApi('payments', 'POST', { action: 'client_list' }).then(data => {
    const body = document.getElementById('paymentsBody');
    if (!data.exito || !data.pagos || !data.pagos.length) {
      body.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:24px">No hay pagos registrados</td></tr>';
      return;
    }
    const metodoLabel = {
      WOMPI: 'Wompi', PAYPAL: 'PayPal', TRANSFERENCIA: 'Transferencia',
      NEQUI: 'Nequi', DAVIPLATA: 'Daviplata', EFECTIVO: 'Efectivo', TARJETA: 'Tarjeta'
    };
    body.innerHTML = data.pagos.map(p => {
      const moneda = p.moneda || 'COP';
      const metodo = metodoLabel[p.metodo] || (p.metodo || '—');
      const ref = p.gateway_ref || p.referencia || '—';
      return `
      <tr>
        <td class="mono">${escapeHtml(p.id)}</td>
        <td>${escapeHtml(p.plan_nombre || '—')}</td>
        <td><strong>$${(Number(p.monto)||0).toLocaleString('es-CO')}</strong> <span style="color:var(--text-muted);font-size:.75rem">${escapeHtml(moneda)}</span></td>
        <td>${escapeHtml(metodo)}</td>
        <td class="mono" style="font-size:.72rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(ref)}">${escapeHtml(ref)}</td>
        <td><span class="status-badge ${escapeHtml(String(p.estado || '').toLowerCase())}">${escapeHtml(p.estado || '')}</span></td>
        <td style="font-size:.8rem">${escapeHtml(p.fecha_pago || '—')}</td>
        <td style="font-size:.8rem">${escapeHtml(p.fecha_verificacion || '—')}</td>
        <td>${p.estado === 'PENDIENTE' ? '<button class="btn btn-sm btn-primary" onclick="openUploadModal(\''+p.id+'\')">Subir comprobante</button>' : '—'}</td>
      </tr>`;
    }).join('');
  });
}

// ---- Upload Payment ----
function openUploadModal(pagoId) {
  document.getElementById('uploadPagoId').value = pagoId;
  document.getElementById('uploadModal').style.display = 'flex';
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

// ---- Menu de gestion de licencia ACTIVA (Upgrade/Downgrade/Resetear) ----
function toggleLicenseMenu(idLic, e) {
  if (e) e.stopPropagation();
  const menu = document.getElementById('licMenu-' + idLic);
  if (!menu) return;
  const isOpen = menu.classList.contains('open');
  document.querySelectorAll('.lic-menu.open').forEach(m => m.classList.remove('open'));
  document.querySelectorAll('.lic-card.menu-open').forEach(c => c.classList.remove('menu-open'));
  if (!isOpen) {
    menu.classList.add('open');
    const card = menu.closest('.lic-card');
    if (card) card.classList.add('menu-open');
  }
}

// Cerrar el menu al hacer clic fuera de una tarjeta gestionable
document.addEventListener('click', (e) => {
  if (!e.target.closest('.lic-clickable')) {
    document.querySelectorAll('.lic-menu.open').forEach(m => m.classList.remove('open'));
    document.querySelectorAll('.lic-card.menu-open').forEach(c => c.classList.remove('menu-open'));
  }
});

// Upgrade / Downgrade: llevan al cliente a elegir el nuevo plan
function planChange(idLic, dir) {
  const el = document.querySelector('[data-section=purchase]');
  switchSection('purchase', el);
  if (dir === 'upgrade') {
    showToast('Elige un plan superior para hacer el upgrade de tu licencia.');
  } else {
    showToast('Elige un plan inferior para hacer el downgrade de tu licencia.');
  }
}

// ---- Reset de licencia (2FA por correo) ----
function openResetModal(idLic, clave) {
  document.getElementById('resetLicId').value = idLic || '';
  document.getElementById('resetClave').textContent = clave || '—';
  const btn = document.getElementById('resetConfirmBtn');
  if (btn) { btn.disabled = false; btn.textContent = 'Enviar correo de confirmación'; }
  document.getElementById('resetModal').style.display = 'flex';
}

function confirmReset(e) {
  if (e) e.preventDefault();
  const idLic = document.getElementById('resetLicId').value;
  if (!idLic) return;
  const btn = document.getElementById('resetConfirmBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }
  lsApi('licenses', 'POST', { action: 'client_reset_request', idLicencia: idLic }).then(data => {
    if (data.exito) {
      closeModal('resetModal');
      showToast(data.mensaje || 'Correo de confirmación enviado. Revisa tu bandeja.');
    } else {
      showToast(data.mensaje || 'No se pudo iniciar el reinicio.', 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Enviar correo de confirmación'; }
    }
  }).catch(() => {
    showToast('Error de conexión. Intenta de nuevo.', 'error');
    if (btn) { btn.disabled = false; btn.textContent = 'Enviar correo de confirmación'; }
  });
}

function uploadPayment(e) {
  e.preventDefault();
  const pagoId = document.getElementById('uploadPagoId').value;
  const file = document.getElementById('uploadComprobante').files[0];
  if (!file) return;

  const fd = new FormData();
  fd.append('comprobante', file);
  fd.append('metodo', document.getElementById('uploadMetodo').value);
  fd.append('notas', document.getElementById('uploadNotas').value);
  fd.append('action', 'client_upload');
  fd.append('idPago', pagoId);
  fetch(API + '/payments.php', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token },
    body: fd
  })
  .then(r => r.json())
  .then(data => {
    if (data.exito) {
      showToast('¡Comprobante enviado! Espera la aprobación del admin.');
      closeModal('uploadModal');
      loadMyPayments();
    } else {
      showToast(data.mensaje || 'Error al subir', 'error');
    }
  });
  return false;
}

// ---- Profile ----
function renderProfile() {
  const card = document.getElementById('profileCard');
  card.innerHTML = `
    <div class="profile-header">
      <div class="profile-avatar">${(user.nombre||'U')[0].toUpperCase()}</div>
      <div>
        <div class="profile-name">${user.nombre || 'Sin nombre'}</div>
        <div class="profile-email">${user.email || ''}</div>
      </div>
    </div>
    <div class="profile-field"><label>Restaurante</label><div class="profile-value">${user.restaurante || '—'}</div></div>
    <div class="profile-field"><label>Teléfono</label><div class="profile-value">${user.telefono || '—'}</div></div>
    <div class="profile-field"><label>Ciudad</label><div class="profile-value">${user.ciudad || '—'}</div></div>
    <div class="profile-field"><label>ID Cliente</label><div class="profile-value" style="font-family:'JetBrains Mono',monospace">${user.id || '—'}</div></div>
    <div class="profile-field"><label>Registro</label><div class="profile-value">${user.fecha_registro || '—'}</div></div>
  `;
}

// ---- Utilidad: escapar HTML ----
function escapeHtml(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ============================================
//  MIS SISTEMAS (multi-tenant)
// ============================================
function loadSystems() {
  const container = document.getElementById('systemsCards');
  container.innerHTML = '<div class="empty-state"><p>Cargando tus sistemas…</p></div>';
  lsApi('licenses', 'POST', { action: 'client_licenses' }).then(data => {
    if (!data.exito || !data.licencias || !data.licencias.length) {
      container.innerHTML = '<div class="empty-state"><p>Aún no tienes sistemas disponibles. Compra o activa una licencia para obtener tu instancia del POS.</p><button class="btn btn-primary" onclick="switchSection(\'purchase\',document.querySelector(\'[data-section=purchase]\'))">Comprar Licencia</button></div>';
      return;
    }
    const activas = data.licencias.filter(l => ['ACTIVA','PRUEBA'].includes(l.estado));
    if (!activas.length) {
      container.innerHTML = '<div class="empty-state"><p>No tienes licencias activas. Cuando una licencia esté activa podrás abrir su sistema desde aquí.</p></div>';
      return;
    }
    container.innerHTML = activas.map(lic => {
      const url = lic.url_instancia || '';
      const listo = !!url;
      return `
      <div class="lic-card reveal">
        <div class="lic-key">${KEY_SVG} ${escapeHtml(lic.negocio_nombre || lic.plan_nombre || 'Mi negocio')}</div>
        <div class="lic-meta">
          <span class="lic-tag plan">${escapeHtml(lic.plan_nombre || lic.plan)}</span>
          <span class="lic-tag ${statusClass(lic.estado)}">${escapeHtml(lic.estado)}</span>
        </div>
        <div class="lic-dates">Licencia: ${escapeHtml(lic.clave_licencia || '—')}</div>
        <div class="lic-actions" style="margin-top:10px">
          ${listo
            ? `<button class="btn btn-sm btn-primary" onclick="abrirSistema('${encodeURIComponent(url)}')">Ejecutar sistema</button>`
            : '<span style="color:var(--yellow);font-weight:600">&#9679; Preparando instancia…</span>'}
        </div>
      </div>`;
    }).join('');
    animateCards();
  }).catch(() => {
    container.innerHTML = '<div class="empty-state"><p>No se pudieron cargar tus sistemas.</p></div>';
  });
}

function abrirSistema(encodedUrl) {
  const url = decodeURIComponent(encodedUrl);
  window.open(url, '_blank', 'noopener');
}

// ============================================
//  SOPORTE POR TICKETS
// ============================================
function openTicketModal() {
  document.getElementById('ticketForm').reset();
  document.getElementById('ticketModal').style.display = 'flex';
}

function crearTicket(e) {
  e.preventDefault();
  const body = {
    action: 'client_create',
    asunto: document.getElementById('ticketAsunto').value.trim(),
    categoria: document.getElementById('ticketCategoria').value,
    mensaje: document.getElementById('ticketMensaje').value.trim()
  };
  lsApi('tickets', 'POST', body).then(data => {
    if (data.exito) {
      showToast('Ticket creado. Te responderemos pronto.');
      closeModal('ticketModal');
      loadTickets();
    } else {
      showToast(data.mensaje || 'No se pudo crear el ticket', 'error');
    }
  });
  return false;
}

function loadTickets() {
  const body = document.getElementById('ticketsBody');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="7" style="text-align:center;opacity:.7">Cargando…</td></tr>';
  lsApi('tickets', 'POST', { action: 'client_list' }).then(data => {
    if (!data.exito || !data.tickets || !data.tickets.length) {
      body.innerHTML = '<tr><td colspan="7" style="text-align:center;opacity:.7">Aún no has creado tickets.</td></tr>';
      return;
    }
    body.innerHTML = data.tickets.map(t => `
      <tr>
        <td>${escapeHtml(t.asunto)}</td>
        <td>${escapeHtml(t.categoria)}</td>
        <td>${escapeHtml(t.prioridad)}</td>
        <td><span class="lic-tag ${estadoTicketClass(t.estado)}">${escapeHtml(t.estado)}</span></td>
        <td>${escapeHtml(t.mensajes || 0)}</td>
        <td>${escapeHtml(t.actualizado || '—')}</td>
        <td><button class="btn btn-sm btn-outline" onclick="verTicket('${escapeHtml(t.idTicket)}')">Abrir</button></td>
      </tr>`).join('');
  });
}

function estadoTicketClass(e) {
  const m = { ABIERTO: 'pendiente', EN_PROCESO: 'activo', RESUELTO: 'expirado' };
  return m[e] || 'pendiente';
}

// ---- Estado del visor de ticket (chat) ----
let tvTicketPollTimer = null;
let tvSignature = '';
let tvAdjuntoFile = null;
let tvEstadoActual = '';

function verTicket(id) {
  tvAdjuntoFile = null;
  tvSignature = '';
  tvQuitarAdjunto();
  const ta = document.getElementById('tvReplyMensaje');
  if (ta) ta.value = '';
  document.getElementById('tvTicketId').value = id;
  document.getElementById('ticketViewModal').style.display = 'flex';
  tvFetch(id, false);
  if (tvTicketPollTimer) clearInterval(tvTicketPollTimer);
  tvTicketPollTimer = setInterval(() => {
    const modal = document.getElementById('ticketViewModal');
    if (!modal || modal.style.display === 'none') { clearInterval(tvTicketPollTimer); tvTicketPollTimer = null; return; }
    tvFetch(id, true);
  }, 4000);
}

function tvFetch(id, silent) {
  lsApi('tickets', 'POST', { action: 'client_get', idTicket: id }).then(data => {
    if (!data.exito) { if (!silent) showToast(data.mensaje || 'No encontrado', 'error'); return; }
    tvRender(id, data);
  }).catch(() => {});
}

function tvRender(id, data) {
  const msgs = data.mensajes || [];
  const estado = (data.ticket && data.ticket.estado) || '';
  // Firma para re-renderizar solo cuando cambia algo (evita perder scroll/typing)
  const sig = estado + '|' + msgs.length + '|' + (msgs.length ? (msgs[msgs.length - 1].creado || '') : '') + '|' + (data.whatsapp || '');
  const cambio = sig !== tvSignature;
  tvSignature = sig;
  tvEstadoActual = estado;

  document.getElementById('tvTitulo').textContent = data.ticket.asunto;
  document.getElementById('tvMeta').textContent =
    'Estado: ' + estado + ' · Prioridad: ' + (data.ticket.prioridad || '—') + ' · ' + (data.ticket.categoria || '');

  const cont = document.getElementById('tvMensajes');
  if (cambio) {
    const nearBottom = (cont.scrollHeight - cont.scrollTop - cont.clientHeight) < 80;
    cont.innerHTML = msgs.map(m => {
      const mio = m.autorTipo === 'CLIENTE';
      const quien = mio ? 'Tú' : 'Soporte';
      const burbuja = mio
        ? 'linear-gradient(135deg,var(--primary),var(--accent))'
        : 'var(--bg-card)';
      return '<div style="align-self:' + (mio ? 'flex-end' : 'flex-start') + ';max-width:82%;background:' + burbuja +
        ';color:' + (mio ? '#fff' : 'inherit') + ';border:1px solid var(--border);border-radius:12px;padding:8px 12px">' +
        '<div style="font-size:.7rem;opacity:.75;margin-bottom:3px">' + quien + ' · ' + escapeHtml(m.creado || '') + '</div>' +
        (m.mensaje ? '<div style="white-space:pre-wrap;word-break:break-word">' + escapeHtml(m.mensaje) + '</div>' : '') +
        tvAdjuntoHtml(m) +
        '</div>';
    }).join('');
    if (nearBottom) cont.scrollTop = cont.scrollHeight;
  }

  // WhatsApp SOLO si el equipo de soporte escalo este ticket.
  const waBox = document.getElementById('tvWhatsapp');
  if (data.whatsapp) {
    waBox.style.display = 'block';
    const waUrl = 'https://wa.me/' + encodeURIComponent(data.whatsapp) + '?text=' + encodeURIComponent('Hola, escribo por mi ticket ' + id);
    waBox.innerHTML = '<div class="support-card" style="padding:12px"><p style="margin:0 0 8px">El equipo habilitó la atención por WhatsApp para este caso.</p><a href="' + waUrl + '" target="_blank" rel="noopener" class="btn btn-primary">Continuar por WhatsApp</a></div>';
  } else {
    waBox.style.display = 'none';
    waBox.innerHTML = '';
  }

  // Bloqueo de composer si el ticket esta resuelto
  const resuelto = estado === 'RESUELTO';
  document.getElementById('tvResuelto').style.display = resuelto ? 'block' : 'none';
  document.getElementById('tvReplyForm').style.display = resuelto ? 'none' : 'block';
}

// Renderiza el adjunto (imagen embebida o enlace de archivo) de un mensaje
function tvAdjuntoHtml(m) {
  if (!m.adjunto_url) return '';
  const url = API + '/' + m.adjunto_url + '&tk=' + encodeURIComponent(token);
  const nombre = escapeHtml(m.adjunto_nombre || 'archivo');
  if ((m.adjunto_tipo || '').indexOf('image/') === 0) {
    return '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener">' +
      '<img src="' + escapeHtml(url) + '" alt="' + nombre + '" style="margin-top:6px;max-width:220px;max-height:220px;border-radius:10px;display:block"></a>';
  }
  return '<a href="' + escapeHtml(url) + '&dl=1" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:.82rem;text-decoration:underline">📎 ' + nombre + '</a>';
}

function responderTicket(e) {
  e.preventDefault();
  const id = document.getElementById('tvTicketId').value;
  const mensaje = document.getElementById('tvReplyMensaje').value.trim();
  if (tvEstadoActual === 'RESUELTO') { showToast('El ticket está resuelto. No se pueden enviar mensajes.', 'error'); return false; }
  if (!mensaje && !tvAdjuntoFile) { showToast('Escribe un mensaje o adjunta un archivo', 'error'); return false; }

  const fd = new FormData();
  fd.append('action', 'client_reply');
  fd.append('idTicket', id);
  fd.append('mensaje', mensaje);
  if (tvAdjuntoFile) fd.append('adjunto', tvAdjuntoFile);

  fetch(API + '/tickets.php', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token },
    body: fd
  }).then(r => r.json()).then(data => {
    if (data.exito) {
      document.getElementById('tvReplyMensaje').value = '';
      tvQuitarAdjunto();
      tvSignature = '';
      tvFetch(id, true);
      loadTickets();
    } else {
      showToast(data.mensaje || 'No se pudo enviar', 'error');
    }
  }).catch(() => showToast('No se pudo enviar', 'error'));
  return false;
}

// ---- Adjuntos del composer ----
function tvArchivoSeleccionado(inputEl) {
  const f = inputEl.files && inputEl.files[0];
  if (!f) return;
  if (f.size > 8 * 1024 * 1024) { showToast('El archivo supera el límite de 8 MB', 'error'); inputEl.value = ''; return; }
  tvAdjuntoFile = f;
  document.getElementById('tvAdjuntoNombre').textContent = f.name;
  document.getElementById('tvAdjuntoPreview').style.display = 'flex';
  inputEl.value = '';
}

function tvQuitarAdjunto() {
  tvAdjuntoFile = null;
  const p = document.getElementById('tvAdjuntoPreview');
  if (p) p.style.display = 'none';
  const n = document.getElementById('tvAdjuntoNombre');
  if (n) n.textContent = '';
}

// ---- Emojis ----
const TV_EMOJIS = ['😀','😄','😊','😉','😍','😅','😂','🤔','😴','😎','👍','👎','🙏','👏','🙌','💪','✅','❌','⚠️','❗','❓','🔥','⭐','🎉','💡','📎','🖼️','📄','💰','🕒','😢','😡'];
function tvToggleEmojis(e) {
  if (e) e.stopPropagation();
  const panel = document.getElementById('tvEmojiPanel');
  if (!panel) return;
  if (panel.style.display !== 'none' && panel.style.display !== '') { panel.style.display = 'none'; return; }
  if (!panel.dataset.filled) {
    panel.innerHTML = TV_EMOJIS.map(em =>
      '<button type="button" style="border:none;background:transparent;font-size:1.2rem;cursor:pointer;padding:3px" onclick="tvInsertEmoji(\'' + em + '\')">' + em + '</button>'
    ).join('');
    panel.dataset.filled = '1';
  }
  panel.style.display = 'grid';
  panel.style.gridTemplateColumns = 'repeat(8,1fr)';
}
function tvInsertEmoji(em) {
  const ta = document.getElementById('tvReplyMensaje');
  if (!ta) return;
  const s = ta.selectionStart || ta.value.length;
  const en = ta.selectionEnd || ta.value.length;
  ta.value = ta.value.slice(0, s) + em + ta.value.slice(en);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = s + em.length;
  document.getElementById('tvEmojiPanel').style.display = 'none';
}

function cerrarTicketView() {
  if (tvTicketPollTimer) { clearInterval(tvTicketPollTimer); tvTicketPollTimer = null; }
  const p = document.getElementById('tvEmojiPanel');
  if (p) p.style.display = 'none';
  closeModal('ticketViewModal');
}

// ---- Notifications ----
function loadNotifications() {
  const panel = document.getElementById('notifPanel');
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  document.getElementById('notifBody').innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-d)">No hay notificaciones nuevas</div>';
}

// ---- GSAP Animations ----
function animateCards() {
  applyReveal(document);
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', () => {
  loadLicenses();
  loadGatewayConfig().then(() => {
    handleGatewayReturn();
  });
});

// Handle hash for direct purchase
if (window.location.hash.startsWith('#purchase-')) {
  const planId = window.location.hash.replace('#purchase-', '');
  setTimeout(() => {
    switchSection('purchase', document.querySelector('[data-section=purchase]'));
    purchasePlan(planId);
  }, 600);
}
