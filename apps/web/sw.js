// NexusBids Service Worker v4.0
// Only handles same-origin static assets; skips cross-origin CDNs and APIs

const CACHE_NAME = 'nexusbids-v4';
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/auctions.html',
  '/categories.html',
  '/about.html',
  '/how-it-works.html',
  '/contact.html',
  '/faq.html',
  '/terms.html',
  '/privacy.html',
  '/assets/css/design-system.css',
  '/assets/js/api-client.js',
  '/assets/js/ui.js',
];

// Install — cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch(() => {});
    })
  );
  self.skipWaiting();
});

// Activate — clean up old caches immediately
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

// Fetch — only handle same-origin GET requests
self.addEventListener('fetch', (event) => {
  const { request } = event;

  // 1. Only handle GET
  if (request.method !== 'GET') return;

  // 2. NEVER intercept external CDNs (tailwindcss, fontawesome, google fonts, etc.)
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // 3. NEVER intercept API calls
  if (url.pathname.startsWith('/api/')) return;

  // 4. Network-first with cache fallback for same-origin assets
  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response && response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone)).catch(() => {});
        }
        return response;
      })
      .catch(async () => {
        const cached = await caches.match(request);
        if (cached) return cached;
        if (request.mode === 'navigate') {
          return new Response(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Offline</title></head><body style="font-family:sans-serif;text-align:center;padding:3rem"><h1>NexusBids — Offline</h1><p>Please check your internet connection.</p></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        }
        return new Response('Network error', { status: 408, headers: { 'Content-Type': 'text/plain' } });
      })
  );
});
