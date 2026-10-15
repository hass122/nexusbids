/**
 * NexusBids — Theme Engine v2 + UI Framework
 * FOUC-free dark/light/system mode with cross-tab sync, smooth transitions,
 * mobile hamburger menu, notification badge, and full navbar rendering.
 */

// ─── UTILITIES (hoisted helpers, safe everywhere) ─────────────────────────────

function escapeHtml(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// ─── THEME ENGINE ─────────────────────────────────────────────────────────────

const NB_THEME_KEY = 'nexusbids_theme'; // 'dark' | 'light' | 'system'

const Theme = {
  applyEarly() {
    let saved = 'system';
    try { saved = localStorage.getItem(NB_THEME_KEY) || 'system'; } catch { }
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = saved === 'dark' || (saved === 'system' && prefersDark);
    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
  },

  init() {
    this.applyEarly();

    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = (e) => {
      let mode = 'system';
      try { mode = localStorage.getItem(NB_THEME_KEY) || 'system'; } catch { }
      if (mode === 'system') {
        document.documentElement.classList.toggle('dark', e.matches);
        document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
        this._syncIcons();
        this._syncCharts();
      }
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);

    window.addEventListener('storage', (e) => {
      if (e.key === NB_THEME_KEY) {
        this.applyEarly();
        this._syncIcons();
        this._syncCharts();
      }
    });

    this._syncIcons();
  },

  get current() {
    try { return localStorage.getItem(NB_THEME_KEY) || 'system'; }
    catch { return 'system'; }
  },

  get isDark() {
    return document.documentElement.classList.contains('dark');
  },

  toggle() {
    const modes = ['system', 'dark', 'light'];
    const next = modes[(modes.indexOf(this.current) + 1) % 3];
    try { localStorage.setItem(NB_THEME_KEY, next); } catch { }
    this.applyEarly();
    this._syncIcons();
    this._syncCharts();
    const labels = { system: 'System theme active', dark: 'Dark mode active', light: 'Light mode active' };
    if (window.UI && typeof UI.showToast === 'function') {
      UI.showToast(labels[next], 'info', 2000);
    }
  },

  _syncIcons() {
    const dark = this.isDark;
    const mode = this.current;
    document.querySelectorAll('[data-theme-icon]').forEach(el => {
      const icon = el.querySelector('i') || el;
      if (!icon) return;
      icon.className = dark
        ? 'fa-solid fa-sun text-amber-400'
        : (mode === 'system'
          ? 'fa-solid fa-circle-half-stroke text-slate-400'
          : 'fa-solid fa-moon text-slate-500');
    });
  },

  _syncCharts() {
    if (window._nbCharts) {
      window._nbCharts.forEach(chart => {
        try {
          const dark = this.isDark;
          if (chart.options.scales?.x) {
            chart.options.scales.x.ticks.color = dark ? '#64748b' : '#94a3b8';
            chart.options.scales.x.grid.color = dark ? 'rgba(51,65,85,.3)' : 'rgba(226,232,240,.8)';
          }
          if (chart.options.scales?.y) {
            chart.options.scales.y.ticks.color = dark ? '#64748b' : '#94a3b8';
            chart.options.scales.y.grid.color = dark ? 'rgba(51,65,85,.3)' : 'rgba(226,232,240,.8)';
          }
          if (chart.options.plugins?.legend?.labels) {
            chart.options.plugins.legend.labels.color = dark ? '#94a3b8' : '#64748b';
          }
          chart.update('none');
        } catch { }
      });
    }
  }
};

window.Theme = Theme;

// ─── MOBILE MENU ──────────────────────────────────────────────────────────────

const MobileMenu = {
  open: false,

  toggle() {
    this.open = !this.open;
    const menu = document.getElementById('nb-mobile-menu');
    const overlay = document.getElementById('nb-mobile-overlay');
    const btn = document.getElementById('nb-hamburger-btn');
    if (!menu) return;
    menu.classList.toggle('open', this.open);
    if (btn) btn.setAttribute('aria-expanded', String(this.open));
    if (overlay) {
      overlay.classList.toggle('hidden', !this.open);
      overlay.style.display = this.open ? 'block' : 'none';
    }
    document.body.style.overflow = this.open ? 'hidden' : '';
  },

  close() {
    this.open = false;
    const menu = document.getElementById('nb-mobile-menu');
    const overlay = document.getElementById('nb-mobile-overlay');
    const btn = document.getElementById('nb-hamburger-btn');
    if (menu) menu.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    if (overlay) {
      overlay.classList.add('hidden');
      overlay.style.display = 'none';
    }
    document.body.style.overflow = '';
  }
};

window.MobileMenu = MobileMenu;

// Global Escape listener to dismiss mobile menus & dropdowns
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    if (MobileMenu.open) MobileMenu.close();
    const searchDropdown = document.getElementById('nb-mobile-search-dropdown');
    if (searchDropdown && !searchDropdown.classList.contains('hidden')) {
      searchDropdown.classList.add('hidden');
    }
    const userDropdown = document.getElementById('nb-user-dropdown');
    if (userDropdown && !userDropdown.classList.contains('hidden')) {
      userDropdown.classList.add('hidden');
    }
  }
});

// ─── MAIN UI OBJECT ───────────────────────────────────────────────────────────

