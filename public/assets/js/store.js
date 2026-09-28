/* ===========================================================================
   Dar Al Saffar storefront behaviour
   ---------------------------------------------------------------------------
   Vanilla, no framework, no build step. Every interaction degrades: with
   JavaScript off the links and forms still work, because nothing here is the
   only way to reach a page.
   =========================================================================== */

(() => {
  'use strict';

  const cfg = window.Store || {};
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // ------------------------------------------------------------------ toasts

  function toast(message, variant) {
    const stack = $('[data-toasts]');
    if (!stack) return;

    const el = document.createElement('div');
    el.className = 'toast' + (variant === 'error' ? ' toast--error' : '');
    el.setAttribute('role', variant === 'error' ? 'alert' : 'status');
    el.textContent = message;
    stack.appendChild(el);

    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transition = 'opacity 240ms';
      setTimeout(() => el.remove(), 260);
    }, 3200);
  }

  // ----------------------------------------------------------------- fetch

  async function api(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': cfg.csrf,
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      },
      ...options,
    });

    const type = res.headers.get('content-type') || '';
    const payload = type.includes('application/json') ? await res.json() : await res.text();

    if (!res.ok) throw Object.assign(new Error('request failed'), { payload, status: res.status });
    return payload;
  }

  // ------------------------------------------------------- panels & focus

  let lastFocused = null;
  let openPanel = null;

  const scrim = $('[data-scrim]');

  // Panels render with `hidden` so they stay away before this script runs.
  // Once it has, visibility takes over — toggling `display` would break every
  // open/close transition.
  $$('[data-panel]').forEach((panel) => { panel.hidden = false; });
  if (scrim) scrim.hidden = false;

  function trapFocus(panel, event) {
    const focusables = $$(
      'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
      panel
    ).filter((el) => el.offsetParent !== null);

    if (!focusables.length) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function open(name) {
    const panel = $(`[data-panel="${name}"]`);
    if (!panel || openPanel === panel) return;

    if (openPanel) close({ restore: false });

    lastFocused = document.activeElement;

    // Force a reflow so the transition has a start state to animate from.
    // requestAnimationFrame would be cleaner, but it never fires while the
    // page is hidden — which would leave the panel stuck off-screen.
    panel.setAttribute('data-shown', '');

    if (scrim && name !== 'search') {
      scrim.setAttribute('data-shown', '');
    }

    document.body.setAttribute('data-locked', '');
    openPanel = panel;

    $$('[data-open]').forEach((btn) => {
      if (btn.dataset.open === name) btn.setAttribute('aria-expanded', 'true');
    });

    const focusTarget = panel.querySelector('[data-autofocus], input, button');
    setTimeout(() => focusTarget && focusTarget.focus(), reduceMotion ? 0 : 120);

    if (name === 'cart') loadCart();
    if (name === 'search') renderRecent();
  }

  function close({ restore = true } = {}) {
    if (!openPanel) return;
    const panel = openPanel;

    panel.removeAttribute('data-shown');
    if (scrim) scrim.removeAttribute('data-shown');
    document.body.removeAttribute('data-locked');

    $$('[data-open]').forEach((btn) => btn.setAttribute('aria-expanded', 'false'));

    openPanel = null;
    if (restore && lastFocused) lastFocused.focus();
  }

  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open]:not([data-open=""])');
    if (opener) {
      e.preventDefault();
      const name = opener.dataset.open;
      if (openPanel && openPanel.dataset.panel === name) close();
      else open(name);
      return;
    }

    if (e.target.closest('[data-close]') || e.target === scrim) {
      e.preventDefault();
      close();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && openPanel) { e.preventDefault(); close(); }
    if (e.key === 'Tab' && openPanel) trapFocus(openPanel, e);
  });

  // -------------------------------------------------------------- header

  const header = $('[data-header]');
  if (header) {
    let ticking = false;
    const onScroll = () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        header.classList.toggle('is-stuck', window.scrollY > 24);
        ticking = false;
      });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // -------------------------------------------------------- product options

  // Products such as oud sold by the tola carry their price in a required
  // option group. Until a choice is made there is no price to add, so the
  // button stays disabled rather than posting something the API will reject.
  const optionsRoot = $('[data-options]');

  function selectedOptions() {
    return $$('[data-option-group]').map((group) => {
      const chosen = $$('[data-option-value]', group).filter((i) => i.checked);
      return {
        optionId: group.dataset.optionGroup,
        required: group.dataset.required === '1',
        values: chosen.map((i) => ({ valueId: i.value, quantity: 1 })),
        extra: chosen.reduce((sum, i) => sum + (Number(i.dataset.price) || 0), 0),
      };
    });
  }

  function syncOptions() {
    const groups = selectedOptions();
    const missing = groups.some((g) => g.required && g.values.length === 0);
    const extra = groups.reduce((sum, g) => sum + g.extra, 0);

    const priceBox = $('[data-price-base]');
    const display = $('[data-price-display] .price__now');

    if (priceBox && display) {
      const base = Number(priceBox.dataset.priceBase) || 0;
      const total = base + extra;
      // Only rewrite once a choice exists, so the "from" price stays until then.
      if (!missing) {
        display.textContent = `${formatKwd(total)} ${display.dataset.symbol || ''}`.trim();
        $('[data-price-display] .price__from')?.setAttribute('hidden', '');
      }
    }

    $$('[data-add-to-cart]').forEach((btn) => {
      btn.disabled = missing;
      btn.setAttribute('aria-disabled', String(missing));
    });
  }

  // Kuwaiti Dinar carries three decimals, but a whole-dinar figure reads
  // better without the trailing zeros.
  function formatKwd(value) {
    if (Number.isInteger(value)) return String(value);
    return value.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
  }

  if (optionsRoot) {
    $$('[data-option-value]').forEach((input) => input.addEventListener('change', syncOptions));
    syncOptions();
  }

  // ------------------------------------------------------------ add to cart

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-add-to-cart]');
    if (!btn) return;

    e.preventDefault();
    if (btn.disabled) return;

    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = cfg.i18n.adding;

    try {
      const qtyInput = btn.closest('form, [data-product-scope]')?.querySelector('[data-qty-input]');
      const data = await api(cfg.routes.cartAdd, {
        method: 'POST',
        body: JSON.stringify({
          productId: btn.dataset.addToCart,
          quantity: qtyInput ? Number(qtyInput.value) || 1 : 1,
          options: optionsRoot
            ? selectedOptions()
                .filter((g) => g.values.length)
                .map(({ optionId, values }) => ({ optionId, values }))
            : [],
        }),
      });

      setCartCount(data.count);
      btn.textContent = cfg.i18n.added;
      open('cart');
      setTimeout(() => { btn.textContent = original; btn.disabled = false; }, 1400);
    } catch (err) {
      toast(err.payload?.message || cfg.i18n.error, 'error');
      btn.textContent = original;
      btn.disabled = false;
    }
  });

  function setCartCount(count) {
    $$('[data-cart-count]').forEach((el) => {
      el.textContent = count;
      el.hidden = !count;
    });
  }

  async function loadCart() {
    const body = $('[data-cart-body]');
    if (!body) return;

    try {
      const html = await fetch(cfg.routes.cartDrawer, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      }).then((r) => r.text());
      body.innerHTML = html;
    } catch {
      body.innerHTML = `<p class="alert alert--error">${cfg.i18n.error}</p>`;
    }
  }

  // Quantity controls inside the drawer post back and re-render it.
  document.addEventListener('click', async (e) => {
    const step = e.target.closest('[data-cart-step]');
    if (!step) return;
    e.preventDefault();

    const key = step.dataset.cartStep;
    const qty = Number(step.dataset.qty);
    const body = $('[data-cart-body]');
    if (body) body.style.opacity = '0.55';

    try {
      const data = await api(`${cfg.routes.cartDrawer.replace('/drawer', '')}/${key}`, {
        method: qty < 1 ? 'DELETE' : 'PATCH',
        body: JSON.stringify({ quantity: qty }),
      });
      setCartCount(data.count);
      await loadCart();
    } catch {
      toast(cfg.i18n.error, 'error');
    } finally {
      if (body) body.style.opacity = '';
    }
  });

  // -------------------------------------------------------------- wishlist

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-wishlist]');
    if (!btn) return;
    e.preventDefault();

    const pressed = btn.getAttribute('aria-pressed') === 'true';
    btn.setAttribute('aria-pressed', String(!pressed));

    try {
      const data = await api(`${cfg.routes.wishlist}/${btn.dataset.wishlist}`, { method: 'POST' });
      btn.setAttribute('aria-pressed', String(data.saved));
      $$('[data-wishlist-count]').forEach((el) => {
        el.textContent = data.count;
        el.hidden = !data.count;
      });
      toast(data.saved ? cfg.i18n.wishAdd : cfg.i18n.wishRemove);
    } catch {
      btn.setAttribute('aria-pressed', String(pressed));
      toast(cfg.i18n.error, 'error');
    }
  });

  // ---------------------------------------------------------------- search

  const RECENT_KEY = 'das.recent';

  function recent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch { return []; }
  }

  function pushRecent(term) {
    if (!term || term.length < 2) return;
    try {
      const list = [term, ...recent().filter((t) => t !== term)].slice(0, 6);
      localStorage.setItem(RECENT_KEY, JSON.stringify(list));
    } catch { /* private mode — history is a convenience, not a requirement */ }
  }

  function renderRecent() {
    const wrap = $('[data-recent-wrap]');
    const list = $('[data-recent]');
    if (!wrap || !list) return;

    const items = recent();
    wrap.hidden = items.length === 0;
    list.innerHTML = items
      .map((t) => `<a class="chip" href="${cfg.routes.search}?q=${encodeURIComponent(t)}">${escapeHtml(t)}</a>`)
      .join('');
  }

  $('[data-clear-recent]')?.addEventListener('click', () => {
    try { localStorage.removeItem(RECENT_KEY); } catch {}
    renderRecent();
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  }

  const searchInput = $('[data-search-input]');
  if (searchInput) {
    const results = $('[data-search-results]');
    const idle = $('[data-search-idle]');
    const status = $('#search-status');
    let timer;
    let controller;

    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      const term = searchInput.value.trim();

      if (term.length < 2) {
        results.hidden = true;
        idle.hidden = false;
        return;
      }

      timer = setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();

        try {
          const html = await fetch(`${cfg.routes.suggest}?q=${encodeURIComponent(term)}`, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
          }).then((r) => r.text());

          results.innerHTML = html;
          results.hidden = false;
          idle.hidden = true;
          if (status) status.textContent = results.dataset.count || '';
        } catch (err) {
          if (err.name !== 'AbortError') toast(cfg.i18n.error, 'error');
        }
      }, 220);
    });

    searchInput.form?.addEventListener('submit', () => pushRecent(searchInput.value.trim()));
  }

  // ------------------------------------------------------------- accordion

  // A panel that starts open must not carry a fixed height, or it would clip
  // once fonts and images settle.
  $$('.accordion__panel[data-shown]').forEach((p) => { p.style.maxBlockSize = 'none'; });

  function setPanel(panel, open) {
    const height = panel.scrollHeight;

    if (open) {
      panel.setAttribute('data-shown', '');
      panel.style.maxBlockSize = `${height}px`;
      // Release the clamp once it has finished, so later reflows are free.
      panel.addEventListener('transitionend', function done(ev) {
        if (ev.propertyName !== 'max-block-size' && ev.propertyName !== 'max-height') return;
        panel.removeEventListener('transitionend', done);
        if (panel.hasAttribute('data-shown')) panel.style.maxBlockSize = 'none';
      });
      return;
    }

    // Collapsing needs a concrete starting height to animate away from.
    panel.style.maxBlockSize = `${height}px`;
    void panel.offsetHeight;
    panel.removeAttribute('data-shown');
    panel.style.maxBlockSize = '0px';
  }

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.accordion__trigger');
    if (!trigger) return;

    const expanded = trigger.getAttribute('aria-expanded') === 'true';
    trigger.setAttribute('aria-expanded', String(!expanded));

    const panel = document.getElementById(trigger.getAttribute('aria-controls'));
    if (panel) setPanel(panel, !expanded);
  });

  // --------------------------------------------------------------- gallery

  const gallery = $('[data-gallery]');
  if (gallery) {
    const slides = $$('[data-gallery-slide]', gallery);
    const thumbs = $$('[data-gallery-thumb]', gallery);

    const show = (index) => {
      slides.forEach((s, i) => s.classList.toggle('is-active', i === index));
      thumbs.forEach((t, i) => t.setAttribute('aria-current', String(i === index)));
    };

    thumbs.forEach((thumb, i) => {
      thumb.addEventListener('click', () => show(i));
      thumb.addEventListener('keydown', (e) => {
        // Arrow keys follow reading direction, so they stay intuitive in Arabic.
        const rtl = cfg.dir === 'rtl';
        const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
        const back = rtl ? 'ArrowRight' : 'ArrowLeft';

        if (e.key === forward) { e.preventDefault(); thumbs[(i + 1) % thumbs.length].focus(); show((i + 1) % thumbs.length); }
        if (e.key === back) { e.preventDefault(); const p = (i - 1 + thumbs.length) % thumbs.length; thumbs[p].focus(); show(p); }
      });
    });
  }

  // ------------------------------------------------------------- quantity

  document.addEventListener('click', (e) => {
    const step = e.target.closest('[data-qty-step]');
    if (!step) return;
    e.preventDefault();

    const input = step.parentElement.querySelector('[data-qty-input]');
    if (!input) return;

    const min = Number(input.min || 1);
    const max = input.max ? Number(input.max) : Infinity;
    const next = Math.min(max, Math.max(min, Number(input.value || 1) + Number(step.dataset.qtyStep)));

    input.value = next;
    input.dispatchEvent(new Event('change', { bubbles: true }));

    step.parentElement.querySelectorAll('[data-qty-step]').forEach((b) => {
      const dir = Number(b.dataset.qtyStep);
      b.disabled = (dir < 0 && next <= min) || (dir > 0 && next >= max);
    });
  });

  // --------------------------------------------------------- scroll reveal

  const revealables = $$('[data-reveal]');
  if (revealables.length && 'IntersectionObserver' in window && !reduceMotion) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        // Stagger siblings slightly so a row arrives as a sequence, not a jump.
        const delay = Number(entry.target.dataset.revealDelay || 0);
        setTimeout(() => entry.target.classList.add('is-visible'), delay);
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    revealables.forEach((el) => io.observe(el));
  } else {
    revealables.forEach((el) => el.classList.add('is-visible'));
  }

  // ------------------------------------------------------- sticky buy bar

  const buyBar = $('[data-buy-bar]');
  const buyAnchor = $('[data-buy-anchor]');
  if (buyBar && buyAnchor && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(
      ([entry]) => buyBar.classList.toggle('is-visible', !entry.isIntersecting),
      { rootMargin: '-120px 0px 0px 0px' }
    );
    io.observe(buyAnchor);
  }

  // ----------------------------------------------------------------- share

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-share]');
    if (!btn) return;
    e.preventDefault();

    const data = { title: btn.dataset.shareTitle || document.title, url: location.href };

    if (navigator.share) {
      try { await navigator.share(data); } catch { /* dismissed */ }
      return;
    }

    try {
      await navigator.clipboard.writeText(location.href);
      toast(cfg.i18n.copied);
    } catch {
      toast(cfg.i18n.error, 'error');
    }
  });

  // ------------------------------------------------------- filter autoform

  $$('[data-autosubmit]').forEach((el) => {
    el.addEventListener('change', () => el.closest('form')?.submit());
  });

  // -------------------------------------------------------- order success

  // A one-off chime for the moment an order is confirmed — unlike the
  // admin's repeating alert, this plays once and never needs an explicit
  // arm. Browsers gate audio until a gesture; reaching this page by
  // submitting the checkout form satisfies that in most browsers, but
  // where it does not this just stays silent — the animation on screen
  // never depends on it, so nothing is lost.
  if ($('[data-celebrate]')) {
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) {
        const audio = new Ctx();
        if (audio.state === 'suspended') audio.resume().catch(() => {});

        const now = audio.currentTime;
        [523.25, 659.25, 783.99].forEach((freq, i) => { // C5, E5, G5 — a small lift, not a fanfare
          const osc = audio.createOscillator();
          const gain = audio.createGain();
          osc.type = 'sine';
          osc.frequency.value = freq;

          const start = now + i * 0.1;
          gain.gain.setValueAtTime(0.0001, start);
          gain.gain.exponentialRampToValueAtTime(0.24, start + 0.02);
          gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.5);

          osc.connect(gain).connect(audio.destination);
          osc.start(start);
          osc.stop(start + 0.55);
        });
      }
    } catch {
      // A flourish, not the message — fail silent.
    }
  }

  window.StoreUI = { toast, open, close };
})();
