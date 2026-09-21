/* ============================================
   FREAKERS POS v6 — Core App Module
   Handles: Auth guard, branding, config, sidebar, topbar,
   notifications, ticket printing, helpers,
   keyboard security, and license background verification
   ============================================ */

const API = 'api';
let CONFIG = {};
let SESION = null;
let notifPolling = null;

/* Factory defaults */
const FACTORY_DEFAULTS = {
  NOMBRE_NEGOCIO: 'Freakers',
  NOMBRE_RESTAURANTE: 'FREAKERS',
  RAZON_SOCIAL: 'BURGERS AND FAST FOOD',
  NIT: '1214722370',
  DIRECCION: 'Medellin, Colombia',
  TELEFONO: '310 574 3129',
  SUBTITULO: 'Burgers & Fast Food',
  LOGO: 'https://i.ibb.co/39bHjfNc/freakers-png.png',
  COLOR_PRIMARIO: '#6C3CE1',
  COLOR_SECUNDARIO: '#1A1A2E',
  COLOR_ACENTO: '#E94560',
  COLOR_FONDO: '#16213E',
  TIPOGRAFIA: 'Inter',
  VALOR_DOMICILIO: '5000',
  AUTO_IMPRIMIR: 'SI'
};

/* Default product image */
const DEFAULT_PRODUCT_IMG = 'https://cdn-icons-png.flaticon.com/512/1046/1046786.png';

