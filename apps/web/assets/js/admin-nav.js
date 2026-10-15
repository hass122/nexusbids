/**
 * NexusBids Admin Command Center Navigation Component
 * Provides responsive multi-module navigation across all 21 administrative sections
 *
 * FIXED v2.0:
 *  - Replaced hardcoded dark-only inline styles with theme-aware CSS
 *  - Active pill now uses solid brand color (works in both light & dark)
 *  - Hover states respect current theme
 *  - Alert items have proper light-mode colors
 *  - Added subtle scroll shadows on edges for better UX
 *  - Keyboard focus rings for accessibility
 */
(function () {
  'use strict';

  const ADMIN_NAV_ITEMS = [
    { href: '/admin/dashboard.html', icon: 'fa-gauge-high', label: 'Dashboard', group: 'core' },
    { href: '/admin/users.html', icon: 'fa-users', label: 'Users', group: 'core' },
    { href: '/admin/sellers.html', icon: 'fa-store', label: 'Sellers', group: 'core' },
    { href: '/admin/kyc.html', icon: 'fa-id-card-clip', label: 'KYC Reviews', group: 'core', highlight: true },

    { href: '/admin/auctions.html', icon: 'fa-gavel', label: 'Auctions', group: 'catalog' },
    { href: '/admin/categories.html', icon: 'fa-layer-group', label: 'Categories', group: 'catalog' },
    { href: '/admin/bids.html', icon: 'fa-hand-holding-dollar', label: 'Live Bids', group: 'catalog' },

    { href: '/admin/orders.html', icon: 'fa-box-open', label: 'Orders', group: 'finance' },
    { href: '/admin/payments.html', icon: 'fa-receipt', label: 'Payments', group: 'finance' },
    { href: '/admin/escrow.html', icon: 'fa-shield-halved', label: 'Escrow', group: 'finance' },
    { href: '/admin/refunds.html', icon: 'fa-rotate-left', label: 'Refunds', group: 'finance' },
    { href: '/admin/payouts.html', icon: 'fa-money-bill-transfer', label: 'Payouts', group: 'finance' },
    { href: '/admin/disputes.html', icon: 'fa-scale-balanced', label: 'Disputes', group: 'finance' },

    { href: '/admin/fraud-alerts.html', icon: 'fa-triangle-exclamation', label: 'Fraud Alerts', group: 'security', alert: true },
    { href: '/admin/security.html', icon: 'fa-shield-virus', label: 'Security Alerts', group: 'security' },
    { href: '/admin/activity-logs.html', icon: 'fa-clipboard-list', label: 'Audit Logs', group: 'security' },
    { href: '/admin/reports.html', icon: 'fa-flag', label: 'Reports', group: 'security' },

    { href: '/admin/analytics.html', icon: 'fa-chart-pie', label: 'Analytics', group: 'system' },
    { href: '/admin/notifications.html', icon: 'fa-bullhorn', label: 'Broadcasts', group: 'system' },
    { href: '/admin/settings.html', icon: 'fa-sliders', label: 'Settings', group: 'system' },
    { href: '/admin/health.html', icon: 'fa-heart-pulse', label: 'System Health', group: 'system' }
  ];

  // ─── Inject theme-aware styles (once) ─────────────────────────────────────
  function injectAdminNavStyles() {
    if (document.getElementById('nb-admin-nav-styles')) return;
    const style = document.createElement('style');
    style.id = 'nb-admin-nav-styles';
    style.textContent = `
      /* ===== Admin Nav Shell ===== */
      .nb-admin-nav-shell {
        position: relative;
        background: var(--a-surface, #ffffff);
        border: 1px solid var(--a-border, #e4e8f0);
        border-radius: 16px;
        padding: 8px 10px;
        margin-bottom: 24px;
        box-shadow: 0 1px 2px rgba(15, 23, 41, .04), 0 2px 8px -2px rgba(15, 23, 41, .06);
        transition: background-color .25s ease, border-color .25s ease;
      }
      html.dark .nb-admin-nav-shell {
        background: #0e1422;
        border-color: #1c2740;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .35);
      }

      /* ===== Scroll Container ===== */
      .nb-admin-nav-scroll {
        display: flex;
        align-items: center;
        gap: 4px;
        overflow-x: auto;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
        padding: 2px;
      }
      .nb-admin-nav-scroll::-webkit-scrollbar { display: none; }

      /* ===== Pill (base) ===== */
      .nb-admin-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 13px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        color: var(--a-text-muted, #55607a);
        background: transparent;
        border: 1px solid transparent;
        transition: background-color .18s ease, color .18s ease,
                    border-color .18s ease, transform .18s ease,
                    box-shadow .18s ease;
        line-height: 1;
        cursor: pointer;
      }
      .nb-admin-pill i {
        font-size: 0.72rem;
        opacity: .9;
        transition: color .18s ease, transform .18s ease;
      }
      .nb-admin-pill span { line-height: 1.2; }

      /* ===== Hover (light theme) ===== */
      .nb-admin-pill:hover {
        background: #f1f4fa;
        color: #0f1729;
        border-color: #e4e8f0;
        transform: translateY(-1px);
      }
      .nb-admin-pill:hover i { color: #6366f1; }

      /* ===== Hover (dark theme) ===== */
      html.dark .nb-admin-pill { color: #96a2ba; }
      html.dark .nb-admin-pill:hover {
        background: #172033;
        color: #e9eef8;
        border-color: #2a3854;
      }
      html.dark .nb-admin-pill:hover i { color: #818cf8; }

      /* ===== Active pill — solid brand (works in both themes) ===== */
      .nb-admin-pill.is-active {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        color: #ffffff;
        border-color: #4f46e5;
        font-weight: 700;
        box-shadow: 0 4px 12px -2px rgba(79, 70, 229, .35),
                    0 2px 4px -1px rgba(79, 70, 229, .25);
      }
      .nb-admin-pill.is-active i { color: #ffffff; opacity: 1; }
      .nb-admin-pill.is-active:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px -2px rgba(79, 70, 229, .45),
                    0 3px 6px -1px rgba(79, 70, 229, .3);
        background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
      }

      /* Dark theme active — brighter glow */
      html.dark .nb-admin-pill.is-active {
        background: linear-gradient(135deg, #818cf8 0%, #6366f1 100%);
        border-color: #6366f1;
        color: #ffffff;
        box-shadow: 0 4px 14px -2px rgba(99, 102, 241, .55),
                    0 2px 6px -1px rgba(99, 102, 241, .4);
      }

      /* ===== Alert pill (e.g. Fraud Alerts) ===== */
      .nb-admin-pill.is-alert {
        color: #be123c;
        background: rgba(225, 29, 72, .06);
        border-color: rgba(225, 29, 72, .18);
      }
      .nb-admin-pill.is-alert i { color: #e11d48; opacity: 1; }
      .nb-admin-pill.is-alert:hover {
        color: #9f1239;
        background: rgba(225, 29, 72, .1);
        border-color: rgba(225, 29, 72, .3);
      }
      html.dark .nb-admin-pill.is-alert {
        color: #fda4af;
        background: rgba(244, 63, 94, .1);
        border-color: rgba(244, 63, 94, .22);
      }
      html.dark .nb-admin-pill.is-alert i { color: #fb7185; }
      html.dark .nb-admin-pill.is-alert:hover {
        color: #fecdd3;
        background: rgba(244, 63, 94, .18);
        border-color: rgba(244, 63, 94, .35);
      }

      /* ===== Keyboard focus ring ===== */
      .nb-admin-pill:focus-visible {
        outline: 2px solid #6366f1;
        outline-offset: 2px;
      }
      html.dark .nb-admin-pill:focus-visible { outline-color: #818cf8; }

      /* ===== Optional: subtle scroll fade hints ===== */
      .nb-admin-nav-shell::before,
      .nb-admin-nav-shell::after {
        content: "";
        position: absolute;
        top: 8px;
        bottom: 8px;
        width: 24px;
        pointer-events: none;
        opacity: 0;
        transition: opacity .25s ease;
        z-index: 2;
      }
      .nb-admin-nav-shell::before {
        left: 0;
        background: linear-gradient(90deg, var(--a-surface, #fff), transparent);
        border-top-left-radius: 16px;
        border-bottom-left-radius: 16px;
      }
      .nb-admin-nav-shell::after {
        right: 0;
        background: linear-gradient(270deg, var(--a-surface, #fff), transparent);
        border-top-right-radius: 16px;
        border-bottom-right-radius: 16px;
      }
      html.dark .nb-admin-nav-shell::before {
        background: linear-gradient(90deg, #0e1422, transparent);
      }
      html.dark .nb-admin-nav-shell::after {
        background: linear-gradient(270deg, #0e1422, transparent);
      }
      .nb-admin-nav-shell.has-overflow-left::before,
      .nb-admin-nav-shell.has-overflow-right::after { opacity: 1; }

      /* ===== Mobile tweaks ===== */
      @media (max-width: 640px) {
        .nb-admin-nav-shell { padding: 6px 8px; border-radius: 14px; }
        .nb-admin-pill { padding: 7px 11px; font-size: 0.7rem; }
        .nb-admin-pill i { font-size: 0.68rem; }
      }
    `;
    document.head.appendChild(style);
  }

  // ─── Track horizontal scroll to toggle fade hints ────────────────────────
  function updateScrollHints(shell, scroll) {
    const hasLeft = scroll.scrollLeft > 4;
    const hasRight = scroll.scrollLeft + scroll.clientWidth < scroll.scrollWidth - 4;
    shell.classList.toggle('has-overflow-left', hasLeft);
    shell.classList.toggle('has-overflow-right', hasRight);
  }

  // ─── Render ───────────────────────────────────────────────────────────────
  function renderAdminNav(mountId = 'admin-subnav') {
    const el = document.getElementById(mountId);
    if (!el) return;

    injectAdminNavStyles();

    const currentPath = window.location.pathname.toLowerCase();

    const pillsHtml = ADMIN_NAV_ITEMS.map(item => {
      const isActive = currentPath.endsWith(item.href.toLowerCase());
      const isAlert = !!item.alert && !isActive;

      const classes = [
        'nb-admin-pill',
        isActive ? 'is-active' : '',
        isAlert ? 'is-alert' : ''
      ].filter(Boolean).join(' ');

      // Icon color is handled entirely by CSS now
      return `
        <a href="${item.href}"
           class="${classes}"
           ${isActive ? 'aria-current="page"' : ''}
           title="${item.label}">
          <i class="fa-solid ${item.icon}"></i>
          <span>${item.label}</span>
        </a>
      `;
    }).join('');

    el.innerHTML = `
      <div class="nb-admin-nav-shell">
        <div class="nb-admin-nav-scroll">
          ${pillsHtml}
        </div>
      </div>
    `;

    // Wire scroll hint fades
    const shell = el.querySelector('.nb-admin-nav-shell');
    const scroll = el.querySelector('.nb-admin-nav-scroll');
    if (shell && scroll) {
      updateScrollHints(shell, scroll);
      scroll.addEventListener('scroll', () => updateScrollHints(shell, scroll), { passive: true });
      window.addEventListener('resize', () => updateScrollHints(shell, scroll));
    }

    // Auto-scroll active pill into view (smooth, centered)
    const active = el.querySelector('.nb-admin-pill.is-active');
    if (active && scroll) {
      requestAnimationFrame(() => {
        const target = active.offsetLeft - (scroll.clientWidth - active.clientWidth) / 2;
        scroll.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
      });
    }
  }

  window.AdminNav = {
    init: renderAdminNav,
    items: ADMIN_NAV_ITEMS,
    refresh: () => renderAdminNav('admin-subnav')
  };

  document.addEventListener('DOMContentLoaded', () => {
    renderAdminNav('admin-subnav');
  });
})();