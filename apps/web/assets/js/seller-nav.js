/**
 * NexusBids Seller Workspace Navigation Component
 * Includes Universal Auth Guard + Bulletproof buyer/profile link fix
 */
(function () {
  'use strict';

  /* ============================================================
     ✅ UNIVERSAL SELLER AUTH GUARD
     ============================================================ */
  function enforceSellerAuth() {
    if (typeof window.ApiClient === 'undefined') {
      if (!enforceSellerAuth._retries) enforceSellerAuth._retries = 0;
      if (enforceSellerAuth._retries++ < 30) {
        setTimeout(enforceSellerAuth, 100);
        return;
      }
      console.warn('[Auth Guard] ApiClient not available — using localStorage fallback');
      var token =
        localStorage.getItem('nexusbids_token') ||
        sessionStorage.getItem('nexusbids_token') ||
        localStorage.getItem('nexusbids_access_token') ||
        sessionStorage.getItem('nexusbids_access_token');
      var userRaw = localStorage.getItem('nexusbids_user') || sessionStorage.getItem('nexusbids_user') || '{}';
      var user = {};
      try { user = JSON.parse(userRaw) || {}; } catch (e) { user = {}; }
      var role = String(user.role || user.user_type || '').toLowerCase().trim();
      var isSeller = role === 'seller' || role === 'admin';
      if (!token || !isSeller) {
        var redirect = encodeURIComponent(location.pathname + location.search);
        window.location.replace('/auth/login.html?redirect=' + redirect);
      }
      return;
    }

    enforceSellerAuth._retries = 0;

    var token, role;
    try {
      token = typeof window.ApiClient.getAccessToken === 'function'
        ? window.ApiClient.getAccessToken()
        : (localStorage.getItem('nexusbids_token') || sessionStorage.getItem('nexusbids_token'));
      role = typeof window.ApiClient.getUserRole === 'function'
        ? window.ApiClient.getUserRole()
        : '';
    } catch (e) {
      token = null;
      role = '';
    }

    if (!role) {
      var u = {};
      try {
        u = JSON.parse(localStorage.getItem('nexusbids_user') || sessionStorage.getItem('nexusbids_user') || '{}');
      } catch (e) { u = {}; }
      role = String(u.role || u.user_type || '').toLowerCase().trim();
    }

    var isSeller = role === 'seller' || role === 'admin';

    if (!token || !isSeller) {
      var redirectUrl = encodeURIComponent(location.pathname + location.search);
      window.location.replace('/auth/login.html?redirect=' + redirectUrl);
    }
  }

  /* ============================================================
     ✅ BULLETPROOF BUYER → SELLER PROFILE LINK FIX
     - Capture-phase click interceptor (wins over any handler)
     - MutationObserver (catches async-rendered links)
     - Periodic attribute rewriting (backup)
     ============================================================ */
  var SELLER_PROFILE_URL = '/seller/profile.html';
  var _buyerFixInstalled = false;

  function installBuyerProfileFix() {
    if (_buyerFixInstalled) return;
    _buyerFixInstalled = true;

    function isBuyerProfileUrl(url) {
      return typeof url === 'string' && url.indexOf('/buyer/profile') !== -1;
    }

    /* (A) Capture-phase click interceptor — beats everything */
    function clickInterceptor(e) {
      var el = e.target;
      while (el && el !== document) {
        // Anchor tag
        if (el.tagName === 'A') {
          var href = el.getAttribute('href') || '';
          if (isBuyerProfileUrl(href) || isBuyerProfileUrl(el.href || '')) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            window.location.href = SELLER_PROFILE_URL;
            return false;
          }
        }
        // Any element with onclick referencing buyer profile
        if (el.getAttribute) {
          var oc = el.getAttribute('onclick') || '';
          if (isBuyerProfileUrl(oc)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            window.location.href = SELLER_PROFILE_URL;
            return false;
          }
        }
        el = el.parentElement;
      }
    }
    document.addEventListener('click', clickInterceptor, true);

    /* (B) Rewrite anchor href attributes */
    function rewriteLinks() {
      try {
        // Rewrite <a href="/buyer/profile*">
        var anchors = document.querySelectorAll('a[href*="/buyer/profile"]');
        for (var i = 0; i < anchors.length; i++) {
          anchors[i].setAttribute('href', SELLER_PROFILE_URL);
        }
        // Rewrite inline onclick
        var onclickEls = document.querySelectorAll('[onclick*="/buyer/profile"]');
        for (var j = 0; j < onclickEls.length; j++) {
          var oc = onclickEls[j].getAttribute('onclick') || '';
          onclickEls[j].setAttribute(
            'onclick',
            oc.replace(/\/buyer\/profile(\.html)?/g, SELLER_PROFILE_URL)
          );
        }
      } catch (err) { /* silent */ }
    }

    /* (C) MutationObserver — catches async-rendered header menus */
    if (typeof MutationObserver !== 'undefined' && document.body) {
      try {
        var observer = new MutationObserver(function () {
          rewriteLinks();
        });
        observer.observe(document.body, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ['href', 'onclick']
        });
      } catch (err) { /* silent */ }
    }

    /* (D) Periodic retries for slow-rendering headers */
    rewriteLinks();
    setTimeout(rewriteLinks, 100);
    setTimeout(rewriteLinks, 300);
    setTimeout(rewriteLinks, 800);
    setTimeout(rewriteLinks, 1500);
    setTimeout(rewriteLinks, 3000);

    /* (E) Expose for debugging */
    window.__nbFixBuyerProfile = rewriteLinks;
    window.__nbSellerProfileUrl = SELLER_PROFILE_URL;
  }

  /* ============================================================
     NAVIGATION RENDERING
     ============================================================ */
  var NAV_ITEMS = [
    { href: '/seller/dashboard.html', icon: 'fa-gauge', label: 'Dashboard', group: 'core' },
    { href: '/seller/auctions.html', icon: 'fa-gavel', label: 'My Auctions', group: 'core' },
    { href: '/seller/create-auction.html', icon: 'fa-plus-circle', label: 'Create Listing', group: 'core', highlight: true },
    { href: '/seller/bids.html', icon: 'fa-hand-holding-dollar', label: 'Bid Activity', group: 'auctions' },
    { href: '/seller/analytics.html', icon: 'fa-chart-pie', label: 'Analytics', group: 'auctions' },
    { href: '/seller/orders.html', icon: 'fa-box-open', label: 'Orders', group: 'sales' },
    { href: '/seller/shipping.html', icon: 'fa-truck-fast', label: 'Shipping', group: 'sales' },
    { href: '/seller/disputes.html', icon: 'fa-triangle-exclamation', label: 'Disputes', group: 'sales' },
    { href: '/seller/earnings.html', icon: 'fa-coins', label: 'Earnings', group: 'finance' },
    { href: '/seller/payouts.html', icon: 'fa-money-bill-transfer', label: 'Payouts', group: 'finance' },
    { href: '/seller/transactions.html', icon: 'fa-receipt', label: 'Ledger', group: 'finance' },
    { href: '/seller/reviews.html', icon: 'fa-star', label: 'Reviews', group: 'comms' },
    { href: '/seller/messages.html', icon: 'fa-comments', label: 'Messages', group: 'comms' },
    { href: '/seller/notifications.html', icon: 'fa-bell', label: 'Notifications', group: 'comms' },
    { href: '/seller/profile.html', icon: 'fa-store', label: 'Store Profile', group: 'settings' },
    { href: '/seller/security.html', icon: 'fa-shield-halved', label: 'Security & 2FA', group: 'settings' },
    { href: '/seller/kyc.html', icon: 'fa-id-card', label: 'Seller KYC', group: 'settings' }
  ];

  function renderSellerNav(mountId) {
    mountId = mountId || 'seller-subnav';
    var el = document.getElementById(mountId);
    if (!el) return;

    var currentPath = window.location.pathname.toLowerCase();

    el.innerHTML =
      '<div class="seller-nav-shell" style="background:var(--s-surface, #0d1220);border:1px solid var(--s-border, #1e2a40);border-radius:16px;padding:8px 12px;margin-bottom:24px;box-shadow:0 4px 20px rgba(0,0,0,0.15)">' +
      '<div style="display:flex;align-items:center;gap:6px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch" class="no-scrollbar">' +
      NAV_ITEMS.map(function (item) {
        var isActive = currentPath.endsWith(item.href.toLowerCase()) ||
          (item.href === '/seller/auctions.html' && (currentPath.indexOf('edit-auction') !== -1 || currentPath.indexOf('auction-details') !== -1)) ||
          (item.href === '/seller/orders.html' && currentPath.indexOf('order-details') !== -1);
        var activeStyle = isActive
          ? 'background:linear-gradient(135deg, rgba(99,102,241,0.25), rgba(79,70,229,0.35));color:#ffffff;border:1px solid rgba(99,102,241,0.5);font-weight:800;box-shadow:0 4px 12px rgba(99,102,241,0.25);'
          : 'background:transparent;color:var(--s-text-muted, #9aa6bd);border:1px solid transparent;font-weight:600;';
        var highlightStyle = item.highlight && !isActive
          ? 'background:rgba(99,102,241,0.1);color:#a5b4fc;border:1px solid rgba(99,102,241,0.25);'
          : '';

        return '<a href="' + item.href + '" ' +
          'style="display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:10px;font-size:0.75rem;text-decoration:none;white-space:nowrap;transition:all 0.2s;' + (activeStyle || highlightStyle) + '" ' +
          'class="seller-nav-pill hover:bg-slate-800/60 hover:text-white">' +
          '<i class="fa-solid ' + item.icon + '" style="font-size:0.72rem;color:' + (isActive ? '#a5b4fc' : 'inherit') + '"></i>' +
          '<span>' + item.label + '</span>' +
          '</a>';
      }).join('') +
      '</div></div>';
  }

  window.SellerNav = {
    init: renderSellerNav,
    items: NAV_ITEMS,
    fixBuyerProfileLinks: function () { try { window.__nbFixBuyerProfile && window.__nbFixBuyerProfile(); } catch (e) { } }
  };

  /* ============================================================
     ✅ BOOT
     ============================================================ */
  function boot() {
    enforceSellerAuth();
    renderSellerNav('seller-subnav');
    installBuyerProfileFix();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // ✅ Extra safety — install fix at script parse time too (before DOM ready)
  // This catches clicks that might happen before DOMContentLoaded fires.
  if (document.body) {
    installBuyerProfileFix();
  } else {
    // Body not ready yet — attach a very early listener
    document.addEventListener('DOMContentLoaded', installBuyerProfileFix, { once: true });
  }
})();