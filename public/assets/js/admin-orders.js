/* ===========================================================================
   Order board — polls for new orders and rings until acknowledged.
   ---------------------------------------------------------------------------
   The tone is synthesised with the Web Audio API rather than shipped as a
   file: nothing to download, it loops seamlessly, and it keeps working
   offline. Browsers refuse to start audio without a gesture, so the page
   arms the sound on an explicit press and says so plainly.
   =========================================================================== */

(() => {
  'use strict';

  const board = document.querySelector('[data-order-board]');
  if (!board) return;

  const cfg = window.OrderBoard || {};
  const $ = (s, r = document) => r.querySelector(s);

  const banner = $('[data-alert-banner]');
  const countEl = $('[data-alert-count]');
  const listEl = $('[data-order-list]');
  const errEl = $('[data-feed-error]');
  const armBtn = $('[data-arm]');
  const armedTag = $('[data-armed]');
  const seenForm = $('[data-seen-form]');

  const pollMs = Math.max(10, Number(board.dataset.poll) || 30) * 1000;
  const alertOn = board.dataset.alert === '1';

  // ------------------------------------------------------------------ sound

  let audio = null;
  let ringing = false;
  let ringTimer = null;

  function armAudio() {
    if (audio) return true;
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return false;
    audio = new Ctx();
    // Resuming inside the click is what satisfies the autoplay policy.
    if (audio.state === 'suspended') audio.resume();
    return true;
  }

  /** One two-tone chime. Short, clear, and not painful on repeat. */
  function chime() {
    if (!audio || audio.state !== 'running') return;

    const now = audio.currentTime;
    [880, 1320].forEach((freq, i) => {
      const osc = audio.createOscillator();
      const gain = audio.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;

      const start = now + i * 0.22;
      // Ramp rather than switch, so it does not click.
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(0.28, start + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.2);

      osc.connect(gain).connect(audio.destination);
      osc.start(start);
      osc.stop(start + 0.22);
    });
  }

  function startRinging() {
    if (ringing || !alertOn) return;
    ringing = true;
    chime();
    // Keeps ringing until acknowledged — that is the whole point.
    ringTimer = setInterval(chime, 3000);
  }

  function stopRinging() {
    ringing = false;
    clearInterval(ringTimer);
    ringTimer = null;
  }

  armBtn?.addEventListener('click', () => {
    if (!armAudio()) return;
    armBtn.hidden = true;
    if (armedTag) armedTag.hidden = false;
    chime();                       // confirm it works
    if (!banner.hidden) startRinging();
  });

  // ------------------------------------------------------------------ title

  const baseTitle = document.title;
  let flip = false;

  setInterval(() => {
    if (!ringing) {
      if (document.title !== baseTitle) document.title = baseTitle;
      return;
    }
    // A blinking title is the one alert that survives a background tab.
    flip = !flip;
    document.title = flip ? `🔔 ${countEl?.textContent.trim() || ''}` : baseTitle;
  }, 1200);

  // ------------------------------------------------------------------- feed

  let failures = 0;

  async function poll() {
    try {
      const res = await fetch(cfg.feed, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        cache: 'no-store',
      });

      if (res.status === 401 || res.status === 403 || res.redirected) {
        // Session expired — reload so the login screen shows rather than
        // silently pretending there are no orders.
        location.reload();
        return;
      }

      if (!res.ok) throw new Error(String(res.status));

      const data = await res.json();
      failures = 0;
      if (errEl) errEl.hidden = true;
      render(data);
    } catch {
      failures += 1;
      // Tolerate a blip; say something once it is clearly not coming back.
      if (errEl && failures >= 2) errEl.hidden = false;
    }
  }

  function render(data) {
    const unseen = Number(data.unseen) || 0;

    if (unseen > 0) {
      const label = unseen === 1
        ? cfg.i18n.one
        : (cfg.i18n.many || '').replace('%n', unseen);
      if (countEl) countEl.textContent = label;
      banner.hidden = false;
      startRinging();
    } else {
      banner.hidden = true;
      stopRinging();
    }

    paint(data.orders || [], data.unseenIds || []);
  }

  /** Rewrites the list in place, marking the ones not yet acknowledged. */
  function paint(orders, unseenIds) {
    if (!listEl) return;

    const seen = new Set(unseenIds);
    const existing = new Map(
      [...listEl.querySelectorAll('[data-order-id]')].map((el) => [el.dataset.orderId, el])
    );

    orders.forEach((order) => {
      const row = existing.get(order.id);
      if (row) {
        row.classList.toggle('is-new', seen.has(order.id));
        existing.delete(order.id);
        return;
      }
      // A genuinely new row: reload so it renders with the server's own
      // markup and formatting rather than a second, drifting copy here.
      location.reload();
    });
  }

  seenForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    stopRinging();
    banner.hidden = true;

    try {
      await fetch(seenForm.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': seenForm.querySelector('[name=_token]')?.value || '',
          Accept: 'application/json',
        },
      });
      document.querySelectorAll('.order-item.is-new').forEach((el) => el.classList.remove('is-new'));
    } catch {
      // The next poll will re-raise it if the acknowledgement did not land.
    }
  });

  poll();
  setInterval(poll, pollMs);
})();
