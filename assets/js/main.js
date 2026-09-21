/* ============================================
   Comandix Landing — Main JS
   Particles, GSAP, Pricing
   ============================================ */

// ---- API Base ----
const API = '../api';

// ---- Plans Data ----
const PLANS = {
  BASICO: {
    id: 'PLAN-BAS',
    name: 'Básico',
    desc: 'Perfecto para empezar',
    icon: '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
    mensual: 49900,
    anual: 499900,
    maxDevices: 1,
    features: [
      'Punto de venta completo',
      'Gestión de productos',
      'Facturación electrónica',
      '1 dispositivo activo',
      'Soporte por tickets',
      'Reportes básicos'
    ],
    disabled: [
      'Domicilios unificados',
      'WhatsApp integrado',
      'Multi-dispositivo'
    ]
  },
  PROFESIONAL: {
    id: 'PLAN-PRO',
    name: 'Profesional',
    icon: '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    desc: 'Para restaurantes en crecimiento',
    featured: true,
    mensual: 89900,
    anual: 899900,
    maxDevices: 3,
    features: [
      'Todo del plan Básico',
      'Domicilios unificados',
      'WhatsApp integrado',
      'Hasta 3 dispositivos',
      'Comisiones por app',
      'Reportes avanzados',
      'Dashboard en tiempo real'
    ],
    disabled: [
      'Multi-sucursal',
      'API personalizada'
    ]
  },
  ENTERPRISE: {
    id: 'PLAN-ENT',
    name: 'Enterprise',
    icon: '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="12" height="20" rx="1"/><path d="M16 8h4a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-4"/><path d="M8 6h0M12 6h0M8 10h0M12 10h0M8 14h0M12 14h0"/><path d="M9 22v-4h2v4"/></svg>',
    desc: 'Para cadenas y grandes operaciones',
    mensual: 149900,
    anual: 1499900,
    maxDevices: 10,
    features: [
      'Todo del plan Profesional',
      'Hasta 10 dispositivos',
      'Multi-sucursal',
      'API personalizada',
      'Soporte prioritario 24/7',
      'Onboarding dedicado',
      'Personalización total'
    ],
    disabled: []
  }
};

// ---- Format COP ----
function formatCOP(n) {
  return '$' + n.toLocaleString('es-CO');
}

// ---- Render Pricing ----
let currentPeriod = 'mensual';

function renderPricing() {
  const grid = document.getElementById('pricingGrid');
  if (!grid) return;
  grid.innerHTML = '';

  Object.values(PLANS).forEach(plan => {
    const price = currentPeriod === 'mensual' ? plan.mensual : plan.anual;
    const period = currentPeriod === 'mensual' ? '/mes' : '/año';
    const featured = plan.featured ? 'featured' : '';

    let featuresHTML = plan.features.map(f => `<li>${f}</li>`).join('');
    featuresHTML += plan.disabled.map(f => `<li class="disabled">${f}</li>`).join('');

    const btnClass = plan.featured ? 'btn-plan-primary' : 'btn-plan-outline';
    const btnText = 'Comprar ' + plan.name;

    grid.innerHTML += `
      <div class="pricing-card ${featured}" data-plan="${plan.id}">
        <div class="plan-icon">${plan.icon}</div>
        <div class="plan-name">${plan.name}</div>
        <div class="plan-desc">${plan.desc}</div>
        <div class="plan-price">
          <span class="currency">$</span>${price.toLocaleString('es-CO')}<span class="period">${period}</span>
        </div>
        <ul class="plan-features">${featuresHTML}</ul>
        <button class="btn-plan ${btnClass}" onclick="selectPlan('${plan.id}')">${btnText}</button>
      </div>
    `;
  });

  // Animate cards
  if (typeof gsap !== 'undefined') {
    gsap.from('.pricing-card', {
      y: 30, opacity: 0, stagger: 0.1, duration: 0.6, ease: 'power3.out'
    });
  }
}

function selectPlan(planId) {
  // Check if logged in
  const token = localStorage.getItem('ls_token');
  if (token) {
    window.location.href = 'views/portal';
  } else {
    window.location.href = 'views/register';
  }
}

// Pricing toggle
document.addEventListener('DOMContentLoaded', () => {
  renderPricing();

  document.querySelectorAll('.toggle-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.toggle-opt').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentPeriod = btn.dataset.period;
      renderPricing();
    });
  });
});

// ---- Particles ----
if (typeof particlesJS !== 'undefined') {
  particlesJS('particles-js', {
    particles: {
      number: { value: 50, density: { enable: true, value_area: 900 } },
      color: { value: ['#6C3CE1', '#E94560', '#38BDF8', '#4ADE80'] },
      shape: { type: 'circle' },
      opacity: { value: 0.3, random: true },
      size: { value: 2.5, random: true },
      line_linked: {
        enable: true, distance: 150, color: '#6C3CE1',
        opacity: 0.08, width: 1
      },
      move: { enable: true, speed: 0.8, direction: 'none', random: true }
    },
    interactivity: {
      detect_on: 'canvas',
      events: { onhover: { enable: true, mode: 'grab' }, resize: true },
      modes: { grab: { distance: 180, line_linked: { opacity: 0.15 } } }
    },
    retina_detect: true
  });
}

// ---- GSAP Animations ----
if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
  gsap.registerPlugin(ScrollTrigger);

  // Hero
  gsap.from('.hero-badge', { y: 20, opacity: 0, duration: 0.8, delay: 0.2 });
  gsap.from('.hero-title', { y: 40, opacity: 0, duration: 1, delay: 0.4, ease: 'power4.out' });
  gsap.from('.hero-sub', { y: 30, opacity: 0, duration: 0.8, delay: 0.7 });
  gsap.from('.hero-actions', { y: 20, opacity: 0, duration: 0.6, delay: 0.9 });
  gsap.from('.hero-trust', { y: 20, opacity: 0, duration: 0.6, delay: 1.1 });

  // Mockup
  gsap.from('.mockup-window', {
    x: 60, opacity: 0, duration: 1.2, delay: 0.6, ease: 'power3.out'
  });

  // Features scroll
  gsap.utils.toArray('.feature-card').forEach((card, i) => {
    gsap.from(card, {
      scrollTrigger: { trigger: card, start: 'top 85%' },
      y: 40, opacity: 0, duration: 0.7, delay: i * 0.08, ease: 'power3.out'
    });
  });

  // Pricing
  gsap.from('.pricing-toggle', {
    scrollTrigger: { trigger: '.pricing-section', start: 'top 80%' },
    y: 20, opacity: 0, duration: 0.6
  });

  // FAQ
  gsap.utils.toArray('.faq-item').forEach((item, i) => {
    gsap.from(item, {
      scrollTrigger: { trigger: item, start: 'top 88%' },
      y: 20, opacity: 0, duration: 0.5, delay: i * 0.06
    });
  });

  // CTA
  gsap.from('.cta-box', {
    scrollTrigger: { trigger: '.cta-section', start: 'top 80%' },
    scale: 0.95, opacity: 0, duration: 0.8, ease: 'back.out(1.2)'
  });

  // Footer
  gsap.from('.footer-inner', {
    scrollTrigger: { trigger: '.footer', start: 'top 90%' },
    y: 20, opacity: 0, duration: 0.6
  });
}
