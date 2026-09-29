/* ===========================================================================
   Service worker — Dar Al Saffar
   ---------------------------------------------------------------------------
   Deliberately conservative. It caches the shell (fonts, CSS, JS, logo) so a
   returning visitor paints instantly, and it NEVER caches a page, an API
   response, the cart or anything behind a session — prices, stock and totals
   must always come from the network, or a shopper could be shown a figure the
   checkout will not honour.
   =========================================================================== */

// Bumping this forces every returning visitor's shell cache to be thrown
// away and rebuilt on the next activation (see the `activate` handler
// below) — needed once, here, because Asset::url()'s version stamp was
// stuck on the same value across every deploy until just now, so anyone
// who had already loaded the site was holding a cache keyed by URLs that
// were never going to change on their own. Going forward this should not
// need bumping for an ordinary deploy — a real content change gets a real
// new ?v= now, which is a cache miss on its own.
const VERSION = 'das-v3';
const SHELL = `${VERSION}-shell`;

// Only assets that never change name are pre-cached. CSS and JS are requested
// with a ?v= stamp, so a deploy fetches a fresh URL rather than a stale copy.
const SHELL_ASSETS = [
  '/assets/brand/logo-mark-emerald.png',
  '/assets/brand/logo-mark-cream.png',
  '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL)
      // One bad URL must not fail the whole install.
      .then((cache) => Promise.allSettled(SHELL_ASSETS.map((u) => cache.add(u))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Same-origin only, and never a document or anything session-shaped.
  if (url.origin !== self.location.origin) return;
  if (request.mode === 'navigate') return;
  if (request.headers.get('accept')?.includes('text/html')) return;

  const isShellAsset = /^\/(assets|favicon|apple-touch-icon|manifest)/.test(url.pathname);
  if (!isShellAsset) return;

  // Cache first for static assets; they are content-addressed by deploy.
  event.respondWith(
    caches.match(request).then((hit) => {
      if (hit) return hit;

      return fetch(request).then((response) => {
        if (response.ok && response.type === 'basic') {
          const copy = response.clone();
          caches.open(SHELL).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => hit);
    })
  );
});
