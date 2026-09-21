/* ============================================
   Comandix — Auth JS (Login + Register)
   ============================================ */

const API = '../api';

// ---- Helpers ----
function lsJson(method, url, body) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json' }
  };
  const token = localStorage.getItem('ls_token');
  if (token) opts.headers['Authorization'] = 'Bearer ' + token;
  if (body) opts.body = JSON.stringify(body);
  // Llamamos al archivo .php DIRECTAMENTE: asi funciona tengas o no activadas
  // las URLs limpias (mod_rewrite). Insertamos .php antes de cualquier ?query.
  const qi = url.indexOf('?');
  const path = qi === -1 ? url : url.slice(0, qi);
  const qs = qi === -1 ? '' : url.slice(qi);
  const ep = /\.[a-z0-9]+$/i.test(path) ? url : path + '.php' + qs;
  return fetch(API + '/' + ep, opts)
    .then(r => r.text().then(text => {
      let data = null;
      const s = text.search(/[\[{]/);
      if (s !== -1) { try { data = JSON.parse(text.slice(s)); } catch (e) { data = null; } }
      if (data === null) {
        const preview = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220);
        throw new Error('El servidor respondio con un error (HTTP ' + r.status + '). ' + (preview || 'Sin detalle. Revisa que la base de datos exista.'));
      }
      return data;
    }));
}

function showToast(msg, type = 'success') {
  // Remove existing toasts
  document.querySelectorAll('.toast').forEach(t => t.remove());
  
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  t.style.cssText = 'position:fixed;top:20px;right:20px;padding:14px 24px;border-radius:12px;font-size:0.9rem;font-weight:600;z-index:10000;animation:slideIn 0.3s ease;max-width:90vw;';
  
  if (type === 'error') {
    t.style.background = 'linear-gradient(135deg,#7f1d1d,#991b1b)';
    t.style.color = '#fca5a5';
    t.style.border = '1px solid rgba(239,68,68,0.3)';
  } else if (type === 'warning') {
    t.style.background = 'linear-gradient(135deg,#78350f,#92400e)';
    t.style.color = '#fde68a';
    t.style.border = '1px solid rgba(245,158,11,0.3)';
  } else {
    t.style.background = 'linear-gradient(135deg,#064e3b,#065f46)';
    t.style.color = '#6ee7b7';
    t.style.border = '1px solid rgba(16,185,129,0.3)';
  }
  
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 4000);
}

function setAuth(data) {
  localStorage.setItem('ls_token', data.token);
  localStorage.setItem('ls_user', JSON.stringify(data.usuario));
  // Store user type: 'admin' or 'client'
  localStorage.setItem('ls_type', data.tipo || 'client');
}

function getAuth() {
  const token = localStorage.getItem('ls_token');
  const user = JSON.parse(localStorage.getItem('ls_user') || 'null');
  const type = localStorage.getItem('ls_type');
  return { token, user, type };
}

function logout() {
  localStorage.removeItem('ls_token');
  localStorage.removeItem('ls_user');
  localStorage.removeItem('ls_type');
}

// ---- Redirect if already logged ----
(function checkAuth() {
  const { token, type } = getAuth();
  if (token) {
    if (type === 'admin') window.location.href = 'admin';
    else window.location.href = 'portal';
  }
})();

// ---- Tab Switch ----
function switchTab(tab) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelector('[data-tab="' + tab + '"]').classList.add('active');
  document.getElementById('clientForm').style.display = tab === 'client' ? 'block' : 'none';
  document.getElementById('adminForm').style.display = tab === 'admin' ? 'block' : 'none';
}

// ---- Client Login ----
function clientLogin(e) {
  e.preventDefault();
  const btn = document.getElementById('clientBtn');
  btn.disabled = true;
  btn.textContent = 'Ingresando...';

  lsJson('POST', 'auth', {
    action: 'client_login',
    email: document.getElementById('clientEmail').value,
    clave: document.getElementById('clientPass').value
  })
  .then(data => {
    if (data.exito) {
      setAuth(data);
      showToast('Bienvenido! Redirigiendo...');
      setTimeout(() => window.location.href = 'portal', 1200);
    } else {
      showToast(data.mensaje || 'Error al iniciar sesión', 'error');
      btn.disabled = false;
      btn.textContent = 'Iniciar Sesión';
    }
  })
  .catch(err => {
    showToast((err && err.message) ? err.message : 'Error de conexión al servidor. Verifica que Apache y MySQL estén corriendo.', 'error');
    console.error('Login error:', err);
    btn.disabled = false;
    btn.textContent = 'Iniciar Sesión';
  });
  return false;
}

// ---- Admin Login ----
function adminLogin(e) {
  e.preventDefault();
  const btn = document.getElementById('adminBtn');
  btn.disabled = true;
  btn.textContent = 'Accediendo...';

  lsJson('POST', 'auth', {
    action: 'admin_login',
    usuario: document.getElementById('adminUser').value,
    clave: document.getElementById('adminPass').value
  })
  .then(data => {
    if (data.exito) {
      setAuth(data);
      showToast('Bienvenido, Admin!');
      setTimeout(() => window.location.href = 'admin', 1200);
    } else {
      showToast(data.mensaje || 'Credenciales incorrectas', 'error');
      btn.disabled = false;
      btn.textContent = 'Acceder como Admin';
    }
  })
  .catch(err => {
    showToast((err && err.message) ? err.message : 'Error de conexión al servidor. Verifica que Apache y MySQL estén corriendo.', 'error');
    console.error('Admin login error:', err);
    btn.disabled = false;
    btn.textContent = 'Acceder como Admin';
  });
  return false;
}

// ---- Client Register ----
function clientRegister(e) {
  e.preventDefault();
  const btn = document.getElementById('regBtn');
  btn.disabled = true;
  btn.innerHTML = '<span>Creando cuenta...</span>';

  lsJson('POST', 'auth', {
    action: 'client_register',
    nombre: document.getElementById('regName').value,
    email: document.getElementById('regEmail').value,
    clave: document.getElementById('regPass').value,
    restaurante: document.getElementById('regRestaurant').value,
    telefono: document.getElementById('regPhone').value,
    ciudad: document.getElementById('regCity').value
  })
  .then(data => {
    if (data.exito) {
      setAuth(data);
      showToast('¡Cuenta creada! Tu licencia de prueba está activa.');
      setTimeout(() => window.location.href = 'portal', 1500);
    } else {
      showToast(data.mensaje || 'Error al registrarse', 'error');
      btn.disabled = false;
      btn.innerHTML = '<span>Crear Cuenta y Obtener Prueba</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    }
  })
  .catch(err => {
    showToast((err && err.message) ? err.message : 'Error de conexión al servidor. Verifica que Apache y MySQL estén corriendo.', 'error');
    console.error('Register error:', err);
    btn.disabled = false;
    btn.innerHTML = '<span>Crear Cuenta y Obtener Prueba</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
  });
  return false;
}

// ---- Enter key shortcuts ----
document.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    const active = document.querySelector('.auth-tab.active');
    if (active && active.dataset.tab === 'admin') {
      document.getElementById('adminForm').dispatchEvent(new Event('submit'));
    }
  }
});