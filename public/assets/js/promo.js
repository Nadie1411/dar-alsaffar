/* ===========================================================================
   Promotions, pop-up and add-to-home-screen
   ---------------------------------------------------------------------------
   None of this decides whether an offer applies — the cart API does that and
   this file only reacts to what it reported. Dismissals live in localStorage,
   which is per-visitor and may be unavailable, so every read is guarded.
   =========================================================================== */

(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const store = {
    get(key) {
      try { return localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
      try { localStorage.setItem(key, value); } catch { /* private mode */ }
    },
  };

  const snoozedUntil = (key) => {
    const raw = store.get(key);
    return raw ? Number(raw) || 0 : 0;
  };

  const snooze = (key, days) =>
    store.set(key, String(Date.now() + days * 24 * 60 * 60 * 1000));

  // ------------------------------------------------------ gift celebration

  function celebrate(el) {
    if (reduceMotion || el.dataset.celebrate !== 'on') return;

    // Fire once per unlock, not on every render of the same state.
    const key = `oz-gift-celebrated:${el.dataset.giftKey || ''}`;
    if (!el.dataset.giftKey || store.get(key)) return;
    store.set(key, '1');

    const colours = ['#c2a36a', '#00603a', '#f3e8c8', '#e0cfa8'];

    for (let i = 0; i < 14; i += 1) {
      const spark = document.createElement('span');
      spark.className = 'celebrate__burst';
      const angle = (Math.PI * 2 * i) / 14;
      const distance = 40 + Math.random() * 46;
      spark.style.setProperty('--dx', `${Math.cos(angle) * distance}px`);
      spark.style.setProperty('--dy', `${Math.sin(angle) * distance}px`);
      spark.style.background = colours[i % colours.length];
      spark.style.animationDelay = `${Math.random() * 90}ms`;
      el.appendChild(spark);
    }

    el.classList.add('is-firing');
    setTimeout(() => {
      el.classList.remove('is-firing');
      $$('.celebrate__burst', el).forEach((s) => s.remove());
    }, 1100);
  }

  function scanGifts(root = document) {
    $$('[data-celebrate]', root).forEach(celebrate);
  }

  scanGifts();

  // The cart drawer re-renders its contents, so watch for a gift arriving.
  const drawerBody = $('[data-cart-body]');
  if (drawerBody && 'MutationObserver' in window) {
    new MutationObserver(() => scanGifts(drawerBody)).observe(drawerBody, { childList: true, subtree: true });
  }

  // ------------------------------------------------------------ copy a code

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch { /* no clipboard API (plain http, older iOS): fall back below */ }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;inset-block-start:0;opacity:0';
    document.body.appendChild(field);
    field.select();
    field.setSelectionRange(0, text.length);

    let copied = false;
    try { copied = document.execCommand('copy'); } catch { /* ignore */ }
    field.remove();

    return copied;
  }

  document.addEventListener('click', async (e) => {
    const chip = e.target.closest('[data-copy-code]');
    if (!chip) return;

    const i18n = window.Store?.i18n || {};

    if (await copyText(chip.dataset.copyCode)) {
      window.StoreUI?.toast(i18n.codeCopied || '');
      chip.classList.add('is-copied');
      setTimeout(() => chip.classList.remove('is-copied'), 1800);
    } else {
      window.StoreUI?.toast(i18n.error || '', 'error');
    }
  });

  // ---------------------------------------------------------------- pop-up

  const popup = $('[data-promo-popup]');
  if (popup) {
    popup.hidden = false;   // visibility takes over from here
    const key = `oz-promo:${popup.dataset.key}`;
    const days = Number(popup.dataset.snooze) || 7;
    const delay = (Number(popup.dataset.delay) || 6) * 1000;

    const close = () => {
      popup.removeAttribute('data-shown');
      snooze(key, days);
      document.body.removeAttribute('data-locked');
    };

    const open = () => {
      popup.setAttribute('data-shown', '');
      // Seen is seen: remembered as it appears, not only when it is closed, so
      // following its link or navigating away does not bring it back on the next page.
      snooze(key, days);
      const first = popup.querySelector('a, button');
      setTimeout(() => first && first.focus(), 280);
    };

    if (Date.now() > snoozedUntil(key)) {
      setTimeout(open, delay);
    }

    $$('[data-promo-close]', popup).forEach((b) => b.addEventListener('click', close));
    popup.addEventListener('click', (e) => { if (e.target === popup) close(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && popup.hasAttribute('data-shown')) close();
    });
  }

  // -------------------------------------------- add to home screen (PWA)

  const panel = $('[data-install-prompt]');
  if (panel) panel.hidden = false;   // visibility takes over from here

  // Chrome/Edge/Android hand us a deferred prompt we can replay on a tap.
  let deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (panel) $('[data-install-native]', panel).hidden = false;
  });

  if (panel) {
    const key = 'oz-install';
    const days = Number(panel.dataset.snooze) || 14;
    const delay = (Number(panel.dataset.delay) || 12) * 1000;

    const isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;

    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    const close = () => {
      panel.removeAttribute('data-shown');
      snooze(key, days);
      document.body.removeAttribute('data-locked');
      const scrim = $('[data-scrim]');
      if (scrim) scrim.removeAttribute('data-shown');
    };

    const open = () => {
      // Nothing to offer: already installed, or a browser with neither a
      // prompt nor the iOS Share route.
      if (isStandalone) return;
      if (!deferredPrompt && !isIos) return;

      $('[data-install-ios]', panel).hidden = !(isIos && !deferredPrompt);
      $('[data-install-native]', panel).hidden = !deferredPrompt;

      panel.setAttribute('data-shown', '');

      const scrim = $('[data-scrim]');
      if (scrim) scrim.setAttribute('data-shown', '');
    };

    if (Date.now() > snoozedUntil(key)) {
      setTimeout(open, delay);
    }

    $('[data-install-later]', panel)?.addEventListener('click', close);

    $('[data-install-go]', panel)?.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      deferredPrompt = null;
      if (outcome === 'accepted') {
        window.StoreUI?.toast(window.Store?.i18n?.installed || '');
      }
      close();
    });

    window.addEventListener('appinstalled', () => {
      snooze(key, 3650);
      window.StoreUI?.toast(window.Store?.i18n?.installed || '');
    });
  }

  // ------------------------------------------------------- service worker

  // Registered only over https (or localhost); the worker caches static
  // assets and deliberately never caches pages, cart or API responses.
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => { /* non-fatal */ });
    });
  }
})();
