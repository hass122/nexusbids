/**
 * NexusBids — Buyer Page Role Guard
 * Agar seller/admin buyer page khole, to equivalent seller page pe redirect kar do.
 * Load this script in <head> of EVERY buyer/*.html page.
 */
(function () {
    'use strict';
    try {
        var u = JSON.parse(
            localStorage.getItem('nexusbids_user') ||
            sessionStorage.getItem('nexusbids_user') ||
            '{}'
        );
        var role = String(u.role || u.user_type || '').toLowerCase();
        if (role !== 'seller' && role !== 'admin') return; // buyer — allow

        // Map: buyer page → seller equivalent
        var map = {
            '/buyer/profile.html': '/seller/profile.html',
            '/buyer/notifications.html': '/seller/notifications.html',
            '/buyer/dashboard.html': '/seller/dashboard.html',
            '/buyer/orders.html': '/seller/orders.html',
            '/buyer/messages.html': '/seller/messages.html',
            '/buyer/wishlist.html': '/seller/auctions.html',
            '/buyer/watchlist.html': '/seller/auctions.html',
            '/buyer/bids.html': '/seller/bids.html',
            '/buyer/kyc.html': '/seller/kyc.html',
            '/buyer/settings.html': '/seller/security.html',
            '/buyer/security.html': '/seller/security.html'
        };

        var path = window.location.pathname.toLowerCase();
        var target = map[path];

        if (target) {
            window.location.replace(target);
        } else {
            // Unmapped buyer page — safe fallback
            window.location.replace('/seller/dashboard.html');
        }
    } catch (e) { /* silent */ }
})();