/* Admin panel behaviour. Plain JavaScript, no build step. Everything here is
   progressive: with scripts off, the forms and links still work. */
(function () {
  'use strict';

  var $ = function (selector, root) { return (root || document).querySelector(selector); };
  var $$ = function (selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); };

  /* ---- small side menu on narrow screens ---------------------------------- */

  var side = $('#panel-side');
  var scrim = $('[data-scrim]');

  function setSide(open) {
    if (!side || !scrim) { return; }
    side.classList.toggle('is-open', open);
    scrim.hidden = !open;
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-toggle-side]')) { setSide(!side.classList.contains('is-open')); }
    else if (event.target === scrim) { setSide(false); }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') { setSide(false); }
  });

  /* ---- toasts -------------------------------------------------------------- */

  function toast(message) {
    var stack = $('[data-toasts]');
    if (!stack) { return; }
    var node = document.createElement('div');
    node.className = 'panel-toast';
    node.textContent = message;
    stack.appendChild(node);
    setTimeout(function () { node.remove(); }, 6000);
  }

  window.panelToast = toast;

  /* ---- "are you sure?" before anything destructive -------------------------- */

  var dialog = $('#confirm-dialog');

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm') || (event.submitter && event.submitter.getAttribute('data-confirm'));

    if (!message || form.__confirmed || !dialog || typeof dialog.showModal !== 'function') { return; }

    event.preventDefault();

    $('[data-confirm-text]', dialog).textContent = message;
    var submitter = event.submitter || null;

    // Deleting reads as a warning; an ordinary change does not.
    var danger = form.hasAttribute('data-danger') || (submitter && submitter.hasAttribute('data-danger'));
    $('[data-confirm-ok]', dialog).classList.toggle('btn-p--danger', !!danger);

    dialog.addEventListener('close', function onClose() {
      dialog.removeEventListener('close', onClose);
      if (dialog.returnValue === 'ok') {
        form.__confirmed = true;
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(submitter); } else { form.submit(); }
      }
    });

    dialog.returnValue = '';
    dialog.showModal();
  });

  /* ---- filters that apply as soon as they change ----------------------------- */

  document.addEventListener('change', function (event) {
    var control = event.target;
    if (control.matches('[data-autosubmit]') && control.form) { control.form.submit(); }
  });

  /* ---- repeating rows: product options and quantity tiers --------------------- */

  function nextIndex(container) {
    var next = Number(container.getAttribute('data-next') || container.children.length);
    container.setAttribute('data-next', String(next + 1));
    return next;
  }

  document.addEventListener('click', function (event) {
    var add = event.target.closest('[data-repeat-add]');

    if (add) {
      var container = add.closest('[data-repeat]');
      var template = container && container.querySelector('template[data-repeat-template="' + add.getAttribute('data-repeat-add') + '"]');
      if (!container || !template) { return; }

      var token = template.getAttribute('data-token') || '__INDEX__';
      var html = template.innerHTML.split(token).join(String(nextIndex(container)));
      var holder = $('[data-repeat-rows]', container) || container;
      holder.insertAdjacentHTML('beforeend', html);

      var fresh = holder.lastElementChild;
      var first = fresh && $('input:not([type=hidden]), select, textarea', fresh);
      if (first) { first.focus(); }
      return;
    }

    var remove = event.target.closest('[data-repeat-remove]');

    if (remove) {
      var row = remove.closest('[data-repeat-row]');
      if (row) { row.remove(); }
    }
  });

  /* ---- fields that only apply to one choice of a select ------------------------------ */

  function applySwitch(select) {
    var scope = select.closest('[data-switch-scope]') || document;

    $$('[data-when]', scope).forEach(function (node) {
      // A row nested inside this scope answers to its own select, not this one.
      var owner = node.closest('[data-switch-scope]') || document;
      if (owner !== scope) { return; }
      node.hidden = node.getAttribute('data-when').split(/\s+/).indexOf(select.value) === -1;
    });
  }

  document.addEventListener('change', function (event) {
    if (event.target.matches('select[data-switch]')) { applySwitch(event.target); }
  });

  /* ---- type to narrow a long list of checkboxes ------------------------------------------- */

  document.addEventListener('input', function (event) {
    var box = event.target.closest('[data-filter]');
    if (!box) { return; }

    var needle = box.value.trim().toLowerCase();
    $$('[data-filter-item]', $(box.getAttribute('data-filter'))).forEach(function (item) {
      item.hidden = needle !== '' && item.textContent.toLowerCase().indexOf(needle) === -1;
    });
  });

  /* ---- chosen images, previewed before they are uploaded ------------------------ */

  document.addEventListener('change', function (event) {
    var input = event.target;
    if (!input.matches('input[type=file][data-preview]')) { return; }

    var target = $(input.getAttribute('data-preview'));
    if (!target) { return; }

    $$('[data-preview-item]', target).forEach(function (node) { node.remove(); });

    Array.prototype.slice.call(input.files || []).forEach(function (file) {
      if (!/^image\//.test(file.type)) { return; }
      var item = document.createElement('div');
      item.className = 'thumbs__item';
      item.setAttribute('data-preview-item', '');
      var image = document.createElement('img');
      image.alt = '';
      image.src = URL.createObjectURL(file);
      item.appendChild(image);
      target.appendChild(item);
    });
  });

  /* ---- a field that selects itself when it is focused, so it can be copied -------------- */

  document.addEventListener('focusin', function (event) {
    if (event.target.matches('input[data-select]')) { event.target.select(); }
  });

  /* ---- print ---------------------------------------------------------------------- */

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-print]')) { window.print(); }
  });

  /* ---- copy to clipboard ---------------------------------------------------------- */

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button || !navigator.clipboard) { return; }

    navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function () {
      toast(button.getAttribute('data-copied') || 'Copied');
    });
  });

  /* ---- new-order alert ------------------------------------------------------------- */

  var feed = document.body.getAttribute('data-orders-feed');

  if (feed) {
    var every = Math.max(parseInt(document.body.getAttribute('data-orders-poll'), 10) || 30, 10) * 1000;
    var sound = document.body.getAttribute('data-orders-sound') === '1';
    var storeKey = 'panel.lastOrderId';

    var beep = function () {
      try {
        var Context = window.AudioContext || window.webkitAudioContext;
        var audio = new Context();
        var oscillator = audio.createOscillator();
        var gain = audio.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = 880;
        gain.gain.value = 0.08;
        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.start();
        oscillator.stop(audio.currentTime + 0.35);
      } catch (error) { /* a browser that will not play it is not an error */ }
    };

    var poll = function () {
      fetch(feed, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data) { return; }

          var seen = 0;
          try { seen = parseInt(localStorage.getItem(storeKey), 10) || 0; } catch (error) { seen = 0; }

          if (seen === 0) {
            // The first look only learns where things stand; it does not announce the whole backlog.
            try { localStorage.setItem(storeKey, String(data.latestId)); } catch (error) { /* ignore */ }
          } else if (data.latestId > seen) {
            try { localStorage.setItem(storeKey, String(data.latestId)); } catch (error) { /* ignore */ }
            toast(data.message);
            if (sound) { beep(); }
          }

          $$('[data-new-orders]').forEach(function (node) {
            node.textContent = data.awaiting;
            node.hidden = data.awaiting < 1;
          });
        })
        .catch(function () { /* the next poll will try again */ });
    };

    poll();
    setInterval(poll, every);
  }

  /* ---- offer to add the panel to the home screen ------------------------------------ */

  var sheet = $('[data-install]');
  var installScrim = $('[data-install-scrim]');
  var deferred = null;
  var phoneIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  var snoozeKey = 'panel.install';

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferred = event;
    refreshInstall();
  });

  // What can be offered: nothing once it is installed; Android and Chrome get a button, iPhone gets the steps.
  function canOffer() { return !standalone && (deferred !== null || phoneIos); }

  function refreshInstall() {
    $$('[data-install-open-row]').forEach(function (row) { row.hidden = !canOffer(); });
    if (sheet) {
      $('[data-install-native]', sheet).hidden = deferred === null;
      $('[data-install-ios]', sheet).hidden = !(phoneIos && deferred === null);
    }
  }

  function setInstall(open) {
    if (!sheet || !installScrim) { return; }
    if (open) {
      refreshInstall();
      sheet.hidden = false;
      installScrim.hidden = false;
      requestAnimationFrame(function () { sheet.classList.add('is-open'); installScrim.classList.add('is-open'); });
    } else {
      sheet.classList.remove('is-open');
      installScrim.classList.remove('is-open');
      setTimeout(function () { sheet.hidden = true; installScrim.hidden = true; }, 220);
    }
  }

  function snoozeInstall(days) {
    try { localStorage.setItem(snoozeKey, String(Date.now() + days * 86400000)); } catch (error) { /* ignore */ }
  }

  if (sheet && installScrim) {
    var days = parseInt(sheet.getAttribute('data-snooze-days'), 10) || 14;
    var wait = (parseInt(sheet.getAttribute('data-delay'), 10) || 4) * 1000;
    var until = 0;
    try { until = parseInt(localStorage.getItem(snoozeKey), 10) || 0; } catch (error) { until = 0; }

    refreshInstall();

    if (Date.now() > until) {
      setTimeout(function () { if (canOffer()) { setInstall(true); } }, wait);
    }

    var later = function () { snoozeInstall(days); setInstall(false); };

    $('[data-install-later]', sheet).addEventListener('click', later);
    installScrim.addEventListener('click', later);
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !sheet.hidden) { later(); } });

    $('[data-install-go]', sheet).addEventListener('click', function () {
      if (!deferred) { return; }
      deferred.prompt();
      deferred.userChoice.then(function (choice) {
        deferred = null;
        if (choice.outcome === 'accepted') { toast(sheet.getAttribute('data-installed')); }
        snoozeInstall(days);
        setInstall(false);
        refreshInstall();
      });
    });

    window.addEventListener('appinstalled', function () { snoozeInstall(3650); setInstall(false); });

    document.addEventListener('click', function (event) {
      if (event.target.closest('[data-install-open]')) { setInstall(true); }
    });
  }

  /* ---- install on the phone, and order notifications -------------------------------- */

  var worker = document.body.getAttribute('data-worker');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  if (worker && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register(worker, { scope: '/panel/' }).catch(function () { /* the panel works without it */ });
  }

  var push = $('[data-push]');

  if (push) {
    var enable = $('[data-push-enable]', push);
    var disable = $('[data-push-disable]', push);
    var test = $('[data-push-test]', push);
    var note = $('[data-push-note]', push);

    var say = function (message) {
      note.hidden = !message;
      note.textContent = message || '';
    };

    var show = function (state) {
      enable.hidden = state !== 'off';
      disable.hidden = state !== 'on';
      test.hidden = state !== 'on';
    };

    var post = function (url, body) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body || {})
      });
    };

    var toKey = function (base64Url) {
      var padded = (base64Url + '='.repeat((4 - base64Url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
      var raw = atob(padded);
      var bytes = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i += 1) { bytes[i] = raw.charCodeAt(i); }
      return bytes;
    };

    var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var installed = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    var refresh = function () {
      if (!supported) {
        say(isIos ? push.getAttribute('data-needs-install') : push.getAttribute('data-unsupported'));
        show('none');
        return;
      }

      if (Notification.permission === 'denied') {
        say(push.getAttribute('data-denied'));
        show('none');
        return;
      }

      navigator.serviceWorker.ready
        .then(function (registration) { return registration.pushManager.getSubscription(); })
        .then(function (subscription) {
          if (subscription) {
            // Remembering it again is harmless, and moves a shared device to whoever is signed in now.
            post(push.getAttribute('data-subscribe'), { endpoint: subscription.endpoint });
          }
          say(subscription ? push.getAttribute('data-on') : push.getAttribute('data-off'));
          show(subscription ? 'on' : 'off');
        })
        .catch(function () { show('off'); });
    };

    enable.addEventListener('click', function () {
      enable.disabled = true;

      Notification.requestPermission()
        .then(function (permission) {
          if (permission !== 'granted') { return null; }
          return navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(push.getAttribute('data-key')) });
          });
        })
        .then(function (subscription) {
          if (!subscription) { return null; }
          return post(push.getAttribute('data-subscribe'), { endpoint: subscription.endpoint }).then(function (response) {
            if (!response.ok) {
              subscription.unsubscribe();
              throw new Error('refused');
            }
          });
        })
        .catch(function () { toast(push.getAttribute('data-failed')); })
        .then(function () { enable.disabled = false; refresh(); });
    });

    disable.addEventListener('click', function () {
      navigator.serviceWorker.ready
        .then(function (registration) { return registration.pushManager.getSubscription(); })
        .then(function (subscription) {
          if (!subscription) { return null; }
          return post(push.getAttribute('data-unsubscribe'), { endpoint: subscription.endpoint }).then(function () { return subscription.unsubscribe(); });
        })
        .then(refresh);
    });

    test.addEventListener('click', function () {
      test.disabled = true;

      post(push.getAttribute('data-test'))
        .then(function (response) { return response.ok ? response.json() : { delivered: 0 }; })
        .then(function (data) { toast(push.getAttribute(data.delivered > 0 ? 'data-sent' : 'data-none-sent')); })
        .catch(function () { toast(push.getAttribute('data-none-sent')); })
        .then(function () { test.disabled = false; });
    });

    refresh();
  }
})();
