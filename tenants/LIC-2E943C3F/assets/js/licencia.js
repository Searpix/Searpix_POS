/* ============================================
   FREAKERS POS v6 - License Client Module
   Handles: License check, activation, removal,
   background verification, expired screen routing
   ============================================ */

const LicenciaModule = (() => {
    
    // License check interval: 1 hour (3600000ms)
    const CHECK_INTERVAL = 3600000;
    let checkTimer = null;

    /**
     * Check current license status from the server
     * @returns {Promise<Object>} License status object
     */
    async function checkLicenseStatus() {
        try {
            const resp = await fetch('api/licencia?action=verificar', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });
            const data = await resp.json();
            return data;
        } catch (err) {
            console.error('[Licencia] Error checking license:', err);
            return {
                exito: false,
                estado: 'ERROR_CONEXION',
                mensaje: 'No se pudo conectar con el servidor.'
            };
        }
    }

    /**
     * Activate a license key
     * @param {string} key - License key in format XXXX-XXXX-XXXX-XXXX
     * @returns {Promise<Object>} Activation result
     */
    async function activateLicense(key) {
        if (!key || key.trim().length < 10) {
            return {
                exito: false,
                mensaje: 'Ingresa una clave de activacion valida.'
            };
        }

        try {
            const resp = await fetch('api/licencia', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'activar',
                    clave: key.trim()
                })
            });
            const data = await resp.json();
            
            if (data.exito) {
                // Update session if exists
                const sesion = getSesion();
                if (sesion) {
                    sesion.licencia_estado = 'ACTIVA';
                    setSesion(sesion);
                }
                // Restart background check
                startBackgroundCheck();
            }
            
            return data;
        } catch (err) {
            console.error('[Licencia] Activation error:', err);
            return {
                exito: false,
                mensaje: 'Error de conexion al activar la licencia.'
            };
        }
    }

    /**
     * Remove current license (admin only)
     * @param {string} adminPassword - Admin password for confirmation
     * @returns {Promise<Object>} Removal result
     */
    async function removeLicense(adminPassword) {
        if (!adminPassword) {
            return {
                exito: false,
                mensaje: 'Se requiere la contrasena de administrador.'
            };
        }

        try {
            const resp = await fetch('api/licencia', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'remover',
                    adminPassword: adminPassword
                })
            });
            const data = await resp.json();
            
            if (data.exito) {
                // Clear session
                clearSesion();
                // Stop background check
                stopBackgroundCheck();
                // Redirect to license page
                redirectToLicensePage('SIN_LICENCIA');
            }
            
            return data;
        } catch (err) {
            console.error('[Licencia] Removal error:', err);
            return {
                exito: false,
                mensaje: 'Error de conexion al remover la licencia.'
            };
        }
    }

    /**
     * Force a remote verification check
     * @returns {Promise<Object>} Check result
     */
    async function forceRemoteCheck() {
        try {
            const resp = await fetch('api/licencia', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'check' })
            });
            return await resp.json();
        } catch (err) {
            console.error('[Licencia] Remote check error:', err);
            return { exito: false, mensaje: 'Error de conexion.' };
        }
    }

    /**
     * Start background license verification (1-hour interval)
     */
    function startBackgroundCheck() {
        // Clear any existing timer
        stopBackgroundCheck();
        
        checkTimer = setInterval(async () => {
            const status = await checkLicenseStatus();
            
            if (!status.exito || status.estado !== 'ACTIVA') {
                console.warn('[Licencia] Background check failed:', status.estado, status.mensaje);
                handleLicenseIssue(status);
            } else {
                console.log('[Licencia] Background check OK');
            }
        }, CHECK_INTERVAL);
        
        console.log('[Licencia] Background check started (every', CHECK_INTERVAL / 1000, 'seconds)');
    }

    /**
     * Stop background license verification
     */
    function stopBackgroundCheck() {
        if (checkTimer) {
            clearInterval(checkTimer);
            checkTimer = null;
            console.log('[Licencia] Background check stopped');
        }
    }

    /**
     * Handle a license issue detected during background check
     * @param {Object} status - License status from server
     */
    function handleLicenseIssue(status) {
        const estado = status.estado || 'DESCONOCIDO';
        
        // Create notification
        if (typeof NotificationModule !== 'undefined' && NotificationModule.add) {
            NotificationModule.add({
                tipo: 'LICENCIA',
                titulo: 'Problema de Licencia',
                mensaje: status.mensaje || 'La licencia no es valida.',
                idReferencia: estado
            });
        }
        
        // For critical states, force logout and redirect
        const criticalStates = ['EXPIRADA', 'REVOCADA', 'SUSPENDIDA', 'SIN_LICENCIA', 'SIN_CONEXION'];
        
        if (criticalStates.includes(estado)) {
            // Force logout
            clearSesion();
            stopBackgroundCheck();
            
            // Show overlay then redirect
            showLicenseOverlay(status);
            
            // After 3 seconds, redirect to license page
            setTimeout(() => {
                redirectToLicensePage(estado);
            }, 3000);
        }
    }

    /**
     * Show a license issue overlay on the current page
     * @param {Object} status - License status
     */
    function showLicenseOverlay(status) {
        // Remove existing overlay if any
        const existing = document.getElementById('license-overlay');
        if (existing) existing.remove();
        
        const overlay = document.createElement('div');
        overlay.id = 'license-overlay';
        overlay.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.92); z-index: 99999;
            display: flex; align-items: center; justify-content: center;
            flex-direction: column; gap: 16px;
            animation: fadeIn 0.3s ease;
        `;
        
        const icon = document.createElement('div');
        icon.style.cssText = 'font-size: 64px; opacity: 0.8;';
        icon.textContent = '🔒';
        
        const title = document.createElement('h2');
        title.style.cssText = 'color: #E94560; font-family: Inter, sans-serif; font-size: 1.5rem; font-weight: 900;';
        title.textContent = 'Licencia ' + (status.estado || 'Invalida');
        
        const msg = document.createElement('p');
        msg.style.cssText = 'color: rgba(255,255,255,0.5); font-family: Inter, sans-serif; font-size: 0.85rem; max-width: 400px; text-align: center;';
        msg.textContent = status.mensaje || 'Redirigiendo...';
        
        const spinner = document.createElement('div');
        spinner.style.cssText = `
            width: 32px; height: 32px; border: 3px solid rgba(255,255,255,0.1);
            border-top: 3px solid #E94560; border-radius: 50%;
            animation: spin 0.8s linear infinite;
        `;
        
        // Style for animations
        if (!document.getElementById('license-overlay-styles')) {
            const style = document.createElement('style');
            style.id = 'license-overlay-styles';
            style.textContent = `
                @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
                @keyframes spin { to { transform: rotate(360deg); } }
            `;
            document.head.appendChild(style);
        }
        
        overlay.appendChild(icon);
        overlay.appendChild(title);
        overlay.appendChild(msg);
        overlay.appendChild(spinner);
        document.body.appendChild(overlay);
    }

    /**
     * Redirect to the license expired page
     * @param {string} estado - License state code
     */
    function redirectToLicensePage(estado) {
        const params = new URLSearchParams();
        if (estado) params.set('estado', estado);
        window.location.href = 'licencia-expirada?' + params.toString();
    }

    /**
     * Get the license status from the current session token
     * @returns {string|null} License state code
     */
    function getSessionLicenseState() {
        const sesion = getSesion();
        return sesion ? (sesion.licencia_estado || null) : null;
    }

    /**
     * Intercept API responses for license-related errors
     * Call this after any API call that returns a license error code
     * @param {Object} response - API response object
     */
    function interceptLicenseError(response) {
        if (!response) return false;
        
        const code = response.codigo_error || '';
        
        if (code.startsWith('LICENCIA_')) {
            const estado = code.replace('LICENCIA_', '');
            
            handleLicenseIssue({
                exito: false,
                estado: estado,
                mensaje: response.mensaje || 'Problema con la licencia.'
            });
            
            return true; // Error was handled
        }
        
        return false; // Not a license error
    }

    /**
     * Pre-check before login — verify license exists
     * @returns {Promise<boolean>} True if license is OK to proceed
     */
    async function preLoginCheck() {
        const status = await checkLicenseStatus();
        
        if (!status.exito && status.estado && status.estado !== 'SIN_TABLAS') {
            redirectToLicensePage(status.estado);
            return false;
        }
        
        return true;
    }

    // ===== Session helpers (mirror app.js functions) =====
    function getSesion() {
        try {
            const raw = sessionStorage.getItem('pos_sesion');
            if (!raw) return null;
            const sesion = JSON.parse(atob(raw));
            if (sesion.exp && sesion.exp < Date.now() / 1000) {
                sessionStorage.removeItem('pos_sesion');
                return null;
            }
            return sesion;
        } catch (e) {
            return null;
        }
    }

    function setSesion(sesion) {
        if (!sesion) return;
        const payload = { ...sesion, exp: Math.floor(Date.now() / 1000) + 86400 * 7 };
        sessionStorage.setItem('pos_sesion', btoa(JSON.stringify(payload)));
    }

    function clearSesion() {
        sessionStorage.removeItem('pos_sesion');
    }

    // ===== Public API =====
    return {
        checkLicenseStatus,
        activateLicense,
        removeLicense,
        forceRemoteCheck,
        startBackgroundCheck,
        stopBackgroundCheck,
        handleLicenseIssue,
        interceptLicenseError,
        preLoginCheck,
        getSessionLicenseState,
        redirectToLicensePage
    };

})();