const UI = {
  serverOffsetMs: 0,
  _navbarReady: false,
  _footerReady: false,
  _outsideClickBound: false,

  escapeHtml(str) { return escapeHtml(str); },

  async syncServerTime() {
    try {
      const start = Date.now();
      const res = await fetch('/api/health');
      if (res.ok) {
        const data = await res.json();
        const serverTime = new Date(data.data?.timestamp || res.headers.get('date')).getTime();
        const latency = (Date.now() - start) / 2;
        this.serverOffsetMs = (serverTime + latency) - Date.now();
      }
    } catch {
      this.serverOffsetMs = 0;
    }
  },

  getSyncedNow() { return Date.now() + this.serverOffsetMs; },

  toggleMobileSearch() {
    const bar = document.getElementById('nb-mobile-search-dropdown');
    if (!bar) return;
    const isHidden = bar.classList.contains('hidden');
    bar.classList.toggle('hidden', !isHidden);
    if (isHidden) {
      const input = document.getElementById('nb-mobile-search-input');
      if (input) setTimeout(() => input.focus(), 60);
    }
  },

  formatCountdown(endTimeStr) {
    if (!endTimeStr) return { ended: true, text: 'Ended', isUrgent: false };
    const diff = Math.max(0, Math.floor((new Date(endTimeStr).getTime() - this.getSyncedNow()) / 1000));
    if (diff === 0) return { ended: true, text: 'Ended', isUrgent: false };
    const days = Math.floor(diff / 86400);
    const hours = Math.floor((diff % 86400) / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;
    const isUrgent = diff < 3600;
    const text = days > 0
      ? `${days}d ${hours}h`
      : hours > 0
        ? `${hours}h ${minutes}m`
        : `${minutes}m ${seconds}s`;
    return { ended: false, text, days, hours, minutes, seconds, isUrgent };
  },

  formatPKR(amount) {
    const val = parseFloat(amount) || 0;
    return `Rs.\u00a0${val.toLocaleString('en-PK')}`;
  },

  formatCompact(amount) {
    const val = parseFloat(amount) || 0;
    if (val >= 10000000) return `${(val / 10000000).toFixed(2)} Cr`;
    if (val >= 100000) return `${(val / 100000).toFixed(1)} Lac`;
    if (val >= 1000) return `${Math.round(val / 1000)}K`;
    return val.toLocaleString('en-PK');
  },

  // ─── Toasts ─────────────────────────────────────────────────────────────
  showToast(message, type = 'info', duration = 4000) {
    let container = document.getElementById('nb-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'nb-toast-container';
      container.style.cssText = 'position:fixed;bottom:1.25rem;right:1.25rem;z-index:9999;display:flex;flex-direction:column;gap:.625rem;pointer-events:none;max-width:22rem;width:calc(100% - 2.5rem)';
      document.body.appendChild(container);
    }

    const styles = {
      success: { bg: '#052e16', border: '#166534', text: '#86efac', icon: 'fa-circle-check text-emerald-400' },
      error: { bg: '#450a0a', border: '#991b1b', text: '#fca5a5', icon: 'fa-circle-exclamation text-rose-400' },
      info: { bg: '#0c1a4f', border: '#1e40af', text: '#bfdbfe', icon: 'fa-circle-info text-blue-400' },
      warning: { bg: '#451a03', border: '#92400e', text: '#fcd34d', icon: 'fa-triangle-exclamation text-amber-400' }
    };
    const s = styles[type] || styles.info;

    const toast = document.createElement('div');
    toast.style.cssText = `pointer-events:auto;display:flex;align-items:center;gap:.75rem;padding:.875rem 1rem;border-radius:.875rem;border:1px solid ${s.border};background:${s.bg};color:${s.text};font-size:.75rem;font-weight:600;line-height:1.4;box-shadow:0 10px 30px rgba(0,0,0,.4);backdrop-filter:blur(12px);transform:translateY(1rem);opacity:0;transition:all .25s cubic-bezier(.4,0,.2,1)`;
    toast.innerHTML = `
      <i class="fa-solid ${s.icon}" style="flex-shrink:0;font-size:.875rem"></i>
      <span style="flex:1">${escapeHtml(message)}</span>
      <button onclick="this.parentElement.remove()" aria-label="Dismiss" style="color:rgba(255,255,255,.4);background:none;border:none;cursor:pointer;padding:0;line-height:1"><i class="fa-solid fa-xmark"></i></button>
    `;

    container.appendChild(toast);
    requestAnimationFrame(() => {
      toast.style.transform = 'translateY(0)';
      toast.style.opacity = '1';
    });

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(.5rem)';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  },

  // ─── Confirm dialog (v2 — bulletproof) ──────────────────────────────────
  confirm({
    title = 'Confirm',
    message = 'Are you sure?',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    type = 'danger'
  } = {}) {
    return new Promise((resolve) => {
      // Prevent duplicate modals
      document.querySelectorAll('[data-nb-confirm-modal]').forEach(el => el.remove());

      const isDark = Theme.isDark;
      const colors = {
        danger: { icon: '#ef4444', btn: '#be123c', btnHover: '#9f1239', emoji: 'fa-triangle-exclamation' },
        warning: { icon: '#f59e0b', btn: '#b45309', btnHover: '#92400e', emoji: 'fa-triangle-exclamation' },
        primary: { icon: '#6366f1', btn: '#4f46e5', btnHover: '#4338ca', emoji: 'fa-circle-question' },
        success: { icon: '#10b981', btn: '#059669', btnHover: '#047857', emoji: 'fa-circle-check' }
      };
      const c = colors[type] || colors.danger;

      const bg = isDark ? '#0b1120' : '#ffffff';
      const border = isDark ? 'rgba(51,65,85,.6)' : '#e2e8f0';
      const textColor = isDark ? '#f1f5f9' : '#0f172a';
      const mutedColor = isDark ? '#94a3b8' : '#64748b';
      const cancelBg = isDark ? '#1e293b' : '#f1f5f9';
      const cancelText2 = isDark ? '#cbd5e1' : '#475569';

      // Unique IDs to avoid conflicts
      const uniq = 'nbc_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
      const confirmBtnId = uniq + '_ok';
      const cancelBtnId = uniq + '_cancel';

      // Backdrop
      const backdrop = document.createElement('div');
      backdrop.setAttribute('data-nb-confirm-modal', '1');
      backdrop.style.cssText = [
        'position:fixed',
        'inset:0',
        'top:0', 'right:0', 'bottom:0', 'left:0',
        'z-index:2147483647',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        'padding:1rem',
        'background:rgba(2,6,23,.78)',
        'backdrop-filter:blur(8px)',
        '-webkit-backdrop-filter:blur(8px)',
        'opacity:0',
        'transition:opacity .2s ease',
        'pointer-events:auto'
      ].join(';');

      // Inner card
      const card = document.createElement('div');
      card.className = 'nb-modal-enter';
      card.style.cssText = [
        'max-width:26rem',
        'width:100%',
        'padding:1.75rem',
        'border-radius:1.25rem',
        'background:' + bg,
        'border:1px solid ' + border,
        'box-shadow:0 25px 60px rgba(0,0,0,.5)',
        'transform:scale(.96)',
        'transition:transform .2s cubic-bezier(.2,.8,.2,1)',
        'pointer-events:auto'
      ].join(';');

      card.innerHTML = `
        <div style="width:3rem;height:3rem;border-radius:.875rem;background:${c.icon}1a;border:1px solid ${c.icon}40;display:flex;align-items:center;justify-content:center;margin-bottom:1.25rem">
          <i class="fa-solid ${c.emoji}" style="color:${c.icon};font-size:1.125rem"></i>
        </div>
        <h3 style="font-size:1rem;font-weight:700;color:${textColor};margin:0 0 .5rem">${escapeHtml(title)}</h3>
        <p style="font-size:.8125rem;color:${mutedColor};line-height:1.6;margin:0 0 1.5rem">${escapeHtml(message)}</p>
        <div style="display:flex;gap:.75rem;justify-content:flex-end">
          <button id="${cancelBtnId}" type="button"
            style="padding:.6rem 1.1rem;border-radius:.625rem;background:${cancelBg};color:${cancelText2};border:none;font-weight:600;font-size:.8rem;cursor:pointer;font-family:inherit">
            ${escapeHtml(cancelText)}
          </button>
          <button id="${confirmBtnId}" type="button"
            style="padding:.6rem 1.25rem;border-radius:.625rem;background:${c.btn};color:#fff;border:none;font-weight:700;font-size:.8rem;cursor:pointer;font-family:inherit">
            ${escapeHtml(confirmText)}
          </button>
        </div>
      `;

      backdrop.appendChild(card);

      // Lock body scroll
      const prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      // Cleanup
      let done = false;
      const cleanup = (result) => {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', escHandler);
        document.body.style.overflow = prevOverflow;
        backdrop.style.opacity = '0';
        card.style.transform = 'scale(.96)';
        setTimeout(() => {
          if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
          resolve(result);
        }, 180);
      };

      const escHandler = (e) => { if (e.key === 'Escape') cleanup(false); };

      // Bind events
      backdrop.querySelector('#' + confirmBtnId).addEventListener('click', (e) => {
        e.stopPropagation();
        cleanup(true);
      });
      backdrop.querySelector('#' + cancelBtnId).addEventListener('click', (e) => {
        e.stopPropagation();
        cleanup(false);
      });
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) cleanup(false);
      });
      document.addEventListener('keydown', escHandler);

      // Mount
      document.body.appendChild(backdrop);

      // Trigger enter animation
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          backdrop.style.opacity = '1';
          card.style.transform = 'scale(1)';
        });
      });

      // Focus confirm button for keyboard users
      setTimeout(() => {
        const btn = document.getElementById(confirmBtnId);
        if (btn) btn.focus();
      }, 250);
    });
  },

  // ─── Skeletons ──────────────────────────────────────────────────────────
  renderAuctionSkeletons(container, count = 4) {
    if (!container) return;
    container.innerHTML = Array(count).fill(0).map(() => `
      <div class="nb-card overflow-hidden flex flex-col">
        <div class="h-44 nb-skeleton"></div>
        <div style="padding:1.25rem;flex:1;display:flex;flex-direction:column;gap:.75rem">
          <div class="nb-skeleton" style="height:.625rem;width:4rem"></div>
          <div class="nb-skeleton" style="height:1.125rem;width:80%"></div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.5rem">
            <div class="nb-skeleton" style="height:1.5rem;width:6rem"></div>
            <div class="nb-skeleton" style="height:2rem;width:3.5rem;border-radius:.625rem"></div>
          </div>
        </div>
      </div>`).join('');
  },

  renderTableSkeletons(tbody, cols = 5, rows = 5) {
    if (!tbody) return;
    tbody.innerHTML = Array(rows).fill(0).map(() => `
      <tr>${Array(cols).fill(0).map(() =>
      `<td style="padding:.875rem 1rem"><div class="nb-skeleton" style="height:.75rem;border-radius:.375rem"></div></td>`
    ).join('')}</tr>`).join('');
  },

  renderEmptyState(container, {
    icon = 'fa-inbox',
    title = 'Nothing here yet',
    description = '',
    actionText = null,
    actionHref = null,
    onAction = null
  } = {}) {
    if (!container) return;
    const btn = actionText
      ? (actionHref
        ? `<a href="${escapeHtml(actionHref)}" class="nb-btn nb-btn-primary" style="margin-top:1rem">${escapeHtml(actionText)}</a>`
        : `<button onclick="${onAction || ''}" class="nb-btn nb-btn-primary" style="margin-top:1rem">${escapeHtml(actionText)}</button>`)
      : '';
    container.innerHTML = `
      <div style="padding:4rem 1rem;text-align:center;display:flex;flex-direction:column;align-items:center">
        <div style="width:4rem;height:4rem;border-radius:1rem;background:var(--surface-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:var(--text-light);margin-bottom:1rem">
          <i class="fa-solid ${escapeHtml(icon)}"></i>
        </div>
        <h3 style="font-size:.9375rem;font-weight:700;color:var(--text);margin:0 0 .375rem">${escapeHtml(title)}</h3>
        <p style="font-size:.8125rem;color:var(--text-muted);max-width:22rem;line-height:1.6;margin:0">${escapeHtml(description)}</p>
        ${btn}
      </div>`;
  },

  // ─── NAVBAR ─────────────────────────────────────────────────────────────
  async setupNavbar() {
    if (this._navbarReady) return;

    let header = document.getElementById('nb-header') || document.querySelector('header');
    if (header && (header.hasAttribute('hidden') || header.hidden)) {
      return; // Auth pages explicitly suppress the global navbar
    }
    if (!header) {
      header = document.createElement('header');
      header.id = 'nb-header';
      document.body.prepend(header);
    }

    const isInvoice = window.location.pathname.includes('invoice');
    header.className = 'nb-nav sticky top-0 z-50 ' + (isInvoice ? 'no-print' : '');

    const p = window.location.pathname.toLowerCase();
    const isHome = p === '/' || p.endsWith('/index.html') || p === '';
    const isAuctions = p.includes('auctions') || p.includes('auction-details');
    const isCategories = p.includes('categories');
    const isAbout = p.includes('about') && !p.includes('how-it-works');
    const isHowItWorks = p.includes('how-it-works');
    const isContact = p.includes('contact');
    const isFaq = p.includes('faq');
    const isTerms = p.includes('terms');
    const isPrivacy = p.includes('privacy');

    let user = null;
    let unreadCount = 0;
    let watchlistCount = 0;

    try {
      const hasToken = (typeof ApiClient !== 'undefined')
        && typeof ApiClient.getAccessToken === 'function'
        && ApiClient.getAccessToken();

      if (hasToken) {
        const res = await ApiClient.get('/auth/me');
        user = res && res.data;
        window.CURRENT_USER = user;

        // ✅ FIXED: Fetch notifications list to count unread accurately
        const [notifRes, watchRes] = await Promise.allSettled([
          ApiClient.get('/notifications'),
          ApiClient.get('/watchlist')
        ]);

        if (notifRes.status === 'fulfilled' && Array.isArray(notifRes.value?.data)) {
          // ✅ Filter only unread notifications
          unreadCount = notifRes.value.data.filter(n => {
            const isRead = n.is_read === true || n.is_read === 1 || n.is_read === '1';
            return !isRead;
          }).length;
        }

        if (watchRes.status === 'fulfilled' && Array.isArray(watchRes.value?.data)) {
          watchlistCount = watchRes.value.data.length;
        }
      }
    } catch (e) {
      console.warn('[Navbar] Auth fetch failed, continuing as guest:', e);
      user = null;
    }

    const searchQuery = new URLSearchParams(window.location.search).get('search') || '';

    // ─── ROLE-BASED URLS ──────────────────────────────────────────────────
    let roleName = 'Buyer';
    let dashUrl = '/buyer/dashboard.html';
    let ordersUrl = '/buyer/orders.html';
    let kycUrl = '/buyer/kyc.html';

    if (user) {
      if (user.role === 'admin') {
        roleName = 'Admin';
        dashUrl = '/admin/dashboard.html';
        ordersUrl = '/admin/orders.html';
        kycUrl = '/admin/kyc.html';
      } else if (user.role === 'seller') {
        roleName = 'Seller';
        dashUrl = '/seller/dashboard.html';
        ordersUrl = '/seller/orders.html';
        kycUrl = '/seller/kyc.html';
      }
    }

    const iconBtnClass = "flex items-center justify-center w-9 h-9 rounded-xl text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800/60 transition-all cursor-pointer";

    const searchBtn = `
      <button type="button" onclick="UI.toggleMobileSearch()" class="${iconBtnClass} lg:hidden" aria-label="Search auctions" title="Search">
        <i class="fa-solid fa-magnifying-glass text-sm"></i>
      </button>
    `;

    const themeBtn = `
      <button onclick="Theme.toggle()" class="${iconBtnClass} hidden sm:flex" aria-label="Toggle theme" data-theme-icon="1" title="Toggle Theme">
        <i class="fa-solid fa-circle-half-stroke text-sm"></i>
      </button>
    `;

    const showUserIcons = user && (user.role === 'buyer' || user.role === 'seller');

    const watchlistBtn = showUserIcons ? `
      <a href="/buyer/watchlist.html" class="relative ${iconBtnClass} hidden sm:flex" title="My Watchlist" aria-label="Watchlist">
        <i class="fa-regular fa-heart text-sm"></i>
        ${watchlistCount > 0 ? `<span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-rose-500 shadow-sm shadow-rose-500/50"></span>` : ''}
      </a>
    ` : '';

    const notifBtn = user ? `
      <a href="${user.role === 'admin' ? '/admin/notifications.html' : '/buyer/notifications.html'}" class="relative ${iconBtnClass}" title="Notifications" aria-label="Notifications">
        <i class="fa-regular fa-bell text-sm"></i>
        ${unreadCount > 0 ? `<span class="absolute -top-1 -right-1 flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[9px] font-bold shadow-sm shadow-rose-500/50 border-2 border-[#0b1120]">${unreadCount > 99 ? '99+' : unreadCount}</span>` : ''}
      </a>
    ` : '';

    // ─── AUTH AREA ────────────────────────────────────────────────────────
    let authArea = '';
    let name = '';
    let initials = 'U';

    if (user) {
      name = user.full_name || (user.email ? user.email.split('@')[0] : 'User');
      initials = name.split(' ').filter(Boolean).map(n => n[0]).slice(0, 2).join('').toUpperCase() || 'U';

      // ── ROLE-BASED DROPDOWN MENU ─────────────────────────────────────
      let menuItemsHtml = '';

      // Dashboard — everyone
      menuItemsHtml += `
        <a href="${escapeHtml(dashUrl)}" class="nb-dropdown-item">
          <i class="fa-solid fa-gauge" style="width:1rem"></i> Dashboard
        </a>`;

      // BUYER-only items
      if (user.role === 'buyer') {
        menuItemsHtml += `
          <a href="/buyer/bids.html" class="nb-dropdown-item">
            <i class="fa-solid fa-gavel" style="width:1rem"></i> My Bids
          </a>
          <a href="/buyer/watchlist.html" class="nb-dropdown-item">
            <i class="fa-regular fa-heart" style="width:1rem"></i> My Watchlist
          </a>
          <a href="/buyer/orders.html" class="nb-dropdown-item">
            <i class="fa-solid fa-box" style="width:1rem"></i> My Orders
          </a>`;
      }

      // SELLER-only items
      if (user.role === 'seller') {
        menuItemsHtml += `
          <a href="/seller/create-auction.html" class="nb-dropdown-item">
            <i class="fa-solid fa-plus" style="width:1rem"></i> Create Listing
          </a>
          <a href="/seller/orders.html" class="nb-dropdown-item">
            <i class="fa-solid fa-box" style="width:1rem"></i> My Orders
          </a>
          <a href="/seller/earnings.html" class="nb-dropdown-item">
            <i class="fa-solid fa-wallet" style="width:1rem"></i> Earnings
          </a>`;
      }

      // Notifications — buyer/seller only
      if (user.role === 'buyer' || user.role === 'seller') {
        menuItemsHtml += `
          <a href="/buyer/notifications.html" class="nb-dropdown-item">
            <i class="fa-solid fa-bell" style="width:1rem"></i> Notifications
          </a>`;
      }

      // Profile — everyone
      menuItemsHtml += `
        <a href="/buyer/profile.html" class="nb-dropdown-item">
          <i class="fa-solid fa-user-gear" style="width:1rem"></i> Profile &amp; Settings
        </a>`;

      // KYC & Protection — buyer & seller only
      if (user.role === 'buyer') {
        menuItemsHtml += `
          <a href="/buyer/protection.html" class="nb-dropdown-item">
            <i class="fa-solid fa-shield-halved" style="width:1rem"></i> Buyer Protection
          </a>
          <a href="/buyer/kyc.html" class="nb-dropdown-item">
            <i class="fa-solid fa-id-card" style="width:1rem"></i> Identity KYC
          </a>`;
      } else if (user.role === 'seller') {
        menuItemsHtml += `
          <a href="/seller/kyc.html" class="nb-dropdown-item">
            <i class="fa-solid fa-id-card" style="width:1rem"></i> Seller KYC
          </a>`;
      }

      // ADMIN-only items
      if (user.role === 'admin') {
        menuItemsHtml += `
          <a href="/admin/kyc.html" class="nb-dropdown-item">
            <i class="fa-solid fa-id-card-clip" style="width:1rem"></i> KYC Review Queue
          </a>
          <a href="/admin/auctions.html" class="nb-dropdown-item">
            <i class="fa-solid fa-gavel" style="width:1rem"></i> Moderate Auctions
          </a>
          <a href="/admin/users.html" class="nb-dropdown-item">
            <i class="fa-solid fa-users" style="width:1rem"></i> User Accounts
          </a>
          <a href="/admin/fraud-alerts.html" class="nb-dropdown-item">
            <i class="fa-solid fa-triangle-exclamation" style="width:1rem"></i> Fraud Alerts
          </a>
          <a href="/admin/disputes.html" class="nb-dropdown-item">
            <i class="fa-solid fa-scale-balanced" style="width:1rem"></i> Disputes
          </a>`;
      }

      authArea = `
        <div style="position:relative" class="nb-user-menu-wrapper ml-1 sm:ml-2 sm:pl-2.5 sm:border-l sm:border-slate-800">
          <button onclick="document.getElementById('nb-user-dropdown').classList.toggle('hidden')"
                  class="flex items-center gap-2 p-1 sm:px-2 sm:py-1 rounded-xl hover:bg-slate-800/40 transition-colors cursor-pointer"
                  aria-haspopup="true" aria-expanded="false" id="nb-user-dropdown-btn">
            <div style="width:2rem;height:2rem;border-radius:.625rem;background:linear-gradient(135deg,var(--brand-600),#818cf8);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0;box-shadow:0 4px 12px rgba(99,102,241,0.25)">
              ${escapeHtml(initials)}
            </div>
            <div class="hidden md:block" style="text-align:left;line-height:1.2">
              <div style="font-weight:700;font-size:.75rem;color:var(--text);max-width:7rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(name)}</div>
              <div style="font-size:.65rem;color:var(--text-muted);text-transform:capitalize">${escapeHtml(roleName)}</div>
            </div>
            <i class="fa-solid fa-chevron-down hidden md:block text-slate-500 ml-0.5" style="font-size:.625rem"></i>
          </button>

          <div id="nb-user-dropdown" class="hidden" style="position:absolute;right:0;top:calc(100% + .5rem);width:15rem;border-radius:1rem;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow-lg);z-index:100;overflow:hidden">
            <div style="padding:.875rem 1rem;border-bottom:1px solid var(--border)">
              <div style="font-size:.7rem;color:var(--text-muted)">Signed in as</div>
              <div style="font-weight:700;font-size:.8125rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(user.email || '')}</div>
              <span style="display:inline-block;margin-top:.375rem;padding:.15rem .5rem;border-radius:9999px;font-size:.625rem;font-weight:700;text-transform:uppercase;background:rgba(99,102,241,.1);color:var(--brand-400);border:1px solid rgba(99,102,241,.25)">
                ${escapeHtml(roleName)} ${user.role !== 'admin' ? `&bull; KYC ${escapeHtml(user.role === 'seller' ? (user.seller_kyc_status || 'unverified') : (user.kyc_status || 'unverified'))}` : ''}
              </span>
            </div>

            ${menuItemsHtml}

            <div style="height:1px;background:var(--border);margin:.25rem 0"></div>
            <button onclick="UI.logout()" class="nb-dropdown-item" style="width:100%;text-align:left;background:none;border:none;cursor:pointer;color:#ef4444">
              <i class="fa-solid fa-right-from-bracket" style="width:1rem"></i> Sign out
            </button>
          </div>
        </div>
      `;
    } else {
      authArea = `
        <div class="flex items-center gap-1.5 sm:gap-2 ml-1 sm:ml-2 sm:pl-2.5 sm:border-l sm:border-slate-800">
          <a href="/login.html" class="nb-btn nb-btn-ghost text-xs px-2.5 py-1.5 sm:px-3 sm:py-2">Sign In</a>
          <a href="/register.html" class="hidden sm:inline-flex nb-btn nb-btn-primary text-xs px-3.5 py-2">Register</a>
        </div>
      `;
    }

    // ─── HEADER MARKUP ────────────────────────────────────────────────────
    header.innerHTML = `
      <!-- Announcement Bar -->
      <div style="background:var(--surface-3);border-bottom:1px solid var(--border);padding:.35rem .75rem;font-size:.7rem;font-weight:600;color:var(--text-muted);display:flex;align-items:center;justify-content:space-between;line-height:1.4">
        <div style="display:flex;align-items:center;gap:.625rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <span style="display:inline-flex;align-items:center;gap:.35rem;color:var(--brand-500);font-weight:700">
            <i class="fa-solid fa-shield-halved"></i> NADRA KYC Verified
          </span>
          <span class="hidden sm:inline" style="color:var(--border-2)">&bull;</span>
          <span class="hidden sm:inline-flex" style="align-items:center;gap:.35rem;color:#10b981">
            <i class="fa-solid fa-lock"></i> 100% Escrow Protection
          </span>
        </div>
        <div style="display:flex;align-items:center;gap:.625rem;flex-shrink:0">
          <a href="/faq.html" style="color:var(--text-muted);text-decoration:none" class="hidden sm:inline hover:text-slate-900 dark:hover:text-slate-100">FAQ</a>
          <span class="hidden sm:inline" style="color:var(--border-2)">|</span>
          <a href="/about.html#trust" style="color:var(--text-muted);text-decoration:none" class="hidden sm:inline hover:text-slate-900 dark:hover:text-slate-100">Help &amp; Trust</a>
          <span class="hidden sm:inline" style="color:var(--border-2)">|</span>
          <a href="tel:+923231328558" style="color:var(--text);text-decoration:none;display:flex;align-items:center;gap:.3rem" class="hover:text-indigo-400">
            <i class="fa-solid fa-phone" style="font-size:.65rem;color:var(--brand-500)"></i>
            <span class="hidden xs:inline">0323 1328558</span>
          </a>
        </div>
      </div>

      <!-- Main Navbar Container -->
      <div style="max-width:80rem;margin:0 auto;padding:0 1rem;height:4rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem">
        <!-- Logo -->
        <a href="/" style="display:flex;align-items:center;gap:.5rem;text-decoration:none;flex-shrink:0" class="group">
          <div style="width:2.25rem;height:2.25rem;border-radius:.625rem;background:linear-gradient(135deg,var(--brand-600),#818cf8);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(99,102,241,.3);transition:transform var(--transition)" class="group-hover:scale-105">
            <i class="fa-solid fa-gavel" style="color:#fff;font-size:1rem"></i>
          </div>
          <div>
            <div style="font-size:1.15rem;font-weight:900;letter-spacing:-.02em;color:var(--text);line-height:1">
              Nexus<span style="color:var(--brand-500)">Bids</span>
            </div>
            <div style="font-size:.5rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted)">Pakistan</div>
          </div>
        </a>

        <!-- Desktop Global Search -->
        <form action="/auctions.html" method="GET" style="flex:1;max-width:24rem;margin:0 1rem" class="hidden lg:flex group" role="search">
          <div style="position:relative;width:100%">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:1rem;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.75rem"></i>
            <input type="text" name="search" id="nb-global-search" value="${escapeHtml(searchQuery)}"
                   placeholder="Search cars, phones, watches..." aria-label="Search auctions"
                   class="nb-input" style="padding-left:2.5rem;padding-top:.45rem;padding-bottom:.45rem;border-radius:2rem;background:var(--surface-2);font-size:.825rem;border:1px solid var(--border);transition:all .2s;width:100%"
                   onfocus="this.style.background='var(--surface)';this.style.borderColor='var(--brand-500)';"
                   onblur="this.style.background='var(--surface-2)';this.style.borderColor='var(--border)';">
          </div>
        </form>

        <!-- Desktop Nav Links -->
        <nav class="hidden lg:flex items-center gap-1 xl:gap-2 ml-auto" aria-label="Primary">
          <a href="/" class="nb-nav-link ${isHome ? 'active' : ''}">Home</a>
          <a href="/auctions.html" class="nb-nav-link ${isAuctions ? 'active' : ''}">Auctions</a>
          <a href="/categories.html" class="nb-nav-link ${isCategories ? 'active' : ''}">Categories</a>
          <a href="/how-it-works.html" class="nb-nav-link ${isHowItWorks ? 'active' : ''}">How It Works</a>
          <a href="/about.html" class="nb-nav-link ${isAbout ? 'active' : ''}">About</a>
          <a href="/contact.html" class="nb-nav-link ${isContact ? 'active' : ''}">Contact</a>
        </nav>

        <!-- Right Slot -->
        <div style="display:flex;align-items:center;gap:.25rem">
          ${searchBtn}
          ${themeBtn}
          ${watchlistBtn}
          ${notifBtn}
          ${authArea}

          <!-- Hamburger Button (Mobile) -->
          <button onclick="MobileMenu.toggle()" id="nb-hamburger-btn" class="${iconBtnClass} lg:hidden ml-1 border border-slate-700/60 dark:border-slate-800" aria-label="Open Navigation Menu" aria-expanded="false">
            <i class="fa-solid fa-bars text-base"></i>
          </button>
        </div>
      </div>

      <!-- Expandable Mobile Search Dropdown -->
      <div id="nb-mobile-search-dropdown" class="hidden lg:hidden" style="border-top:1px solid var(--border);background:var(--surface-2);padding:.625rem 1rem">
        <form action="/auctions.html" method="GET" style="display:flex;align-items:center;gap:.5rem" role="search">
          <div style="position:relative;flex:1">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:.875rem;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.75rem"></i>
            <input type="text" name="search" id="nb-mobile-search-input" value="${escapeHtml(searchQuery)}"
                   placeholder="Search cars, phones, watches..." aria-label="Search auctions"
                   class="nb-input" style="padding-left:2.25rem;border-radius:1rem;font-size:.8125rem">
          </div>
          <button type="submit" class="nb-btn nb-btn-primary" style="padding:.5rem .875rem;font-size:.75rem;border-radius:.75rem;flex-shrink:0">
            Search
          </button>
        </form>
      </div>
    `;

    // Wire search listeners
    const wireSearchSync = (inputEl) => {
      if (inputEl && p.includes('auctions') && typeof window.debounceSearch === 'function') {
        inputEl.addEventListener('input', (e) => {
          const localInput = document.getElementById('filter-search');
          if (localInput && localInput !== e.target) {
            localInput.value = e.target.value;
          }
          window.debounceSearch();
        });
      }
    };
    wireSearchSync(document.getElementById('nb-global-search'));
    wireSearchSync(document.getElementById('nb-mobile-search-input'));

    // Close user dropdown on outside click
    if (!this._outsideClickBound) {
      document.addEventListener('click', e => {
        const dd = document.getElementById('nb-user-dropdown');
        if (dd && dd.parentElement && !dd.parentElement.contains(e.target)) {
          dd.classList.add('hidden');
        }
      });
      this._outsideClickBound = true;
    }

    // Mobile drawer
    this.buildMobileDrawer(user, unreadCount, watchlistCount, name, initials);

    Theme._syncIcons();
    this._navbarReady = true;
  },

  // ─── MOBILE DRAWER (role-based) ─────────────────────────────────────────
  buildMobileDrawer(user, unreadCount = 0, watchlistCount = 0, userName = '', userInitials = 'U') {
    const oldMenu = document.getElementById('nb-mobile-menu');
    const oldOverlay = document.getElementById('nb-mobile-overlay');
    if (oldMenu) oldMenu.remove();
    if (oldOverlay) oldOverlay.remove();

    const p = window.location.pathname.toLowerCase();
    const searchQuery = new URLSearchParams(window.location.search).get('search') || '';

    const dashUrl = !user
      ? '/login.html'
      : user.role === 'admin'
        ? '/admin/dashboard.html'
        : user.role === 'seller'
          ? '/seller/dashboard.html'
          : '/buyer/dashboard.html';

    const overlay = document.createElement('div');
    overlay.id = 'nb-mobile-overlay';
    overlay.className = 'nb-mobile-overlay hidden';
    overlay.style.display = 'none';
    overlay.onclick = () => MobileMenu.close();
    document.body.appendChild(overlay);

    const kycStatus = user?.role === 'seller' ? (user.seller_kyc_status || 'unverified') : (user?.kyc_status || 'unverified');
    const isKycApproved = kycStatus === 'approved';

    // Role-based mobile drawer items
    let accountItemsHtml = '';

    if (user) {
      accountItemsHtml += `
        <div style="height:1px;background:var(--border);margin:.75rem 0"></div>
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);padding:0 1rem .25rem">
          Account (${escapeHtml(user.role)})
        </div>
        <a href="${escapeHtml(dashUrl)}" class="nb-nav-link" style="padding:.75rem 1rem">
          <i class="fa-solid fa-gauge" style="width:1.25rem;color:var(--brand-500)"></i> Dashboard
        </a>`;

      if (user.role === 'buyer') {
        accountItemsHtml += `
          <a href="/buyer/bids.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-gavel" style="width:1.25rem;color:var(--brand-500)"></i> My Bids
          </a>
          <a href="/buyer/watchlist.html" class="nb-nav-link" style="padding:.75rem 1rem;justify-content:space-between">
            <span style="display:flex;align-items:center;gap:.35rem"><i class="fa-regular fa-heart" style="width:1.25rem;color:var(--brand-500)"></i> Watchlist</span>
            ${watchlistCount > 0 ? `<span class="nb-badge nb-badge-danger">${watchlistCount}</span>` : ''}
          </a>
          <a href="/buyer/orders.html" class="nb-nav-link" style="padding:.75rem 1rem;justify-content:space-between">
            <span style="display:flex;align-items:center;gap:.35rem"><i class="fa-solid fa-box" style="width:1.25rem;color:var(--brand-500)"></i> My Orders</span>
            ${unreadCount > 0 ? `<span class="nb-badge nb-badge-brand">${unreadCount}</span>` : ''}
          </a>
          <a href="/buyer/protection.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-shield-halved" style="width:1.25rem;color:var(--brand-500)"></i> Buyer Protection
          </a>
          <a href="/buyer/kyc.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-id-card" style="width:1.25rem;color:var(--brand-500)"></i> Identity KYC
          </a>`;
      }

      if (user.role === 'seller') {
        accountItemsHtml += `
          <a href="/seller/create-auction.html" class="nb-nav-link" style="padding:.75rem 1rem;color:var(--brand-500);font-weight:700">
            <i class="fa-solid fa-plus" style="width:1.25rem"></i> Create Listing
          </a>
          <a href="/seller/auctions.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-gavel" style="width:1.25rem;color:var(--brand-500)"></i> My Listings
          </a>
          <a href="/seller/orders.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-box" style="width:1.25rem;color:var(--brand-500)"></i> My Orders
          </a>
          <a href="/seller/earnings.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-wallet" style="width:1.25rem;color:var(--brand-500)"></i> Earnings
          </a>
          <a href="/seller/kyc.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-id-card" style="width:1.25rem;color:var(--brand-500)"></i> Seller KYC
          </a>`;
      }

      if (user.role === 'admin') {
        accountItemsHtml += `
          <a href="/admin/kyc.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-id-card-clip" style="width:1.25rem;color:var(--brand-500)"></i> KYC Reviews
          </a>
          <a href="/admin/auctions.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-gavel" style="width:1.25rem;color:var(--brand-500)"></i> Auctions
          </a>
          <a href="/admin/users.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-users" style="width:1.25rem;color:var(--brand-500)"></i> Users
          </a>
          <a href="/admin/fraud-alerts.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-triangle-exclamation" style="width:1.25rem;color:var(--brand-500)"></i> Fraud Alerts
          </a>
          <a href="/admin/disputes.html" class="nb-nav-link" style="padding:.75rem 1rem">
            <i class="fa-solid fa-scale-balanced" style="width:1.25rem;color:var(--brand-500)"></i> Disputes
          </a>`;
      }
    }

    const drawer = document.createElement('div');
    drawer.id = 'nb-mobile-menu';
    drawer.className = 'nb-mobile-menu';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-label', 'Navigation menu');
    drawer.innerHTML = `
      <!-- Drawer Header -->
      <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <a href="/" style="display:flex;align-items:center;gap:.5rem;text-decoration:none">
          <div style="width:2rem;height:2rem;border-radius:.5rem;background:linear-gradient(135deg,var(--brand-600),#818cf8);display:flex;align-items:center;justify-content:center;color:#fff">
            <i class="fa-solid fa-gavel" style="font-size:.875rem"></i>
          </div>
          <span style="font-weight:900;font-size:1.1rem;color:var(--text)">Nexus<span style="color:var(--brand-500)">Bids</span></span>
        </a>
        <button onclick="MobileMenu.close()" class="nb-theme-btn" aria-label="Close menu" style="width:2rem;height:2rem;border-radius:.5rem">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <!-- User or Guest Profile Banner -->
      ${user ? `
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface-2)">
          <div style="display:flex;align-items:center;gap:.75rem">
            <div style="width:2.5rem;height:2.5rem;border-radius:.75rem;background:linear-gradient(135deg,var(--brand-600),#818cf8);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.875rem;flex-shrink:0;box-shadow:0 4px 12px rgba(99,102,241,.25)">
              ${escapeHtml(userInitials)}
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;font-size:.875rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(userName)}</div>
              <div style="font-size:.7rem;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(user.email || '')}</div>
              <div style="display:flex;align-items:center;gap:.35rem;margin-top:.25rem;flex-wrap:wrap">
                <span style="display:inline-block;padding:.1rem .45rem;border-radius:9999px;font-size:.6rem;font-weight:700;text-transform:uppercase;background:rgba(99,102,241,.12);color:var(--brand-400);border:1px solid rgba(99,102,241,.25)">
                  ${escapeHtml(user.role)}
                </span>
                <span style="display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .45rem;border-radius:9999px;font-size:.6rem;font-weight:600;${isKycApproved ? 'background:rgba(16,185,129,.12);color:#34d399;border:1px solid rgba(16,185,129,.25)' : 'background:rgba(245,158,11,.12);color:#fbbf24;border:1px solid rgba(245,158,11,.25)'}">
                  <i class="fa-solid ${isKycApproved ? 'fa-shield-check' : 'fa-clock'}" style="font-size:.55rem"></i> KYC ${escapeHtml(kycStatus)}
                </span>
              </div>
            </div>
          </div>
        </div>
      ` : `
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface-2)">
          <div style="font-weight:700;font-size:.875rem;color:var(--text);margin-bottom:.2rem">Welcome to NexusBids</div>
          <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.75rem">Pakistan's Verified Auction Platform</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <a href="/login.html" class="nb-btn nb-btn-primary" style="justify-content:center;padding:.5rem;font-size:.775rem">Sign In</a>
            <a href="/register.html" class="nb-btn nb-btn-secondary" style="justify-content:center;padding:.5rem;font-size:.775rem">Register</a>
          </div>
        </div>
      `}

      <!-- Quick Search inside Drawer -->
      <div style="padding:.875rem 1.25rem;border-bottom:1px solid var(--border)">
        <form action="/auctions.html" method="GET" style="position:relative;width:100%" role="search">
          <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:.875rem;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.75rem"></i>
          <input type="text" name="search" value="${escapeHtml(searchQuery)}" placeholder="Search auctions..." aria-label="Search auctions" class="nb-input" style="padding-left:2.25rem;border-radius:1rem;font-size:.8125rem">
        </form>
      </div>

      <!-- Navigation Links -->
      <div style="padding:.75rem;flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:.25rem">
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);padding:.25rem .75rem">
          Explore
        </div>
        <a href="/" class="nb-nav-link ${p === '/' || p.endsWith('/index.html') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-house" style="width:1.25rem;color:var(--brand-500)"></i> Home
        </a>
        <a href="/auctions.html" class="nb-nav-link ${p.includes('auctions') ? 'active' : ''}" style="padding:.65rem 1rem;justify-content:space-between">
          <span style="display:flex;align-items:center;gap:.35rem"><i class="fa-solid fa-gavel" style="width:1.25rem;color:var(--brand-500)"></i> Auctions</span>
          <span style="display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .4rem;border-radius:9999px;font-size:.6rem;font-weight:700;background:rgba(16,185,129,.15);color:#10b981">
            <span style="width:5px;height:5px;border-radius:50%;background:#10b981;animation:pulse 2s infinite"></span> LIVE
          </span>
        </a>
        <a href="/categories.html" class="nb-nav-link ${p.includes('categories') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-layer-group" style="width:1.25rem;color:var(--brand-500)"></i> Categories
        </a>
        <a href="/how-it-works.html" class="nb-nav-link ${p.includes('how-it-works') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-book-open" style="width:1.25rem;color:var(--brand-500)"></i> How It Works
        </a>
        <a href="/about.html" class="nb-nav-link ${p.includes('about') && !p.includes('how-it-works') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-circle-info" style="width:1.25rem;color:var(--brand-500)"></i> About Us
        </a>
        <a href="/contact.html" class="nb-nav-link ${p.includes('contact') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-envelope" style="width:1.25rem;color:var(--brand-500)"></i> Contact Us
        </a>
        <a href="/faq.html" class="nb-nav-link ${p.includes('faq') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-circle-question" style="width:1.25rem;color:var(--brand-500)"></i> FAQ
        </a>

        ${accountItemsHtml}

        <div style="height:1px;background:var(--border);margin:.75rem 0"></div>
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);padding:0 1rem .25rem">
          Trust &amp; Legal
        </div>
        <a href="/about.html#trust" class="nb-nav-link" style="padding:.65rem 1rem">
          <i class="fa-solid fa-shield-halved" style="width:1.25rem;color:#6366f1"></i> NADRA KYC System
        </a>
        <a href="/about.html#escrow" class="nb-nav-link" style="padding:.65rem 1rem">
          <i class="fa-solid fa-lock" style="width:1.25rem;color:#10b981"></i> 100% Escrow Protection
        </a>
        <a href="/terms.html" class="nb-nav-link ${p.includes('terms') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-file-contract" style="width:1.25rem;color:var(--brand-500)"></i> Terms &amp; Conditions
        </a>
        <a href="/privacy.html" class="nb-nav-link ${p.includes('privacy') ? 'active' : ''}" style="padding:.65rem 1rem">
          <i class="fa-solid fa-user-shield" style="width:1.25rem;color:var(--brand-500)"></i> Privacy Policy
        </a>
        <a href="tel:+923231328558" class="nb-nav-link" style="padding:.65rem 1rem">
          <i class="fa-solid fa-headset" style="width:1.25rem;color:var(--brand-500)"></i> Support: 0323 1328558
        </a>
      </div>

      <!-- Drawer Footer -->
      <div style="padding:1rem 1.25rem;border-top:1px solid var(--border);background:var(--surface-2);display:flex;flex-direction:column;gap:.75rem">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:.8rem;font-weight:600;color:var(--text-muted)">Display Theme</span>
          <button onclick="Theme.toggle()" class="nb-theme-btn" data-theme-icon="1" aria-label="Toggle theme">
            <i class="fa-solid fa-circle-half-stroke text-slate-400"></i>
          </button>
        </div>
        ${user ? `
          <button onclick="UI.logout()" class="nb-btn nb-btn-secondary" style="width:100%;justify-content:center;color:#ef4444;padding:.6rem">
            <i class="fa-solid fa-right-from-bracket mr-1"></i> Sign out
          </button>
        ` : `
          <a href="/login.html" class="nb-btn nb-btn-primary" style="width:100%;justify-content:center;padding:.6rem">Sign In</a>
        `}
      </div>
    `;

    // Automatically close drawer when clicking any link
    drawer.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => MobileMenu.close());
    });

    document.body.appendChild(drawer);
  },

  // ─── FOOTER ─────────────────────────────────────────────────────────────
  setupFooter() {
    if (this._footerReady) return;

    const path = window.location.pathname.toLowerCase();
    const AUTH_PATHS = ['login', 'register', 'forgot-password', 'reset-password', 'verify-email'];
    if (AUTH_PATHS.some(p => path.includes(p))) return;

    if (document.body.hasAttribute('data-no-footer')) return;

    let footer = document.getElementById('nb-footer');
    if (!footer) {
      footer = document.createElement('footer');
      footer.id = 'nb-footer';
      document.body.appendChild(footer);
    }

    const isInvoice = window.location.pathname.includes('invoice');
    footer.className = 'nb-footer mt-auto ' + (isInvoice ? 'no-print' : '');
    footer.setAttribute('role', 'contentinfo');

    footer.innerHTML = `
      <div style="max-width:80rem;margin:0 auto;padding:3.5rem 1.5rem 2rem">
        <h2 class="sr-only">Footer Navigation</h2>
        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:2.5rem; padding-bottom:2.5rem; border-bottom:1px solid var(--border)">
          <div style="flex: 2 1 300px; display:flex;flex-direction:column;gap:1rem">
            <a href="/" style="display:flex;align-items:center;gap:.625rem;text-decoration:none">
              <div style="width:2.25rem;height:2.25rem;border-radius:.625rem;background:linear-gradient(135deg,var(--brand-600),#818cf8);display:flex;align-items:center;justify-content:center;color:#fff">
                <i class="fa-solid fa-gavel"></i>
              </div>
              <span style="font-weight:900;font-size:1.25rem;color:var(--text)">Nexus<span style="color:var(--brand-500)">Bids</span></span>
            </a>
            <p style="font-size:.8125rem;color:var(--text-muted);line-height:1.6;max-width:22rem;margin:0">
              Pakistan's premier online auction marketplace. Transparent real-time bidding, NADRA KYC verification, and 100% bank-grade escrow protection.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;padding-top:.25rem">
              <span class="nb-badge nb-badge-success"><i class="fa-solid fa-shield-halved"></i> NADRA KYC Verified</span>
              <span class="nb-badge nb-badge-brand"><i class="fa-solid fa-lock"></i> 100% Escrow Protection</span>
            </div>
          </div>

          <div style="flex: 1 1 150px;">
            <h3 style="font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text);margin:0 0 1rem">Marketplace</h3>
            <div style="display:flex;flex-direction:column;gap:.625rem;font-size:.8125rem">
              <a href="/auctions.html" class="nb-footer-link">Live Auctions</a>
              <a href="/categories.html" class="nb-footer-link">Categories</a>
              <a href="/how-it-works.html" class="nb-footer-link">How It Works</a>
              <a href="/register.html?role=seller" class="nb-footer-link">Start Selling</a>
              <a href="/about.html" class="nb-footer-link">About NexusBids</a>
            </div>
          </div>

          <div style="flex: 1 1 150px;">
            <h3 style="font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text);margin:0 0 1rem">Trust &amp; Legal</h3>
            <div style="display:flex;flex-direction:column;gap:.625rem;font-size:.8125rem">
              <a href="/buyer/kyc.html" class="nb-footer-link">Buyer KYC Verification</a>
              <a href="/seller/kyc.html" class="nb-footer-link">Seller Compliance</a>
              <a href="/about.html#escrow" class="nb-footer-link">Escrow Trust Account</a>
              <a href="/terms.html" class="nb-footer-link">Terms &amp; Conditions</a>
              <a href="/privacy.html" class="nb-footer-link">Privacy Policy</a>
            </div>
          </div>

          <div style="flex: 1 1 150px;">
            <h3 style="font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text);margin:0 0 1rem">Help &amp; Support</h3>
            <div style="display:flex;flex-direction:column;gap:.625rem;font-size:.8125rem">
              <a href="/faq.html" class="nb-footer-link">Frequently Asked Questions</a>
              <a href="/contact.html" class="nb-footer-link">Contact Support</a>
              <span style="color:var(--text);display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-phone" style="color:var(--brand-500);font-size:.75rem"></i> 0323 1328558</span>
              <span style="color:var(--text-muted);display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-envelope" style="color:var(--brand-500);font-size:.75rem"></i> support@nexusbids.pk</span>
              <span style="color:var(--text-muted);display:flex;align-items:center;gap:.5rem"><i class="fa-solid fa-location-dot" style="color:var(--brand-500);font-size:.75rem"></i> Karachi &bull; Lahore &bull; ISB</span>
              <a href="javascript:void(0)" onclick="window.openAiChat && window.openAiChat()" style="color:var(--brand-500);text-decoration:none;font-weight:700;margin-top:.25rem;display:inline-flex;align-items:center;gap:.35rem">
                <i class="fa-solid fa-robot"></i> Ask AI Assistant &rarr;
              </a>
            </div>
          </div>
        </div>

        <div style="padding-top:1.5rem;display:flex;flex-wrap:wrap;gap:1rem;font-size:.75rem;color:var(--text-muted);justify-content:space-between;align-items:center;">
          <p style="margin:0">&copy; ${new Date().getFullYear()} NexusBids Pakistan. All rights reserved. ETO 2002 Compliant Marketplace.</p>
          <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
            <a href="/terms.html" style="color:var(--text-muted);text-decoration:none" class="hover:underline hover:text-indigo-400">Terms</a>
            <span style="color:var(--border-2)">&bull;</span>
            <a href="/privacy.html" style="color:var(--text-muted);text-decoration:none" class="hover:underline hover:text-indigo-400">Privacy</a>
            <span style="color:var(--border-2)">&bull;</span>
            <a href="/faq.html" style="color:var(--text-muted);text-decoration:none" class="hover:underline hover:text-indigo-400">FAQ</a>
            <span style="color:var(--border-2)">&bull;</span>
            <a href="/contact.html" style="color:var(--text-muted);text-decoration:none" class="hover:underline hover:text-indigo-400">Contact</a>
            <span style="color:var(--border-2)">&bull;</span>
            <span style="display:inline-flex;align-items:center;gap:.35rem;color:#10b981;font-weight:600">
              <span style="width:.5rem;height:.5rem;border-radius:9999px;background:#10b981"></span> All Systems Operational
            </span>
          </div>
        </div>
      </div>
    `;

    this._footerReady = true;
  },

  async logout() {
    const ok = await this.confirm({
      title: 'Sign Out',
      message: 'End your current session?',
      confirmText: 'Sign Out',
      type: 'warning'
    });
    if (!ok) return;
    try { await ApiClient.post('/auth/logout'); } catch { }
    ApiClient.setAccessToken(null);
    window.location.href = '/login.html';
  },

  isAuthenticated() {
    return !!(typeof ApiClient !== 'undefined'
      && typeof ApiClient.getAccessToken === 'function'
      && ApiClient.getAccessToken());
  }
};

window.UI = UI;

// ─── Injected shared styles ───────────────────────────────────────────────────
(function injectSharedStyles() {
  if (document.getElementById('nb-ui-inline-styles')) return;
  const _style = document.createElement('style');
  _style.id = 'nb-ui-inline-styles';
  _style.textContent = `
    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
    .nb-dropdown-item {
      display: flex;
      align-items: center;
      gap: .625rem;
      padding: .625rem 1rem;
      font-size: .8rem;
      font-weight: 600;
      color: var(--text-muted);
      text-decoration: none;
      transition: all var(--transition);
      cursor: pointer;
    }
    .nb-dropdown-item:hover {
      color: var(--text);
      background: var(--surface-2);
    }
    .nb-footer-link {
      color: var(--text-muted);
      text-decoration: none;
      transition: color var(--transition);
    }
    .nb-footer-link:hover { color: var(--text); }
    .nb-badge-success { background: #d1fae5; color: #065f46; border: 1px solid rgba(16,185,129,.35); }
    .nb-badge-brand   { background: #e0e7ff; color: #3730a3; border: 1px solid rgba(99,102,241,.35); }
    html.dark .nb-badge-success { background: rgba(16,185,129,.15); color: #34d399; border-color: rgba(16,185,129,.3); }
    html.dark .nb-badge-brand   { background: rgba(99,102,241,.18); color: #a5b4fc; border-color: rgba(99,102,241,.35); }
  `;
  document.head.appendChild(_style);
})();

// ─── AUTO-INIT ────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  try { Theme.init(); } catch (err) { console.error('[Theme] init failed:', err); }
  setTimeout(() => {
    try { UI.syncServerTime(); } catch (err) { console.warn('[Time] sync failed:', err); }
  }, 250);

  UI.setupNavbar().catch(err => {
    console.error('[Navbar] Failed to render:', err);
    const header = document.getElementById('nb-header') || document.querySelector('header');
    if (header && !header.innerHTML.trim()) {
      header.innerHTML = `
        <div style="padding:1rem;text-align:center;background:var(--surface-3);border-bottom:1px solid var(--border)">
          <a href="/" style="font-weight:900;font-size:1.125rem;color:var(--text);text-decoration:none">
            Nexus<span style="color:var(--brand-500)">Bids</span>
          </a>
        </div>`;
    }
  });

  try { UI.setupFooter(); }
  catch (err) { console.error('[Footer] Failed to render:', err); }
});