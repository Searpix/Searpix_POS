/* ============================================
   Comandix — Admin Dashboard JS
   ============================================ */

const API = '../api';

// ---- Auth Guard ----
const token = localStorage.getItem('ls_token');
const userType = localStorage.getItem('ls_type');
if (!token || userType !== 'admin') {
  window.location.href = 'login';
}

const admin = JSON.parse(localStorage.getItem('ls_user') || '{}');
let adminTicketPollTimer = null;
document.getElementById('adminName').textContent = admin.usuario || admin.nombre || 'Admin';

// ---- SVG icons (sin emoji, siempre se ven) ----
const SVG = {
  key: '<svg viewBox="0 0 24 24" fill="none" stroke="#c9b8ff" stroke-width="1.8"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.5 12.5 8-8m-2 2 2 2m-4 0 2 2"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="#FBBF24" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  x: '<svg viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg>',
  flask: '<svg viewBox="0 0 24 24" fill="none" stroke="#38BDF8" stroke-width="1.8"><path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-9V3"/></svg>',
  cash: '<svg viewBox="0 0 24 24" fill="none" stroke="#4ADE80" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>',
  cart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-.15em"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
  receipt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-.15em"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1-2-1z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
  pencil: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-.15em"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-.15em"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'
};

// ---- Brand logo fallback (si logo.png no existe aún) ----
document.addEventListener('DOMContentLoaded', () => {
  const logo = document.getElementById('brandLogo');
  if (logo) logo.addEventListener('error', () => {
    const span = document.createElement('span');
    span.className = 'nav-logo-icon';
    span.textContent = 'Cx';
    span.style.fontSize = '1rem';
    span.style.fontWeight = '900';
    logo.replaceWith(span);
  });
});

// ---- Scroll reveal ----
const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); revealObserver.unobserve(e.target); } });
}, { threshold: 0.12 });
function applyReveal(scope) {
  (scope || document).querySelectorAll('.reveal:not(.in)').forEach(el => revealObserver.observe(el));
}

// ---- Animated counter ----
function animateCount(el, to) {
  to = Number(to) || 0;
  const dur = 900, start = performance.now();
  const isMoney = el.dataset.money === '1';
  function step(now) {
    const p = Math.min(1, (now - start) / dur);
    const val = Math.round(to * (1 - Math.pow(1 - p, 3)));
    el.textContent = isMoney ? formatCOP(val) : val.toLocaleString('es-CO');
    if (p < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

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
  const url = API + '/' + ep;
  return fetch(url, opts).then(r => r.json());
}

// ---- Toast ----
function showToast(msg, type = 'success') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  t.style.cssText = 'position:fixed;top:20px;right:20px;padding:14px 24px;border-radius:12px;font-size:0.9rem;font-weight:600;z-index:10000;animation:slideIn 0.3s ease;max-width:90vw;';
  if (type === 'error') {
    t.style.background = 'linear-gradient(135deg,#7f1d1d,#991b1b)';
    t.style.color = '#fca5a5';
    t.style.border = '1px solid rgba(239,68,68,0.3)';
  } else {
    t.style.background = 'linear-gradient(135deg,#064e3b,#065f46)';
    t.style.color = '#6ee7b7';
    t.style.border = '1px solid rgba(16,185,129,0.3)';
  }
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 4000);
}