/* Available fonts */
const AVAILABLE_FONTS = [
  { value: 'Inter', label: 'Inter', url: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap' },
  { value: 'Poppins', label: 'Poppins', url: 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap' },
  { value: 'Montserrat', label: 'Montserrat', url: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap' },
  { value: 'Roboto', label: 'Roboto', url: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;900&display=swap' },
  { value: 'Playfair Display', label: 'Playfair Display', url: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800;900&display=swap' },
  { value: 'Raleway', label: 'Raleway', url: 'https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap' },
  { value: 'Oswald', label: 'Oswald', url: 'https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap' },
  { value: 'Bebas Neue', label: 'Bebas Neue', url: 'https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap' },
  { value: 'Lato', label: 'Lato', url: 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap' },
  { value: 'Nunito', label: 'Nunito', url: 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap' },
  { value: 'Rubik', label: 'Rubik', url: 'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700;800&display=swap' },
  { value: 'Space Grotesk', label: 'Space Grotesk', url: 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap' }
];

/* ============================================
   App Module (IIFE) — License-aware core
   ============================================ */
const App = (() => {
    let currentPage = '';

    // ===== Session Management =====
    function getSesion() {
        try {
            const raw = sessionStorage.getItem('pos_sesion');
            if (!raw) return null;
            const sesion = JSON.parse(atob(raw));
            if (sesion.exp && sesion.exp < Date.now() / 1000) {
                sessionStorage.removeItem('pos_sesion');
                return null;
            }
            SESION = sesion;
            return sesion;
        } catch (e) {
            // Try legacy format
            try {
                const s = sessionStorage.getItem('pos_sesion');
                if (!s) return null;
                SESION = JSON.parse(s);
                return SESION;
            } catch(e2) { return null; }
        }
    }

    function setSesion(sesion) {
        if (!sesion) return;
        SESION = sesion;
        const payload = { ...sesion, exp: Math.floor(Date.now() / 1000) + 86400 * 7 };
        sessionStorage.setItem('pos_sesion', btoa(JSON.stringify(payload)));
    }

    function clearSesion() {
        SESION = null;
        sessionStorage.removeItem('pos_sesion');
        stopNotifPolling();
    }

    // ===== Auth Guard (license-aware) =====
    function requireAuth() {
        const sesion = getSesion();
        if (!sesion) {
            window.location.href = 'login';
            return false;
        }

        // v6: Check license status embedded in session token
        const licEstado = sesion.licencia_estado || 'ACTIVA';
        const badStates = ['EXPIRADA', 'REVOCADA', 'SUSPENDIDA', 'SIN_LICENCIA'];
        if (badStates.includes(licEstado)) {
            if (typeof LicenciaModule !== 'undefined') {
                LicenciaModule.redirectToLicensePage(licEstado);
            } else {
                window.location.href = 'licencia-expirada?estado=' + licEstado;
            }
            return false;
        }

        return true;
    }

    function requireAdmin() {
        if (!requireAuth()) return false;
        if (SESION.rol !== 'ADMIN') {
            toast('Acceso denegado. Solo administradores.', 'error');
            return false;
        }
        return true;
    }

    // ===== API Helpers (license-intercepting) =====
    async function apiGet(endpoint, params = {}) {
        var qs = Object.entries(params).filter(function(e) { return e[1] !== '' && e[1] !== undefined && e[1] !== null; }).map(function(e) { return encodeURIComponent(e[0]) + '=' + encodeURIComponent(e[1]); }).join('&');
        endpoint = String(endpoint).replace(/\.php$/i, '');
        if (!/\.[a-z0-9]+$/i.test(endpoint)) endpoint += '.php';
        var url = API + '/' + endpoint + (qs ? '?' + qs : '');
        var headers = {};
        if (SESION && SESION.token) headers['Authorization'] = 'Bearer ' + SESION.token;
        try {
            var res = await fetch(url, { headers: headers });
            var text = await res.text();
            if (!text || !text.trim()) {
                return { exito: false, mensaje: 'Respuesta vacia del servidor. Verifica que Apache y MySQL esten corriendo en XAMPP.' };
            }
            var jsonStartObj = text.indexOf('{');
            var jsonStartArr = text.indexOf('[');
            var jsonStart;
            if (jsonStartObj < 0 && jsonStartArr < 0) {
                var preview = text.substring(0, 200).replace(/<[^>]*>/g, '').trim();
                return { exito: false, mensaje: 'El servidor no devolvio JSON. ' + (preview || 'Respuesta vacia.') };
            }
            if (jsonStartObj < 0) jsonStart = jsonStartArr;
            else if (jsonStartArr < 0) jsonStart = jsonStartObj;
            else jsonStart = Math.min(jsonStartObj, jsonStartArr);
            if (jsonStart > 0) text = text.substring(jsonStart);
            if (text.charAt(0) === '[') {
                var jsonEnd = text.lastIndexOf(']');
            } else {
                var jsonEnd = text.lastIndexOf('}');
            }
            if (jsonEnd >= 0 && jsonEnd < text.length - 1) text = text.substring(0, jsonEnd + 1);
            var data = JSON.parse(text);

            // v6: Intercept license errors from any API call
            if (typeof LicenciaModule !== 'undefined' && LicenciaModule.interceptLicenseError(data)) {
                return null;
            }

            return data;
        } catch (e) {
            console.error('API GET error:', e);
            return { exito: false, mensaje: 'Error de red: ' + e.message };
        }
    }

    async function apiPost(endpoint, data) {
        var headers = { 'Content-Type': 'application/json' };
        if (SESION && SESION.token) headers['Authorization'] = 'Bearer ' + SESION.token;
        try {
            var epP = String(endpoint).replace(/\.php$/i, '');
            if (!/\.[a-z0-9]+$/i.test(epP)) epP += '.php';
            var res = await fetch(API + '/' + epP, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify(data)
            });
            var text = await res.text();
            if (!text || !text.trim()) {
                return { exito: false, mensaje: 'Respuesta vacia del servidor. Verifica que Apache y MySQL esten corriendo en XAMPP.' };
            }
            var jsonStartObj = text.indexOf('{');
            var jsonStartArr = text.indexOf('[');
            var jsonStart;
            if (jsonStartObj < 0 && jsonStartArr < 0) {
                var preview = text.substring(0, 200).replace(/<[^>]*>/g, '').trim();
                return { exito: false, mensaje: 'El servidor no devolvio JSON. ' + (preview || 'Respuesta vacia.') };
            }
            if (jsonStartObj < 0) jsonStart = jsonStartArr;
            else if (jsonStartArr < 0) jsonStart = jsonStartObj;
            else jsonStart = Math.min(jsonStartObj, jsonStartArr);
            if (jsonStart > 0) text = text.substring(jsonStart);
            if (text.charAt(0) === '[') {
                var jsonEnd = text.lastIndexOf(']');
            } else {
                var jsonEnd = text.lastIndexOf('}');
            }
            if (jsonEnd >= 0 && jsonEnd < text.length - 1) text = text.substring(0, jsonEnd + 1);
            var parsed = JSON.parse(text);

            // v6: Intercept license errors
            if (typeof LicenciaModule !== 'undefined' && LicenciaModule.interceptLicenseError(parsed)) {
                return null;
            }

            if (!res.ok && parsed && parsed.mensaje) return parsed;
            if (!res.ok) {
                return { exito: false, mensaje: 'Error HTTP ' + res.status + ': ' + res.statusText };
            }
            return parsed;
        } catch (e) {
            console.error('API POST error:', e);
            return { exito: false, mensaje: 'Error de red: ' + e.message };
        }
    }

    async function apiUpload(formData) {
        const headers = {};
        if (SESION?.token) headers['Authorization'] = 'Bearer ' + SESION.token;
        try {
            const res = await fetch(API + '/upload.php', {
                method: 'POST',
                headers,
                body: formData
            });
            return await res.json();
        } catch (e) {
            console.error('Upload error:', e);
            return null;
        }
    }

    // ===== Config / Branding =====
    function applyCachedBranding() {
        try {
            const cached = localStorage.getItem('pos_config');
            if (cached) {
                const cfg = JSON.parse(cached);
                CONFIG = cfg;
                applyBranding();
            }
        } catch (e) {}
    }

    async function loadConfig() {
        const data = await apiGet('configuracion.php');
        if (data && !data.error) {
            CONFIG = data;
            try { localStorage.setItem('pos_config', JSON.stringify(CONFIG)); } catch(e) {}
            applyBranding();
        }
    }

    function applyBranding() {
        const r = document.documentElement;
        // Set BOTH old and new CSS variable names for full compatibility
        if (CONFIG.COLOR_PRIMARIO) {
            r.style.setProperty('--primary', CONFIG.COLOR_PRIMARIO);
            r.style.setProperty('--color-primario', CONFIG.COLOR_PRIMARIO);
        }
        if (CONFIG.COLOR_SECUNDARIO) {
            r.style.setProperty('--bg', CONFIG.COLOR_SECUNDARIO);
            r.style.setProperty('--color-secundario', CONFIG.COLOR_SECUNDARIO);
        }
        if (CONFIG.COLOR_ACENTO) {
            r.style.setProperty('--accent', CONFIG.COLOR_ACENTO);
            r.style.setProperty('--color-acento', CONFIG.COLOR_ACENTO);
        }
        if (CONFIG.COLOR_FONDO) {
            r.style.setProperty('--surface', CONFIG.COLOR_FONDO);
            r.style.setProperty('--color-fondo', CONFIG.COLOR_FONDO);
        }

        const font = CONFIG.TIPOGRAFIA || 'Inter';
        r.style.setProperty('--font', "'" + font + "', sans-serif");
        r.style.setProperty('--font-family', "'" + font + "', sans-serif");
        document.body.style.fontFamily = "'" + font + "', sans-serif";
        loadFontIfNeeded(font);

        // Logo in sidebar
        const brandName = document.querySelector('.sidebar-brand h1');
        if (brandName && CONFIG.NOMBRE_NEGOCIO) brandName.textContent = CONFIG.NOMBRE_NEGOCIO;
        const brandImg = document.querySelector('.sidebar-brand img');
        if (brandImg && CONFIG.LOGO) brandImg.src = CONFIG.LOGO;
        // v6: Also update .app-logo and .app-negocio
        document.querySelectorAll('.app-logo').forEach(el => { if (CONFIG.LOGO) el.src = CONFIG.LOGO; });
        document.querySelectorAll('.app-negocio').forEach(el => { el.textContent = CONFIG.NOMBRE_NEGOCIO || 'Freakers POS'; });

        document.title = (CONFIG.NOMBRE_NEGOCIO || 'Freakers POS') + ' - Sistema';
    }

    function loadFontIfNeeded(fontName) {
        if (document.querySelector('link[data-font="' + fontName + '"]')) return;
        const fontObj = AVAILABLE_FONTS.find(f => f.value === fontName);
        if (fontObj) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = fontObj.url;
            link.setAttribute('data-font', fontName);
            document.head.appendChild(link);
        }
    }

    // ===== Factory Reset =====
    async function factoryReset() {
        if (!confirm('Restaurar configuracion de fabrica? Se perderan todos los cambios de marca, colores, tipografia y logo.')) return;
        if (!confirm('Estas seguro? Esta accion no se puede deshacer.')) return;
        const res = await apiPost('factory_reset.php', {});
        if (res?.exito) {
            toast('Configuracion restaurada a fabrica');
            await loadConfig();
            applyBranding();
            if (typeof loadConfigPage === 'function') loadConfigPage();
        } else {
            toast(res?.mensaje || 'Error al restaurar', 'error');
        }
    }

    // ===== Notifications =====
    function startNotifPolling() {
        if (notifPolling) clearInterval(notifPolling);
        updateNotifBadge();
        notifPolling = setInterval(updateNotifBadge, 8000);
    }

    function stopNotifPolling() {
        if (notifPolling) { clearInterval(notifPolling); notifPolling = null; }
    }

    async function updateNotifBadge() {
        const data = await apiGet('notificaciones.php', { action: 'count' });
        const badge = document.querySelector('.bell-badge');
        if (badge && data) {
            const c = data.total || data.count || 0;
            badge.textContent = c > 99 ? '99+' : c;
            badge.style.display = c > 0 ? 'flex' : 'none';
        }
    }

    async function toggleNotifDropdown() {
        var dd = document.querySelector('.notif-dropdown');
        if (!dd) return;
        if (dd.classList.contains('show')) {
            dd.classList.remove('show');
            return;
        }
        var bell = document.querySelector('.bell-btn');
        if (bell) {
            var rect = bell.getBoundingClientRect();
            var isMobile = window.innerWidth <= 768;
            if (isMobile) {
                dd.style.top = (rect.bottom + 8) + 'px';
                dd.style.left = '4vw';
                dd.style.right = '4vw';
            } else {
                dd.style.top = (rect.bottom + 8) + 'px';
                var ddWidth = 340;
                var leftPos = rect.right - ddWidth;
                if (leftPos < 8) leftPos = 8;
                dd.style.left = leftPos + 'px';
                dd.style.right = 'auto';
            }
        }
        var notifs = await apiGet('notificaciones.php', { action: 'lista', limite: 20 });
        if (!notifs) return;
        var html = '<div class="notif-header"><span>Notificaciones</span><button class="btn btn-sm btn-outline" onclick="markAllRead()">Marcar leidas</button></div>';
        if (Array.isArray(notifs)) {
            notifs.forEach(function(n) {
                html += '<div class="notif-item ' + (n.leida ? '' : 'unread') + '" onclick="handleNotifClick(\'' + esc(n.idNotificacion) + '\',\'' + esc(n.tipo) + '\',\'' + esc(n.idReferencia || '') + '\')">' +
                    '<div class="notif-title">' + esc(n.titulo) + '</div>' +
                    '<div class="notif-msg">' + esc(n.mensaje) + '</div>' +
                    '<div class="notif-time">' + timeAgo(n.fecha) + '</div>' +
                    '</div>';
            });
        }
        if (!notifs.length) html += '<div class="p-4 text-center text-sm text-white/40">Sin notificaciones</div>';
        dd.innerHTML = html;
        dd.classList.add('show');
    }

    async function markAllRead() {
        await apiPost('notificaciones.php', { action: 'marcarLeida', idNotificacion: 'todas' });
        updateNotifBadge();
        toggleNotifDropdown();
    }

    function handleNotifClick(id, tipo, ref) {
        apiPost('notificaciones.php', { action: 'marcarLeida', idNotificacion: id });
        updateNotifBadge();
        if (tipo === 'DOMICILIO' || tipo === 'PAGO' || tipo === 'ORDEN' || tipo === 'WHATSAPP') {
            window.location.href = 'domicilios';
        }
        var dd2 = document.querySelector('.notif-dropdown');
        if (dd2) dd2.classList.remove('show');
    }

    // ===== Ticket / Factura Print =====
    var ticketBlocks = [];

    async function loadTicketConfig() {
        try {
            var data = await apiGet('ticket_config.php');
            if (Array.isArray(data)) ticketBlocks = data;
        } catch(e) { console.warn('ticket config load error', e); }
    }

    async function printTicket(idOrden) {
        var ordenData = await apiGet('ordenes.php', { action: 'una', idOrden: idOrden });
        if (!ordenData || ordenData.error) { toast('Error al cargar orden para imprimir', 'error'); return; }
        if (!ticketBlocks.length) await loadTicketConfig();
        if (!ticketBlocks.length) { toast('No hay configuracion de ticket', 'error'); return; }

        var o = ordenData;
        var cfg = CONFIG || {};
        var isDom = o.tipo === 'DOMICILIO';
        var domFee = parseFloat(o.domicilio || 0);
        var subtotal = parseFloat(o.subtotal || o.total || 0) - domFee;
        var total = parseFloat(o.total || 0);

        var vars = {
            logo: cfg.LOGO || '',
            negocio: cfg.NOMBRE_NEGOCIO || 'Freakers',
            razon_social: cfg.RAZON_SOCIAL || '',
            nit: cfg.NIT || '',
            direccion: cfg.DIRECCION || '',
            telefono: cfg.TELEFONO || '',
            id_orden: o.idOrden || '',
            fecha: formatFecha(o.fecha, { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
            tipo: isDom ? 'Domicilio' : 'Mesa',
            mesa: o.numero || o.idMesa || '-',
            mesero: SESION ? (SESION.nombre || SESION.usuario || '-') : '-',
            cliente_nombre: o.cliente_nombre || '-',
            cliente_telefono: o.cliente_telefono || '-',
            cliente_direccion: o.cliente_direccion || '-',
            canal: o.canal || '-',
            subtotal: formatCOP(subtotal),
            domicilio: formatCOP(domFee),
            total: formatCOP(total),
            comentario: o.comentario || ''
        };

        var items = o.items || [];
        var itemRows = '';
        items.forEach(function(it) {
            var lineTotal = parseFloat(it.totalLinea || (it.precioUnitario || it.precio) * it.cantidad);
            itemRows += '<tr>' +
                '<td style="text-align:left;padding:2px 0;font-size:0.78rem">' + it.cantidad + '</td>' +
                '<td style="text-align:left;padding:2px 4px;font-size:0.78rem">' + esc(it.producto || it.idProducto) + '</td>' +
                '<td style="text-align:right;padding:2px 0;font-size:0.78rem">' + formatCOP(lineTotal) + '</td>' +
                '</tr>';
        });
        vars.items_tabla = '<table style="width:100%;border-collapse:collapse">' +
            '<thead><tr style="border-bottom:1px dashed #555">' +
                '<th style="text-align:left;font-size:0.72rem;padding:2px 0">Cant</th>' +
                '<th style="text-align:left;font-size:0.72rem;padding:2px 4px">Producto</th>' +
                '<th style="text-align:right;font-size:0.72rem;padding:2px 0">Sub</th>' +
            '</tr></thead>' +
            '<tbody>' + itemRows + '</tbody></table>';

        vars.info_cliente = isDom ?
            'Cliente: ' + vars.cliente_nombre + '\nTel: ' + vars.cliente_telefono + '\nDir: ' + vars.cliente_direccion + '\nCanal: ' + vars.canal :
            'Mesa: ' + vars.mesa + '\nMesero: ' + vars.mesero;

        var ticketHTML = '';
        var activeBlocks = ticketBlocks.filter(function(b) { return b.activo; });
        activeBlocks.sort(function(a, b) { return (a.orden || 0) - (b.orden || 0); });
        activeBlocks.forEach(function(block) {
            var content = block.contenido || '';
            Object.keys(vars).forEach(function(key) {
                var regex = new RegExp('\\{' + key + '\\}', 'g');
                content = content.replace(regex, vars[key] || '');
            });
            var opts = block.opciones || {};
            var align = opts.alineacion || 'centro';
            var alignCSS = align === 'centro' ? 'center' : (align === 'derecha' ? 'right' : 'left');
            var sizeClass = opts.tamano === 'grande' ? '1.2rem' : (opts.tamano === 'pequeno' ? '0.72rem' : '0.85rem');
            if (block.bloque === 'HEADER' && content.indexOf('{logo}') >= 0) {
                content = content.replace('{logo}', '<img src="' + (vars.logo || '') + '" style="max-width:100px;max-height:80px;display:block;margin:0 auto">');
            }
            content = content.replace(/\n/g, '<br>');
            ticketHTML += '<div style="text-align:' + alignCSS + ';font-size:' + sizeClass + ';margin:6px 0;line-height:1.4">' + content + '</div>';
        });

        var printPage = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Factura ' + esc(o.idOrden) + '</title>' +
            '<style>' +
            '@page{margin:5mm;size:80mm auto}' +
            'body{font-family:\'Courier New\',monospace;color:#000;background:#fff;margin:0;padding:8px;font-size:0.85rem;line-height:1.4}' +
            'table{width:100%;border-collapse:collapse}' +
            'img{max-width:100px;display:block;margin:0 auto}' +
            '</style></head><body>' +
            ticketHTML +
            '<div style="text-align:center;margin-top:16px;font-size:0.7rem;color:#888">Generado por Freakers POS &bull; ' + formatFecha(new Date().toISOString()) + '</div>' +
            '</body></html>';

        var printWin = window.open('', '_blank', 'width=350,height=600');
        if (!printWin) { toast('Permite ventanas emergentes para imprimir', 'error'); return; }
        printWin.document.write(printPage);
        printWin.document.close();
        printWin.focus();
        setTimeout(function() { printWin.print(); }, 300);
    }

    // ===== Logout =====
    async function handleLogout() {
        if (SESION && SESION.token) {
            apiPost('auth.php', { action: 'logout' }).catch(function() {});
        }
        clearSesion();
        if (typeof LicenciaModule !== 'undefined') LicenciaModule.stopBackgroundCheck();
        window.location.href = 'login';
    }

    // ===== Sidebar =====
    function renderSidebar(activePage) {
        const isAdmin = SESION?.rol === 'ADMIN';
        currentPage = activePage;
        let nav = '';
        nav += '<a href="dashboard" class="nav-item ' + (activePage === 'dashboard' ? 'active' : '') + '"><span class="nav-icon">&#128202;</span> <span class="nav-label">Dashboard</span></a>';
        nav += '<a href="panel-mesero" class="nav-item ' + (activePage === 'panel' ? 'active' : '') + '"><span class="nav-icon">&#129369;</span> <span class="nav-label">Panel Mesero</span></a>';
        nav += '<a href="domicilios" class="nav-item ' + (activePage === 'domicilios' ? 'active' : '') + '"><span class="nav-icon">&#128661;</span> <span class="nav-label">Domicilios</span></a>';
        nav += '<a href="soporte" class="nav-item ' + (activePage === 'soporte' ? 'active' : '') + '"><span class="nav-icon">&#128172;</span> <span class="nav-label">Soporte</span></a>';
        if (isAdmin) {
            nav += '<div class="nav-divider"></div>';
            nav += '<a href="admin-mesas" class="nav-item ' + (activePage === 'mesas' ? 'active' : '') + '"><span class="nav-icon">&#129361;</span> <span class="nav-label">Mesas</span></a>';
            nav += '<a href="admin-categorias" class="nav-item ' + (activePage === 'categorias' ? 'active' : '') + '"><span class="nav-icon">&#128193;</span> <span class="nav-label">Categorias</span></a>';
            nav += '<a href="admin-productos" class="nav-item ' + (activePage === 'productos' ? 'active' : '') + '"><span class="nav-icon">&#127828;</span> <span class="nav-label">Productos</span></a>';
            nav += '<a href="admin-ordenes" class="nav-item ' + (activePage === 'ordenes' ? 'active' : '') + '"><span class="nav-icon">&#128203;</span> <span class="nav-label">Ordenes</span></a>';
            nav += '<a href="admin-pagos" class="nav-item ' + (activePage === 'pagos' ? 'active' : '') + '"><span class="nav-icon">&#128179;</span> <span class="nav-label">Pagos</span></a>';
            nav += '<a href="admin-usuarios" class="nav-item ' + (activePage === 'usuarios' ? 'active' : '') + '"><span class="nav-icon">&#128101;</span> <span class="nav-label">Usuarios</span></a>';
            nav += '<a href="admin-movimientos" class="nav-item ' + (activePage === 'movimientos' ? 'active' : '') + '"><span class="nav-icon">&#128220;</span> <span class="nav-label">Movimientos</span></a>';
            nav += '<a href="admin-configuracion" class="nav-item ' + (activePage === 'config' ? 'active' : '') + '"><span class="nav-icon">&#9881;</span> <span class="nav-label">Configuracion</span></a>';
        }
        nav += '<div class="nav-divider"></div>';
        nav += '<button class="nav-item" onclick="App.handleLogout()"><span class="nav-icon">&#128682;</span> <span class="nav-label">Cerrar Sesion</span></button>';

        var sb = document.querySelector('.sidebar-nav');
        if (sb) sb.innerHTML = nav;

        // v6: Update license badge in sidebar footer
        updateLicenseBadge();
    }

    // ===== Topbar =====
    function renderTopbar(title) {
        var topbar = document.getElementById('topbar');
        if (!topbar) {
            topbar = document.querySelector('.topbar-right');
        }
        if (!topbar) return;

        const user = SESION ? ((SESION.nombre || '') + ' ' + (SESION.apellido || '')).trim() || SESION.usuario : '';
        const rol = SESION?.rol || '';

        // If it's the topbar element
        if (topbar.id === 'topbar') {
            topbar.innerHTML = `
                <div class="topbar-left">
                    <button class="menu-toggle" onclick="App.toggleSidebar()">&#9776;</button>
                    <h2 class="page-title">${esc(title || currentPage)}</h2>
                </div>
                <div class="topbar-right">
                    <div style="position:relative">
                        <button class="bell-btn" onclick="App.toggleNotifDropdown()">&#128276;<span class="bell-badge" style="display:none">0</span></button>
                        <div class="notif-dropdown"></div>
                    </div>
                    <span class="text-sm font-medium" style="color:rgba(255,255,255,0.7)">${esc(user)}</span>
                    <span class="badge badge-${rol === 'ADMIN' ? 'primary' : 'info'}">${esc(rol)}</span>
                    <button class="btn-logout" onclick="App.handleLogout()">&#128682; Salir</button>
                </div>
            `;
        } else {
            // Legacy: just the topbar-right div
            topbar.innerHTML = `
                <div style="position:relative">
                    <button class="bell-btn" onclick="App.toggleNotifDropdown()">&#128276;<span class="bell-badge" style="display:none">0</span></button>
                    <div class="notif-dropdown"></div>
                </div>
                <span class="text-sm font-medium" style="color:rgba(255,255,255,0.7)">${esc(user)}</span>
                <span class="badge badge-${rol === 'ADMIN' ? 'primary' : 'info'}">${esc(rol)}</span>
            `;
        }
        startNotifPolling();
    }

    // ===== License Badge =====
    function updateLicenseBadge() {
        var badge = document.getElementById('license-status-text');
        if (!badge) return;
        var estado = SESION ? (SESION.licencia_estado || '—') : '—';
        badge.textContent = estado;
        var colors = {
            'ACTIVA': '#4ade80',
            'EXPIRADA': '#E94560',
            'REVOCADA': '#ef4444',
            'SUSPENDIDA': '#f59e0b',
            'SIN_LICENCIA': '#E94560',
            'SIN_CONEXION': '#f59e0b'
        };
        badge.style.color = colors[estado] || 'rgba(255,255,255,0.4)';
    }

    // ===== Toggle Sidebar (mobile) =====
    function toggleSidebar() {
        var sb = document.querySelector('.sidebar');
        if (sb) sb.classList.toggle('open');
    }

    function closeSidebar() {
        var sb = document.querySelector('.sidebar');
        var bd = document.querySelector('.sidebar-backdrop');
        if (sb) sb.classList.remove('open');
        if (bd) bd.classList.remove('show');
    }

    function openSidebar() {
        var sb = document.querySelector('.sidebar');
        var bd = document.querySelector('.sidebar-backdrop');
        if (sb) sb.classList.add('open');
        if (bd) bd.classList.add('show');
    }

    // ===== Page Init =====
    async function initPage(pageName, requireAdminFlag = false) {
        if (requireAdminFlag) {
            if (!requireAdmin()) return;
        } else {
            if (!requireAuth()) return;
        }

        // Apply cached branding immediately
        applyCachedBranding();

        // Load fresh config
        await loadConfig();

        // Render navigation
        renderSidebar(pageName);
        renderTopbar('');

        // Load ticket config
        loadTicketConfig();

        // v6: Start license background check
        if (typeof LicenciaModule !== 'undefined') {
            LicenciaModule.startBackgroundCheck();
        }

        // GSAP entrance animation
        if (typeof gsap !== 'undefined') {
            gsap.from('.card, .kpi-card, .dom-card', { y: 20, opacity: 0, duration: 0.5, stagger: 0.06, ease: 'power2.out' });
        }

        return SESION;
    }

    // ===== Public API =====
    return {
        getSesion,
        setSesion,
        clearSesion,
        requireAuth,
        requireAdmin,
        apiGet,
        apiPost,
        apiUpload,
        loadConfig,
        applyBranding,
        applyCachedBranding,
        renderSidebar,
        renderTopbar,
        initPage,
        handleLogout,
        toggleSidebar,
        closeSidebar,
        openSidebar,
        updateLicenseBadge,
        toggleNotifDropdown,
        markAllRead,
        handleNotifClick,
        startNotifPolling,
        stopNotifPolling,
        updateNotifBadge,
        factoryReset,
        printTicket,
        loadTicketConfig,
        get config() { return CONFIG; },
        get currentPage() { return currentPage; }
    };

})();

/* ============================================
   Global Helper Functions (backward compatible)
   ============================================ */

// Redirect global calls to App module
function getSesion() { return App.getSesion(); }
function setSesion(data) { App.setSesion(data); }
function clearSesion() { App.clearSesion(); }
function requireAuth() { return App.requireAuth(); }
function requireAdmin() { return App.requireAdmin(); }
function logout() { App.handleLogout(); }

// API wrappers — global for backward compatibility
async function apiGet(endpoint, params) { return App.apiGet(endpoint, params); }
async function apiPost(endpoint, data) { return App.apiPost(endpoint, data); }
async function apiUpload(formData) { return App.apiUpload(formData); }

// Config wrappers
async function loadConfig() { return App.loadConfig(); }
function applyBranding() { App.applyBranding(); }
function applyCachedBranding() { App.applyCachedBranding(); }
function factoryReset() { App.factoryReset(); }

// Navigation wrappers
function renderSidebar(page) { App.renderSidebar(page); }
function renderTopbar(title) { App.renderTopbar(title); }

// Notifications wrappers
function startNotifPolling() { App.startNotifPolling(); }
function stopNotifPolling() { App.stopNotifPolling(); }
function updateNotifBadge() { App.updateNotifBadge(); }
function toggleNotifDropdown() { App.toggleNotifDropdown(); }
function markAllRead() { App.markAllRead(); }
function handleNotifClick(id, tipo, ref) { App.handleNotifClick(id, tipo, ref); }

// Sidebar wrappers
function toggleSidebar() { App.toggleSidebar(); }
function closeSidebar() { App.closeSidebar(); }
function openSidebar() { App.openSidebar(); }

// Ticket wrappers
function printTicket(idOrden) { return App.printTicket(idOrden); }
function loadTicketConfig() { return App.loadTicketConfig(); }

// ---- Standalone helpers (not in App IIFE for backward compat) ----

/* Toast notification */
function toast(msg, type = 'success') {
    let c = document.querySelector('.toast-container');
    if (!c) {
        c = document.createElement('div');
        c.className = 'toast-container';
        document.body.appendChild(c);
    }
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.innerHTML = '<span>' + esc(msg) + '</span>';
    c.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 400);
    }, 3500);
}

/* Modal helpers */
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

/* HTML escape */
function esc(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

/* Format COP currency */
function formatCOP(n) {
    return '$' + Number(n || 0).toLocaleString('es-CO', { minimumFractionDigits: 0 });
}

/* Time ago (Bogota timezone) */
function timeAgo(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    const bogotaOffset = -5 * 60;
    const nowBogota = new Date(now.getTime() + (now.getTimezoneOffset() + bogotaOffset) * 60000);
    const diff = Math.floor((nowBogota - d) / 1000);
    if (diff < 0) return 'Hace un momento';
    if (diff < 60) return 'Hace un momento';
    if (diff < 3600) return 'Hace ' + Math.floor(diff / 60) + ' min';
    if (diff < 86400) return 'Hace ' + Math.floor(diff / 3600) + 'h';
    return d.toLocaleDateString('es-CO', { timeZone: 'America/Bogota' });
}

/* Format date (Bogota timezone) */
function formatFecha(dateStr, opts = {}) {
    if (!dateStr) return '';
    const defaults = { timeZone: 'America/Bogota', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    const options = { ...defaults, ...opts };
    return new Date(dateStr).toLocaleString('es-CO', options);
}

/* Generate unique ID */
function generateId(prefix) {
    return prefix + Date.now().toString(36).toUpperCase() + Math.random().toString(36).substring(2, 6).toUpperCase();
}

/* Get product image (fallback to default) */
function getProductImage(img) {
    if (img && img.trim()) return img.trim();
    return DEFAULT_PRODUCT_IMG;
}

/* ===== Keyboard Security ===== */
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'u') {
        e.preventDefault();
        toast('Accion no permitida', 'warning');
        return false;
    }
    if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i')) {
        e.preventDefault();
        toast('Accion no permitida', 'warning');
        return false;
    }
    if (e.ctrlKey && e.shiftKey && (e.key === 'J' || e.key === 'j')) {
        e.preventDefault();
        return false;
    }
    if (e.ctrlKey && e.shiftKey && (e.key === 'C' || e.key === 'c')) {
        e.preventDefault();
        return false;
    }
    if (e.key === 'F12') {
        e.preventDefault();
        return false;
    }
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        return false;
    }
});

document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    toast('Clic derecho deshabilitado', 'warning');
    return false;
});

/* ===== Hamburger & Mobile Sidebar ===== */
document.addEventListener('DOMContentLoaded', function() {
    var h = document.querySelector('.hamburger');
    if (h) {
        h.addEventListener('click', function() {
            var sb = document.querySelector('.sidebar');
            if (sb && sb.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.bell-btn') && !e.target.closest('.notif-dropdown')) {
            var dd = document.querySelector('.notif-dropdown');
            if (dd) dd.classList.remove('show');
        }
    });
    window.addEventListener('resize', function() {
        if (window.innerWidth > 900) closeSidebar();
    });
    window.addEventListener('scroll', function() {
        var dd = document.querySelector('.notif-dropdown');
        if (dd && dd.classList.contains('show')) dd.classList.remove('show');
    }, { passive: true });
});
