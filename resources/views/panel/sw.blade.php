/* Service worker for the admin panel. It does one thing: when the shop signals a
   new order, ask the panel what it was and show it. It caches nothing. */

const FALLBACK_TITLE = @json(config('brand.name.ar').' · '.config('brand.name.en'));
const FALLBACK_BODY = @json(__('panel.push.fallback'));
const PANEL = '/panel';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let body = FALLBACK_BODY;

    try {
      // Signed in as the member of staff, with their own cookie: what an order
      // says never travels through the push service.
      const response = await fetch(PANEL + '/orders/feed', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });

      if (response.ok) {
        const data = await response.json();
        if (data.message) { body = data.message; }
      }
    } catch (error) { /* offline or signed out: the plain message will do */ }

    await self.registration.showNotification(FALLBACK_TITLE, {
      body,
      tag: 'new-order',
      renotify: true,
      icon: '/assets/brand/icon-192-maskable.png',
      badge: '/assets/brand/icon-192-maskable.png',
      data: { url: PANEL + '/orders' },
    });
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || PANEL;

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    for (const client of windows) {
      if (new URL(client.url).pathname.startsWith(PANEL) && 'focus' in client) {
        await client.focus();
        if ('navigate' in client) { await client.navigate(target); }
        return;
      }
    }

    await self.clients.openWindow(target);
  })());
});