// ---- Section Switch ----
function switchSection(name, el) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-' + name).classList.add('active');
  if (el) el.classList.add('active');

  const titles = {
    overview: 'Dashboard',
    licenses: 'Licencias',
    payments: 'Pagos',
    clients: 'Clientes',
    history: 'Historial',
    config: 'Configuración',
    tickets: 'Tickets de soporte'
  };
  document.getElementById('pageTitle').textContent = titles[name] || name;

  if (name === 'overview') loadOverview();
  if (name === 'licenses') loadLicenses();
  if (name === 'payments') loadPayments();
  if (name === 'clients') loadClients();
  if (name === 'history') loadHistory();
  if (name === 'config') loadConfig();
  if (name === 'tickets') {
    loadAdminTickets();
    if (!adminTicketPollTimer) adminTicketPollTimer = setInterval(() => {
      const sec = document.getElementById('sec-tickets');
      if (sec && sec.classList.contains('active')) loadAdminTickets();
    }, 5000);
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

// ---- Overview ----
function loadOverview() {
  lsApi('licenses', 'POST', { action: 'admin_stats' }).then(data => {
    if (!data.exito) return;
    const s = data.estadisticas;

    const kpiGrid = document.getElementById('kpiGrid');
    kpiGrid.innerHTML = `
      <div class="kpi-card reveal">
        <div class="kpi-icon">${SVG.key}</div>
        <div class="kpi-value gradient-text" data-count="${s.total_licencias || 0}">0</div>
        <div class="kpi-label">Total Licencias</div>
      </div>
      <div class="kpi-card reveal">
        <div class="kpi-icon">${SVG.check}</div>
        <div class="kpi-value" style="color:var(--green)" data-count="${s.activas || 0}">0</div>
        <div class="kpi-label">Activas</div>
      </div>
      <div class="kpi-card reveal">
        <div class="kpi-icon">${SVG.clock}</div>
        <div class="kpi-value" style="color:var(--yellow)" data-count="${s.pendientes || 0}">0</div>
        <div class="kpi-label">Pendientes</div>
      </div>
      <div class="kpi-card reveal">
        <div class="kpi-icon">${SVG.x}</div>
        <div class="kpi-value" style="color:var(--red)" data-count="${s.expiradas || 0}">0</div>
        <div class="kpi-label">Expiradas</div>
      </div>
      <div class="kpi-card reveal">
        <div class="kpi-icon">${SVG.flask}</div>
        <div class="kpi-value" style="color:var(--blue)" data-count="${s.prueba || 0}">0</div>
        <div class="kpi-label">Prueba</div>
      </div>
      <div class="kpi-card reveal">
        <div class="kpi-icon">${SVG.cash}</div>
        <div class="kpi-value" style="color:var(--green)" data-money="1" data-count="${s.ingresos_mes || 0}">$0</div>
        <div class="kpi-label">Ingresos Mes</div>
      </div>
    `;

    applyReveal(kpiGrid);
    kpiGrid.querySelectorAll('.kpi-value[data-count]').forEach(el => animateCount(el, el.dataset.count));

    renderCharts(s);
  });
}

function formatCOP(n) {
  return '$' + Number(n).toLocaleString('es-CO');
}

// ============ Charts propios (SVG/CSS, sin librerías) ============
function renderCharts(stats) {
  // ---- Donut de estados ----
  const segs = [
    { label: 'Activa', val: stats.activas || 0, color: '#4ADE80' },
    { label: 'Pendiente', val: stats.pendientes || 0, color: '#FBBF24' },
    { label: 'Expirada', val: stats.expiradas || 0, color: '#EF4444' },
    { label: 'Prueba', val: stats.prueba || 0, color: '#38BDF8' },
    { label: 'Suspendida', val: stats.suspendidas || 0, color: '#F97316' }
  ];
  const total = segs.reduce((a, s) => a + s.val, 0);
  const donut = document.getElementById('donutEstado');
  const legend = document.getElementById('donutLegend');
  document.getElementById('donutTotal').textContent = total;

  if (total === 0) {
    donut.style.background = 'conic-gradient(rgba(255,255,255,.06) 0 100%)';
  } else {
    let acc = 0, stops = [];
    segs.forEach(s => {
      if (s.val === 0) return;
      const from = (acc / total) * 100;
      acc += s.val;
      const to = (acc / total) * 100;
      stops.push(`${s.color} ${from}% ${to}%`);
    });
    donut.style.background = `conic-gradient(${stops.join(',')})`;
  }
  legend.innerHTML = segs.map(s => `
    <div class="lg"><span class="dot" style="background:${s.color}"></span>${s.label}<b>${s.val}</b></div>
  `).join('');

  // ---- Barras por plan ----
  const pp = stats.por_plan || {};
  const planos = [
    { label: 'Básico', val: pp.BASICO || 0 },
    { label: 'Pro', val: pp.PROFESIONAL || 0 },
    { label: 'Enterprise', val: pp.ENTERPRISE || 0 }
  ];
  const max = Math.max(1, ...planos.map(p => p.val));
  const bars = document.getElementById('barsPlan');
  bars.innerHTML = planos.map(p => `
    <div class="bar-col">
      <span class="bar-val">${p.val}</span>
      <div class="bar-track"><div class="bar" data-h="${Math.round((p.val / max) * 100)}" style="height:0"></div></div>
      <span class="bar-label">${p.label}</span>
    </div>
  `).join('');
  requestAnimationFrame(() => {
    setTimeout(() => bars.querySelectorAll('.bar').forEach(b => { b.style.height = b.dataset.h + '%'; }), 60);
  });
}

// ---- Licenses ----
let licPage = 1;
const licLimit = 15;
let licCache = [];

function loadLicenses() {
  const search = document.getElementById('licSearch').value;
  const filter = document.getElementById('licFilter').value;
  lsApi('licenses', 'POST', {
    action: 'admin_list',
    page: licPage,
    limit: licLimit,
    search: search,
    estado: filter
  }).then(data => {
    const body = document.getElementById('licBody');
    if (!data.exito) { body.innerHTML = '<tr><td colspan="7">Error al cargar</td></tr>'; return; }
    licCache = data.licencias || [];
    body.innerHTML = data.licencias.map(l => `
      <tr>
        <td class="mono">${tkEscape(l.clave_activacion || l.clave_licencia || '')}</td>
        <td>${tkEscape(l.cliente_nombre || '—')}</td>
        <td>${tkEscape(l.plan || l.plan_nombre || '—')}</td>
        <td><span class="status-badge ${tkEscape(String(l.estado || '').toLowerCase())}">${tkEscape(l.estado || '')}</span></td>
        <td>${l.activaciones}/${l.max_activaciones}</td>
        <td>${tkEscape(l.fecha_expiracion || '—')}</td>
        <td>
          <button class="btn btn-sm btn-outline" onclick="openEditLicense('${l.idLicencia}')">Editar</button>
          <button class="btn btn-sm btn-outline" onclick="verInstancia('${l.idLicencia}')">Instancia</button>
        </td>
      </tr>
    `).join('');

    const totalPages = Math.ceil((data.total || 0) / licLimit);
    const pag = document.getElementById('licPagination');
    pag.innerHTML = '';
    for (let i = 1; i <= totalPages; i++) {
      pag.innerHTML += `<button class="${i===licPage?'active':''}" onclick="licPage=${i};loadLicenses()">${i}</button>`;
    }
  });
}

function searchLicenses() {
  licPage = 1;
  loadLicenses();
}

// ---- Create License ----
let clientsCache = [];

function loadClientsDropdown(preselect) {
  return lsApi('licenses', 'POST', { action: 'admin_clients' }).then(data => {
    if (!data.exito) return;
    clientsCache = data.clientes || [];
    const sel = document.getElementById('newLicCliente');
    sel.innerHTML = '<option value="">Selecciona un cliente…</option>' + clientsCache.map(c =>
      `<option value="${tkEscape(c.idCliente)}">${c.nombre}${c.empresa ? ' — ' + c.empresa : ''} (${c.email})</option>`
    ).join('');
    if (preselect) sel.value = preselect;
  });
}

function openCreateLicenseModal() {
  loadClientsDropdown();
  document.getElementById('createLicModal').style.display = 'flex';
}

function openCreateClientModal() {
  document.getElementById('createClientModal').style.display = 'flex';
}

function createClient(e) {
  e.preventDefault();
  lsApi('licenses', 'POST', {
    action: 'admin_create_client',
    nombre: document.getElementById('newCliNombre').value,
    email: document.getElementById('newCliEmail').value,
    telefono: document.getElementById('newCliTelefono').value,
    empresa: document.getElementById('newCliEmpresa').value,
    ciudad: document.getElementById('newCliCiudad').value
  }).then(data => {
    if (data.exito) {
      showToast('Cliente creado: ' + data.cliente.nombre);
      closeModal('createClientModal');
      document.getElementById('createClientForm').reset();
      // Recargar el selector y preseleccionar el nuevo cliente
      loadClientsDropdown(data.cliente.idCliente);
    } else {
      showToast(data.mensaje || 'Error al crear cliente', 'error');
    }
  });
  return false;
}

function createLicense(e) {
  e.preventDefault();
  lsApi('licenses', 'POST', {
    action: 'admin_create',
    idCliente: document.getElementById('newLicCliente').value,
    idPlan: document.getElementById('newLicPlan').value,
    negocio_nombre: document.getElementById('newLicNegocio').value,
    duracion_meses: parseInt(document.getElementById('newLicDuracion').value),
    max_activaciones: parseInt(document.getElementById('newLicDevices').value),
    notas: document.getElementById('newLicNotas').value
  }).then(data => {
    if (data.exito) {
      showToast('Licencia creada: ' + data.clave_licencia);
      closeModal('createLicModal');
      loadLicenses();
      mostrarInstanciaCreada(data);
    } else {
      showToast(data.mensaje || 'Error al crear', 'error');
    }
  });
  return false;
}

// ---- Edit License ----
function openEditLicense(licId) {
  // 1) Intentar tomar la licencia de la lista ya cargada (mas fiable).
  let l = (licCache || []).find(x => x.idLicencia === licId);
  if (l) { fillEditLicense(l); return; }
  // 2) Respaldo: pedirla al servidor por su ID exacto.
  lsApi('licenses', 'POST', { action: 'admin_get', idLicencia: licId }).then(data => {
    if (!data.exito || !data.licencia) {
      showToast(data.mensaje || 'No se encontro la licencia', 'error');
      return;
    }
    fillEditLicense(data.licencia);
  });
}

function fillEditLicense(l) {
  document.getElementById('editLicId').value = l.idLicencia;
  document.getElementById('editLicEstado').value = l.estado;
  document.getElementById('editLicDevices').value = l.max_activaciones;
  document.getElementById('editLicExpira').value = l.fecha_expiracion ? String(l.fecha_expiracion).split(' ')[0] : '';
  document.getElementById('editLicNotas').value = l.motivo_estado || '';
  document.getElementById('editLicModal').style.display = 'flex';
}

function updateLicense(e) {
  e.preventDefault();
  lsApi('licenses', 'POST', {
    action: 'admin_update',
    idLicencia: document.getElementById('editLicId').value,
    estado: document.getElementById('editLicEstado').value,
    max_activaciones: parseInt(document.getElementById('editLicDevices').value),
    fecha_expiracion: document.getElementById('editLicExpira').value,
    notas: document.getElementById('editLicNotas').value
  }).then(data => {
    if (data.exito) {
      showToast('Licencia actualizada');
      closeModal('editLicModal');
      loadLicenses();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  });
  return false;
}

// ===================== Instancias POS (BD por licencia) =====================
// Muestra el resultado del aprovisionamiento tras crear una licencia:
// URL de la instancia y credenciales iniciales (una sola vez).
function mostrarInstanciaCreada(data) {
  if (!data) return;
  const url = data.url_instancia || '';
  const cred = data.credenciales_iniciales || null;
  let html = '';
  if (url) {
    html += `<p style="margin:.4rem 0">Instancia POS lista en:</p>
      <p><a href="${tkEscape(url)}" target="_blank" rel="noopener noreferrer" class="mono">${tkEscape(url)}</a></p>`;
  } else {
    html += `<p style="color:var(--accent)">La instancia POS aun no se aprovisiono. Usa el boton "Instancia" para reintentar.</p>`;
  }
  if (cred && cred.usuario) {
    html += `<div style="margin-top:.8rem;padding:.7rem .9rem;background:rgba(108,60,225,.08);border-radius:10px">
      <p style="margin:0 0 .3rem"><strong>Credenciales iniciales</strong> (mostrar una sola vez)</p>
      <p style="margin:.15rem 0">Usuario: <span class="mono">${tkEscape(cred.usuario)}</span></p>
      <p style="margin:.15rem 0">Contrase\u00f1a: <span class="mono">${tkEscape(cred.contrasena || '')}</span></p>
      <p style="margin:.3rem 0 0;font-size:.8rem;color:var(--text-muted)">${tkEscape(cred.nota || '')}</p>
    </div>`;
  }
  abrirInstanciaModal('Instancia creada', html);
}

// Consulta el estado de la instancia de una licencia y ofrece (re)aprovisionar.
function verInstancia(licId) {
  lsApi('licenses', 'POST', { action: 'admin_instance', idLicencia: licId }).then(data => {
    let html = '';
    if (data.exito && data.aprovisionada && data.instancia) {
      const i = data.instancia;
      html += `<p style="margin:.2rem 0">Estado: <strong>${tkEscape(i.estado || 'ACTIVO')}</strong></p>
        <p style="margin:.2rem 0">Base de datos: <span class="mono">${tkEscape(i.db_name || '')}</span></p>
        <p style="margin:.2rem 0">URL: <a href="${tkEscape(i.base_url || '')}" target="_blank" rel="noopener noreferrer" class="mono">${tkEscape(i.base_url || '')}</a></p>
        <p style="margin:.2rem 0;font-size:.8rem;color:var(--text-muted)">Aprovisionada: ${tkEscape(i.provisioned_at || '—')}</p>`;
    } else {
      html += `<p style="color:var(--accent)">Esta licencia todav\u00eda no tiene su base de datos ni instancia POS.</p>`;
    }
    html += `<div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn btn-sm btn-primary" onclick="provisionInstancia('${licId}')">${data.aprovisionada ? 'Reaprovisionar (nuevas credenciales)' : 'Crear base de datos e instancia'}</button>
    </div>
    <p style="margin:.9rem 0 0;font-size:.72rem;color:var(--text-muted)">m\u00f3dulo instancias v3</p>`;
    abrirInstanciaModal('Instancia POS de la licencia', html);
  });
}

// Lanza (o reintenta) el aprovisionamiento: crea la BD dedicada + copia del POS.
// Usa fetch directo para poder mostrar errores HTTP/PHP reales (500, no-JSON,
// "Acci\u00f3n no v\u00e1lida" = PHP sin actualizar), en vez de fallar en silencio.
async function provisionInstancia(licId) {
  const box = document.getElementById('instanceModalBody');
  if (box) box.innerHTML = '<p>Aprovisionando la base de datos e instancia\u2026 esto puede tardar unos segundos.</p>';
  let resp, raw;
  try {
    resp = await fetch(API + '/licenses.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ action: 'admin_provision', idLicencia: licId })
    });
    raw = await resp.text();
  } catch (e) {
    const m = 'No se pudo contactar al servidor (' + (e && e.message ? e.message : 'red') + ').';
    showToast(m, 'error');
    if (box) box.innerHTML = `<p style="color:var(--accent)">${tkEscape(m)}</p>`;
    return;
  }
  let data = null;
  try { data = JSON.parse(raw); } catch (e) { data = null; }
  if (!data) {
    const m = 'El servidor respondi\u00f3 (HTTP ' + resp.status + ') algo que no es JSON. Suele indicar un error de PHP. Revisa el log de PHP en XAMPP.';
    showToast('Error del servidor (HTTP ' + resp.status + ')', 'error');
    if (box) box.innerHTML = `<p style="color:var(--accent)">${tkEscape(m)}</p><pre style="white-space:pre-wrap;font-size:.72rem;max-height:180px;overflow:auto;background:rgba(0,0,0,.25);padding:.6rem;border-radius:8px">${tkEscape(raw.slice(0, 800))}</pre>`;
    return;
  }
  if (data.mensaje === 'Acci\u00f3n no v\u00e1lida.' || data.mensaje === 'Acción no válida.') {
    const m = 'El servidor no reconoce la acci\u00f3n "admin_provision". Los archivos PHP (api/licenses.php) NO se actualizaron en XAMPP. Vuelve a copiar la carpeta api del zip nuevo.';
    showToast('PHP desactualizado', 'error');
    if (box) box.innerHTML = `<p style="color:var(--accent)">${tkEscape(m)}</p>`;
    return;
  }
  if (data.exito) {
    showToast('Instancia POS lista');
    mostrarInstanciaCreada(data);
    loadLicenses();
  } else {
    showToast(data.mensaje || 'Error al aprovisionar', 'error');
    if (box) box.innerHTML = `<p style="color:var(--accent)">${tkEscape(data.mensaje || 'Error al aprovisionar')}</p>`;
  }
}

function abrirInstanciaModal(titulo, htmlBody) {
  let modal = document.getElementById('instanceModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'instanceModal';
    modal.className = 'modal';
    modal.style.display = 'none';
    modal.innerHTML = `
      <div class="modal-content" style="max-width:520px">
        <div class="modal-header">
          <h3 id="instanceModalTitle">Instancia POS</h3>
          <button class="modal-close" onclick="closeModal('instanceModal')">&times;</button>
        </div>
        <div class="modal-body" id="instanceModalBody"></div>
      </div>`;
    document.body.appendChild(modal);
  }
  document.getElementById('instanceModalTitle').textContent = titulo;
  document.getElementById('instanceModalBody').innerHTML = htmlBody;
  modal.style.display = 'flex';
}

async function verComprobante(file) {
  try {
    const res = await fetch(API + '/comprobante.php?file=' + encodeURIComponent(file), {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    if (!res.ok) throw new Error('No autorizado');
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    window.open(url, '_blank', 'noopener,noreferrer');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) { showToast('No se pudo abrir el comprobante.', 'error'); }
}

// ---- Payments (historial detallado) ----
function loadPayments() {
  const filter = document.getElementById('payFilter').value;
  lsApi('payments', 'POST', { action: 'admin_list', estado: filter }).then(data => {
    const body = document.getElementById('payBody');
    if (!data.exito || !data.pagos || !data.pagos.length) { body.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--text-muted);padding:24px">Sin pagos</td></tr>'; return; }
    body.innerHTML = data.pagos.map(p => {
      const moneda = p.moneda || 'COP';
      const ref = p.gateway_ref || p.referencia || '—';
      return `
      <tr>
        <td class="mono">${tkEscape(p.idPago)}</td>
        <td>${tkEscape(p.cliente_nombre || '—')}</td>
        <td>${tkEscape(p.plan || '—')}</td>
        <td><strong>${formatCOP(p.monto)}</strong> <span style="color:var(--text-muted);font-size:.72rem">${tkEscape(moneda)}</span></td>
        <td>${tkEscape(p.metodo || '—')}</td>
        <td class="mono" style="font-size:.72rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${tkEscape(ref)}">${tkEscape(ref)}</td>
        <td><span class="status-badge ${tkEscape(String(p.estado || '').toLowerCase())}">${tkEscape(p.estado || '')}</span></td>
        <td style="font-size:.8rem">${tkEscape(p.fecha_pago || '—')}</td>
        <td style="font-size:.8rem">${tkEscape(p.fecha_verificacion || '—')}</td>
        <td>
          ${p.comprobante ? `<a class="btn btn-sm btn-outline" href="#" data-file="${tkEscape(p.comprobante)}" onclick="verComprobante(this.dataset.file);return false">Ver comprobante</a>` : ''}
          ${p.estado === 'PENDIENTE' ? `
            <button class="btn btn-sm btn-success" onclick="approvePayment('${tkEscape(p.idPago)}')">&#10003; Aprobar</button>
            <button class="btn btn-sm btn-danger" onclick="rejectPayment('${tkEscape(p.idPago)}')">&#10007; Rechazar</button>
          ` : ''}
          ${(!p.comprobante && p.estado !== 'PENDIENTE') ? '—' : ''}
        </td>
      </tr>`;
    }).join('');
  });
}

// ---- History (auditoría / LICENCIA_LOG) ----
function loadHistory() {
  const body = document.getElementById('historyBody');
  body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">Cargando…</td></tr>';
  lsApi('payments', 'POST', { action: 'admin_history', limit: 150 }).then(data => {
    if (!data.exito || !data.eventos || !data.eventos.length) {
      body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">Sin actividad registrada</td></tr>';
      return;
    }
    const iconAccion = {
      ORDER_CREATE: SVG.cart, PAYMENT_UPLOAD: SVG.receipt, PAYMENT_APPROVE: '&#10003;', PAYMENT_REJECT: '&#10007;',
      LICENSE_CREATE: SVG.key, LICENSE_UPDATE: SVG.pencil, LOGIN: SVG.lock,
      WEBHOOK_WOMPI: '&#10003;', WEBHOOK_PAYPAL: '&#10003;'
    };
    body.innerHTML = data.eventos.map(e => {
      const ic = iconAccion[e.accion] || '•';
      return `
      <tr>
        <td style="font-size:.8rem;white-space:nowrap">${tkEscape(e.fecha || '—')}</td>
        <td><span class="status-badge" style="font-size:.7rem">${ic} ${tkEscape(e.accion || '—')}</span></td>
        <td style="font-size:.82rem;max-width:320px">${tkEscape(e.descripcion || '—')}</td>
        <td style="font-size:.8rem">${tkEscape(e.cliente_nombre || '—')}</td>
        <td style="font-size:.8rem">${tkEscape(e.admin_nombre || '—')}</td>
        <td class="mono" style="font-size:.72rem">${tkEscape(e.clave_activacion || e.idLicencia || '—')}</td>
        <td class="mono" style="font-size:.72rem">${tkEscape(e.ip || '—')}</td>
      </tr>`;
    }).join('');
  });
}

function approvePayment(pagoId) {
  if (!confirm('¿Aprobar este pago y activar la licencia?')) return;
  lsApi('payments', 'POST', { action: 'admin_approve', idPago: pagoId }).then(data => {
    if (data.exito) {
      showToast('¡Pago aprobado y licencia activada!');
      loadPayments();
      loadOverview();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  });
}

function rejectPayment(pagoId) {
  const motivo = prompt('Motivo del rechazo (opcional):');
  lsApi('payments', 'POST', { action: 'admin_reject', idPago: pagoId, motivo: motivo || '' }).then(data => {
    if (data.exito) {
      showToast('Pago rechazado', 'error');
      loadPayments();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  });
}

// ---- Clients ----
function loadClients() {
  lsApi('licenses', 'POST', { action: 'admin_clients' }).then(data => {
    const body = document.getElementById('clientBody');
    if (!data.exito || !data.clientes || !data.clientes.length) {
      body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">Sin clientes registrados</td></tr>';
      return;
    }
    body.innerHTML = data.clientes.map(c => `
      <tr>
        <td class="mono">${tkEscape(c.idCliente)}</td>
        <td>${tkEscape(c.nombre || '—')}</td>
        <td>${tkEscape(c.empresa || '—')}</td>
        <td>${tkEscape(c.email || '—')}</td>
        <td>${tkEscape(c.ciudad || '—')}</td>
        <td>${c.total_licencias || 0}</td>
        <td style="font-size:.8rem">${c.fechaRegistro || '—'}</td>
      </tr>
    `).join('');
  });
}

// ---- Config ----
function loadConfig() {
  document.getElementById('cfgNombre').value = 'Comandix';
  document.getElementById('cfgServer').value = window.location.origin + '/license-server/api';
  document.getElementById('cfgWhatsApp').value = '573235636580';
  document.getElementById('cfgEmail').value = 'searpix@gmail.com';
}

function saveConfig(e) {
  e.preventDefault();
  showToast('Configuración guardada');
  return false;
}

// ---- Modal Helper ----
function closeModal(id) {
  document.getElementById(id).style.display = 'none';
  if (id === 'adminTicketModal') {
    if (typeof atViewPollTimer !== 'undefined' && atViewPollTimer) { clearInterval(atViewPollTimer); atViewPollTimer = null; }
    const p = document.getElementById('adminEmojiPanel');
    if (p) p.style.display = 'none';
  }
}

// ---- Tickets de soporte (admin) ----
function tkEscape(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function tkEstadoBadge(estado) {
  const map = {
    ABIERTO: '#f59e0b', EN_PROCESO: '#3b82f6', RESUELTO: '#22c55e'
  };
  const c = map[estado] || '#64748b';
  return '<span class="status-badge" style="font-size:.7rem;color:' + c + ';border-color:' + c + '">' + tkEscape(estado || '—') + '</span>';
}

function loadAdminTickets() {
  const body = document.getElementById('adminTicketsBody');
  if (!body) return;
  const estado = (document.getElementById('ticketFilter') || {}).value || '';
  body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px">Cargando…</td></tr>';
  lsApi('tickets', 'POST', { action: 'admin_list', estado: estado }).then(data => {
    if (!data.exito || !data.tickets || !data.tickets.length) {
      body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px">Sin tickets</td></tr>';
      return;
    }
    body.innerHTML = data.tickets.map(t => {
      const esc = Number(t.escalado) === 1;
      return '<tr>' +
        '<td style="font-size:.85rem;max-width:220px">' + tkEscape(t.asunto) + '</td>' +
        '<td style="font-size:.8rem">' + tkEscape(t.cliente || '—') + '</td>' +
        '<td style="font-size:.78rem">' + tkEscape(t.categoria || '—') + '</td>' +
        '<td style="font-size:.78rem">' + tkEscape(t.prioridad || '—') + '</td>' +
        '<td>' + tkEstadoBadge(t.estado) + '</td>' +
        '<td style="font-size:.75rem">' + (esc ? '<span style="color:#22c55e">Habilitado</span>' : '<span style="color:var(--text-muted)">—</span>') + '</td>' +
        '<td style="font-size:.78rem;white-space:nowrap">' + tkEscape(t.actualizado || '—') + '</td>' +
        '<td><button class="btn btn-sm btn-primary" onclick="verAdminTicket(\'' + tkEscape(t.idTicket) + '\')">Ver</button></td>' +
      '</tr>';
    }).join('');
  }).catch(() => {
    body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#f87171;padding:24px">Error al cargar</td></tr>';
  });
}

// ---- Estado del visor de ticket (chat admin) ----
let atViewPollTimer = null;
let atSignature = '';
let atAdjuntoFile = null;
let atEstadoActual = '';

function verAdminTicket(idTicket, silent) {
  if (!silent) {
    atSignature = '';
    atAdjuntoFile = null;
    adminQuitarAdjunto();
    const ta = document.getElementById('adminTicketMsg');
    if (ta) ta.value = '';
    document.getElementById('adminTicketId').value = idTicket;
    document.getElementById('adminTicketModal').style.display = 'flex';
    if (atViewPollTimer) clearInterval(atViewPollTimer);
    atViewPollTimer = setInterval(() => {
      const modal = document.getElementById('adminTicketModal');
      if (!modal || modal.style.display === 'none') { clearInterval(atViewPollTimer); atViewPollTimer = null; return; }
      verAdminTicket(document.getElementById('adminTicketId').value, true);
    }, 4000);
  }
  lsApi('tickets', 'POST', { action: 'admin_get', idTicket: idTicket }).then(data => {
    if (!data.exito) { if (!silent) showToast(data.mensaje || 'No se pudo abrir el ticket', 'error'); return; }
    const t = data.ticket || {};
    const msgs = data.mensajes || [];
    const estado = t.estado || '';
    const sig = estado + '|' + (t.prioridad || '') + '|' + msgs.length + '|' + (msgs.length ? (msgs[msgs.length - 1].creado || '') : '') + '|' + (Number(t.escalado) === 1 ? '1' : '0');
    const cambio = sig !== atSignature;
    atSignature = sig;
    atEstadoActual = estado;

    document.getElementById('adminTicketId').value = t.idTicket || idTicket;
    document.getElementById('adminTicketTitle').textContent = t.asunto || 'Ticket';
    const esc = Number(t.escalado) === 1;
    document.getElementById('adminTicketMeta').innerHTML =
      'Cliente: <b>' + tkEscape(t.cliente || '—') + '</b> · ' + tkEscape(t.clienteEmail || '') +
      ' · Categoría: ' + tkEscape(t.categoria || '—') +
      ' · Estado: ' + tkEscape(estado || '—') +
      (esc ? ' · <span style="color:#22c55e">WhatsApp habilitado</span>' : '');

    // Sincronizar selector de prioridad (sin disparar onchange)
    const selP = document.getElementById('adminTicketPrioridad');
    if (selP && (t.prioridad || 'NORMAL') !== selP.value) selP.value = t.prioridad || 'NORMAL';

    const thread = document.getElementById('adminTicketThread');
    if (cambio) {
      const nearBottom = (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 80;
      if (!msgs.length) {
        thread.innerHTML = '<div style="color:var(--text-muted);text-align:center">Sin mensajes</div>';
      } else {
        thread.innerHTML = msgs.map(m => {
          const esAdmin = (m.autorTipo || '').toUpperCase() === 'ADMIN';
          const align = esAdmin ? 'right' : 'left';
          const bg = esAdmin ? 'rgba(108,60,225,.18)' : 'rgba(255,255,255,.05)';
          const who = esAdmin ? 'Soporte' : 'Cliente';
          return '<div style="text-align:' + align + ';margin-bottom:10px">' +
            '<div style="display:inline-block;max-width:80%;text-align:left;background:' + bg + ';padding:8px 12px;border-radius:10px">' +
            '<div style="font-size:.68rem;color:var(--text-muted);margin-bottom:3px">' + who + ' · ' + tkEscape(m.creado || '') + '</div>' +
            (m.mensaje ? '<div style="font-size:.86rem;white-space:pre-wrap;word-break:break-word">' + tkEscape(m.mensaje) + '</div>' : '') +
            atAdjuntoHtml(m) +
            '</div></div>';
        }).join('');
      }
      if (nearBottom || !silent) thread.scrollTop = thread.scrollHeight;
    }

    // Bloqueo del composer si esta resuelto
    const resuelto = estado === 'RESUELTO';
    document.getElementById('adminTicketResuelto').style.display = resuelto ? 'block' : 'none';
    document.getElementById('adminComposer').style.display = resuelto ? 'none' : 'block';
    const btnEnviar = document.getElementById('adminBtnEnviar');
    if (btnEnviar) btnEnviar.style.display = resuelto ? 'none' : 'inline-block';
  }).catch(() => {});
}

function atAdjuntoHtml(m) {
  if (!m.adjunto_url) return '';
  const url = API + '/' + m.adjunto_url + '&tk=' + encodeURIComponent(token);
  const nombre = tkEscape(m.adjunto_nombre || 'archivo');
  if ((m.adjunto_tipo || '').indexOf('image/') === 0) {
    return '<a href="' + tkEscape(url) + '" target="_blank" rel="noopener">' +
      '<img src="' + tkEscape(url) + '" alt="' + nombre + '" style="margin-top:6px;max-width:220px;max-height:220px;border-radius:10px;display:block"></a>';
  }
  return '<a href="' + tkEscape(url) + '&dl=1" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:.82rem;text-decoration:underline">📎 ' + nombre + '</a>';
}

function adminReplyTicket(e) {
  e.preventDefault();
  const idTicket = document.getElementById('adminTicketId').value;
  const mensaje = document.getElementById('adminTicketMsg').value.trim();
  if (atEstadoActual === 'RESUELTO') { showToast('El ticket está resuelto. Reabrelo para responder.', 'error'); return false; }
  if (!mensaje && !atAdjuntoFile) { showToast('Escribe una respuesta o adjunta un archivo', 'error'); return false; }

  const fd = new FormData();
  fd.append('action', 'admin_reply');
  fd.append('idTicket', idTicket);
  fd.append('mensaje', mensaje);
  if (atAdjuntoFile) fd.append('adjunto', atAdjuntoFile);

  fetch(API + '/tickets.php', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token },
    body: fd
  }).then(r => r.json()).then(data => {
    if (data.exito) {
      showToast('Respuesta enviada');
      document.getElementById('adminTicketMsg').value = '';
      adminQuitarAdjunto();
      atSignature = '';
      verAdminTicket(idTicket, true);
      loadAdminTickets();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  }).catch(() => showToast('Error al enviar', 'error'));
  return false;
}

function adminSetTicketPrioridad(prioridad) {
  const idTicket = document.getElementById('adminTicketId').value;
  lsApi('tickets', 'POST', { action: 'admin_update', idTicket: idTicket, prioridad: prioridad }).then(data => {
    if (data.exito) {
      showToast('Prioridad actualizada');
      atSignature = '';
      verAdminTicket(idTicket, true);
      loadAdminTickets();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  });
}

// ---- Adjuntos del composer (admin) ----
function adminArchivoSeleccionado(inputEl) {
  const f = inputEl.files && inputEl.files[0];
  if (!f) return;
  if (f.size > 8 * 1024 * 1024) { showToast('El archivo supera el límite de 8 MB', 'error'); inputEl.value = ''; return; }
  atAdjuntoFile = f;
  document.getElementById('adminAdjuntoNombre').textContent = f.name;
  document.getElementById('adminAdjuntoPreview').style.display = 'flex';
  inputEl.value = '';
}
function adminQuitarAdjunto() {
  atAdjuntoFile = null;
  const p = document.getElementById('adminAdjuntoPreview');
  if (p) p.style.display = 'none';
  const n = document.getElementById('adminAdjuntoNombre');
  if (n) n.textContent = '';
}

// ---- Emojis (admin) ----
const AT_EMOJIS = ['😀','😄','😊','😉','😍','😅','😂','🤔','😴','😎','👍','👎','🙏','👏','🙌','💪','✅','❌','⚠️','❗','❓','🔥','⭐','🎉','💡','📎','🖼️','📄','💰','🕒','😢','😡'];
function adminToggleEmojis(e) {
  if (e) e.stopPropagation();
  const panel = document.getElementById('adminEmojiPanel');
  if (!panel) return;
  if (panel.style.display !== 'none' && panel.style.display !== '') { panel.style.display = 'none'; return; }
  if (!panel.dataset.filled) {
    panel.innerHTML = AT_EMOJIS.map(em =>
      '<button type="button" style="border:none;background:transparent;font-size:1.2rem;cursor:pointer;padding:3px" onclick="adminInsertEmoji(\'' + em + '\')">' + em + '</button>'
    ).join('');
    panel.dataset.filled = '1';
  }
  panel.style.display = 'grid';
  panel.style.gridTemplateColumns = 'repeat(8,1fr)';
}
function adminInsertEmoji(em) {
  const ta = document.getElementById('adminTicketMsg');
  if (!ta) return;
  const s = ta.selectionStart || ta.value.length;
  const en = ta.selectionEnd || ta.value.length;
  ta.value = ta.value.slice(0, s) + em + ta.value.slice(en);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = s + em.length;
  document.getElementById('adminEmojiPanel').style.display = 'none';
}

function adminSetTicketEstado(estado) {
  const idTicket = document.getElementById('adminTicketId').value;
  lsApi('tickets', 'POST', { action: 'admin_update', idTicket: idTicket, estado: estado }).then(data => {
    if (data.exito) {
      showToast('Ticket actualizado');
      verAdminTicket(idTicket);
      loadAdminTickets();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  });
}

function adminEscalateTicket() {
  const idTicket = document.getElementById('adminTicketId').value;
  if (!confirm('¿Habilitar la atención por WhatsApp para este ticket? Solo hazlo si no se pudo resolver por la conversación normal.')) return;
  lsApi('tickets', 'POST', { action: 'admin_escalate', idTicket: idTicket, activar: 1 }).then(data => {
    if (data.exito) {
      showToast('Ticket habilitado para WhatsApp');
      verAdminTicket(idTicket);
      loadAdminTickets();
    } else {
      showToast(data.mensaje || 'Error', 'error');
    }
  });
}

// ---- GSAP ----
function animateCards() {
  applyReveal(document);
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', () => {
  loadOverview();
});