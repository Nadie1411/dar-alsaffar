/* ===========================================================================
   Apple Pay button on the payment page
   ---------------------------------------------------------------------------
   MyFatoorah draws the button, in its own frame, and takes the payment. This
   file loads MyFatoorah's script, starts the button on the session the server
   opened, and — whatever the button says — asks the server what became of the
   payment. The server asks MyFatoorah; nothing here decides that an order is paid.
   =========================================================================== */

(() => {
  'use strict';

  const root = document.getElementById('apple-pay');
  if (!root) return;

  const status = document.getElementById('apple-pay-status');
  const retry = document.getElementById('apple-pay-retry');
  const text = JSON.parse(root.dataset.text || '{}');
  const csrf = window.Store?.csrf || '';

  let settling = false;
  let ready = false;

  const say = (message, tone = '') => {
    status.textContent = message;
    if (tone) status.dataset.tone = tone; else delete status.dataset.tone;
  };

  // The button only exists on an Apple device with a card in Wallet. Said plainly
  // instead of leaving an empty gap where a button should be.
  const canPay = () => {
    try {
      return 'ApplePaySession' in window && window.ApplePaySession.canMakePayments();
    } catch {
      return false;
    }
  };

  const giveUp = (message) => {
    root.hidden = true;
    say(message, 'error');
  };

  // Back to a state where the shopper can try again. The session may be spent, so a
  // fresh page (and with it a fresh session) is the way to retry.
  const failed = (message) => {
    root.hidden = true;
    retry.hidden = false;
    say(message, 'error');
  };

  async function settle() {
    if (settling) return;
    settling = true;
    say(text.checking);

    let result;

    try {
      const response = await fetch(root.dataset.confirm, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
      });

      if (!response.ok) throw new Error('not answered');
      result = await response.json();
    } catch {
      // The payment may well have gone through. Say that it is being confirmed
      // rather than that it failed, and let the shop settle it.
      window.location.assign(root.dataset.pending);
      return;
    }

    if (result.redirect && (result.result === 'paid' || result.result === 'pending')) {
      window.location.assign(result.redirect);
      return;
    }

    settling = false;
    failed(result.result === 'unpaid' ? text.notPaid : text.failed);
  }

  // Called by MyFatoorah's button when the payment sheet is done. What it says is
  // not looked at: it is only the cue to ask the server.
  const onPayment = () => { settle(); };

  const onEvent = (event) => {
    if (event?.name === 'VIEW_READY') {
      ready = true;
      say('');
    }
  };

  function start() {
    if (typeof window.myfatoorah?.init !== 'function') {
      giveUp(text.unavailable);
      return;
    }

    window.myfatoorah.init({
      sessionId: root.dataset.session,
      callback: onPayment,
      containerId: 'apple-pay-button',
      subscribedEvents: ['VIEW_READY'],
      eventListener: onEvent,
      shouldHandlePaymentUrl: true,
      settings: {
        card: { isEnabled: false },
        googlePay: { isEnabled: false },
        stcPay: { isEnabled: false },
        applePay: {
          isEnabled: true,
          language: root.dataset.language,
          style: {
            frameHeight: '48px',
            frameWidth: '100%',
            button: { height: '48px', type: 'buy', borderRadius: '10px' },
          },
        },
      },
    });

    // If the button never shows, the way to another payment is already on the page;
    // this just says so.
    setTimeout(() => { if (!ready && !settling) say(text.slow); }, 15000);
  }

  if (!canPay()) {
    giveUp(text.unavailable);
    return;
  }

  say(text.loading);

  const script = document.createElement('script');
  script.src = root.dataset.sdk;
  script.onload = start;
  script.onerror = () => giveUp(text.unavailable);
  document.head.append(script);
})();
