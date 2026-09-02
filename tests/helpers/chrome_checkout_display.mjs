const [port, base, mode = 'default'] = process.argv.slice(2);
const adminPassword = process.env.HTTP_TEST_ADMIN_PASSWORD || '';
const commandTimeoutMs = 5_000;
const stageTimeoutMs = 15_000;

let stage = 'startup';
let currentUrl = base || '(missing base URL)';
let ws;
let nextId = 0;
const pending = new Map();
const browserErrors = [];

const MODES = [
  'default',
  'populated',
  'empty',
  'alt',
  'compact',
  'mobile-390',
  'quantity',
  'remove',
  'out-of-stock',
  'image-error',
  'storage-corrupt',
  'xss',
  'preview',
  'restore',
];

const context = message => `${message} [mode=${mode} stage=${stage} url=${currentUrl}]`;
const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
const withTimeout = (promise, milliseconds, description) => {
  let timer;
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(context(`timeout after ${milliseconds}ms: ${description}`))), milliseconds);
    }),
  ]).finally(() => clearTimeout(timer));
};
const requireValue = (condition, message) => {
  if (!condition) throw new Error(context(message));
};

const call = (method, params = {}) => {
  const callId = ++nextId;
  const response = new Promise((resolve, reject) => {
    pending.set(callId, {resolve, reject, method});
    try {
      ws.send(JSON.stringify({id: callId, method, params}));
    } catch (error) {
      pending.delete(callId);
      reject(error);
    }
  });
  return withTimeout(response, commandTimeoutMs, `CDP ${method}`).finally(() => pending.delete(callId));
};

const evaluate = async expression => {
  const response = await call('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
  });
  if (response.exceptionDetails) {
    const detail = response.exceptionDetails.exception?.description || response.exceptionDetails.text;
    throw new Error(context(`Runtime.evaluate failed: ${detail}`));
  }
  return response.result.value;
};

const waitFor = async (description, expression, milliseconds = stageTimeoutMs) => {
  const deadline = Date.now() + milliseconds;
  let lastError;
  while (Date.now() < deadline) {
    try {
      if (await evaluate(expression)) return;
    } catch (error) {
      lastError = error;
    }
    await sleep(100);
  }
  const suffix = lastError ? `; last error: ${lastError.message}` : '';
  throw new Error(context(`timeout after ${milliseconds}ms waiting for ${description}${suffix}`));
};

const navigate = async (path, nextStage) => {
  stage = nextStage;
  currentUrl = new URL(path, base).href;
  await call('Page.navigate', {url: currentUrl});
  const expectedPath = JSON.stringify(new URL(currentUrl).pathname);
  await waitFor(
    `navigation to ${currentUrl}`,
    `document.readyState === 'complete' && location.pathname === ${expectedPath}`,
  );
  currentUrl = await evaluate('location.href');
};

const assertNoBrowserErrors = () => {
  if (browserErrors.length === 0) return;
  const detail = browserErrors.map(entry => `[${entry.type}] ${entry.message}`).join(' | ');
  throw new Error(context(`browser error: ${detail}`));
};

const setViewport = async (width, height = 900) => {
  await call('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile: width <= 480,
  });
};

const seedCart = async items => {
  const payload = JSON.stringify(items);
  await evaluate(`(() => {
    localStorage.setItem('cart', ${JSON.stringify(payload)});
    return true;
  })()`);
};

const cartProbe = async () => evaluate(`(() => {
  const btn = document.getElementById('whatsapp-order');
  const items = [...document.querySelectorAll('.cart-item')];
  const empty = !!document.querySelector('.cart-empty-state');
  const total = document.getElementById('cart-total')?.textContent || '';
  const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
  const page = document.querySelector('.cart-page');
  return {
    empty,
    itemCount: items.length,
    total,
    overflow,
    orderDisabled: !btn || btn.classList.contains('disabled') || btn.getAttribute('aria-disabled') === 'true',
    orderText: (btn?.textContent || '').trim(),
    pageTitle: document.querySelector('.cart-page-title')?.textContent?.trim() || '',
    layoutCompact: !!(page && page.classList.contains('cart-layout-compact')),
    hasDelivery: !!document.querySelector('.cart-delivery-block'),
    hasPayment: !!document.querySelector('.cart-payment-block'),
    hasReservation: !!document.getElementById('cart-reservation-note'),
    hasTerms: !!document.querySelector('.cart-terms-block'),
    hasImage: !!document.querySelector('.cart-product-image, .product-image-placeholder'),
    removeAria: document.querySelector('.remove-from-cart')?.getAttribute('aria-label') || '',
    qtyAria: [...document.querySelectorAll('.cart-item button[aria-label]')].map(b => b.getAttribute('aria-label')),
  };
})()`);

try {
  requireValue(port && base, 'usage: chrome_checkout_display.mjs <debug-port> <base-url> <mode>');
  requireValue(MODES.includes(mode), `invalid mode: ${mode}`);
  requireValue(typeof globalThis.WebSocket === 'function', 'global WebSocket is unavailable');

  const targetResponse = await withTimeout(
    fetch(`http://127.0.0.1:${port}/json/list`),
    commandTimeoutMs,
    'fetching Chrome /json/list',
  );
  requireValue(targetResponse.ok, `/json/list returned HTTP ${targetResponse.status}`);
  const targets = await targetResponse.json();
  const page = targets.find(t => t.type === 'page') || targets[0];
  requireValue(page?.webSocketDebuggerUrl, 'no CDP page target');

  ws = new WebSocket(page.webSocketDebuggerUrl);
  await withTimeout(new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, {once: true});
    ws.addEventListener('error', reject, {once: true});
  }), commandTimeoutMs, 'opening CDP websocket');

  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
      const request = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) request.reject(new Error(context(`${request.method}: ${message.error.message}`)));
      else request.resolve(message.result);
      return;
    }
    if (message.method === 'Runtime.exceptionThrown') {
      const text = message.params?.exceptionDetails?.exception?.description
        || message.params?.exceptionDetails?.text
        || 'uncaught exception';
      browserErrors.push({type: 'exception', message: text});
    }
    if (message.method === 'Runtime.consoleAPICalled') {
      const type = message.params?.type;
      if (type === 'error' || type === 'assert') {
        const parts = (message.params?.args || []).map(arg => arg.value || arg.description || '');
        browserErrors.push({type: `console.${type}`, message: parts.join(' ')});
      }
    }
  });

  await call('Runtime.enable');
  await call('Page.enable');
  await evaluate(`(() => {
    const originalOpen = window.open;
    window.open = function (url) {
      window.__checkoutOpenCalls = window.__checkoutOpenCalls || [];
      window.__checkoutOpenCalls.push(String(url || ''));
      return {
        closed: false,
        close() { this.closed = true; },
        set location(v) { window.__checkoutOpenNav = String(v || ''); },
        get location() { return window.__checkoutOpenNav || ''; },
      };
    };
    window.__checkoutOriginalOpen = originalOpen;
    return true;
  })()`);

  if (mode === 'mobile-390') {
    await setViewport(390, 844);
  } else {
    await setViewport(1440, 900);
  }

  if (mode === 'preview' || mode === 'restore') {
    requireValue(adminPassword, 'HTTP_TEST_ADMIN_PASSWORD required for preview/restore');
    await call('Network.enable');
    await navigate('admin_login.php', 'admin-login');
    const submitted = await evaluate(`(() => {
      const username = document.querySelector('input[name="username"]');
      const password = document.querySelector('input[name="password"]');
      const form = username && username.form;
      if (!username || !password || !form) return false;
      username.value = 'http-admin';
      password.value = ${JSON.stringify(adminPassword)};
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
      return true;
    })()`);
    requireValue(submitted, 'admin login form missing');
    await waitFor(
      'admin products after login',
      `location.pathname === '/admin_products.php' || location.pathname.endsWith('/admin_products.php')`,
      20000,
    );
    await navigate('admin_settings.php', 'admin');
    await waitFor('checkout section', `!!document.getElementById('checkout-display-heading')`, 20000);
    if (mode === 'preview') {
      await evaluate(`(() => {
        const title = document.getElementById('cart_page_title');
        if (title) {
          title.value = 'Preview Live Title';
          title.dispatchEvent(new Event('input', {bubbles: true}));
        }
        const sticky = document.getElementById('cart_summary_sticky');
        if (sticky) {
          sticky.checked = true;
          sticky.dispatchEvent(new Event('change', {bubbles: true}));
        }
        return true;
      })()`);
      await sleep(200);
      const preview = await evaluate(`(() => ({
        title: document.getElementById('checkout-preview-page-title')?.textContent || '',
        sticky: !document.getElementById('checkout-preview-sticky-flag')?.hidden,
        storageBefore: localStorage.getItem('cart'),
        orderDisabled: !!document.getElementById('checkout-preview-order-btn-el')?.disabled,
        openCalls: (window.__checkoutOpenCalls || []).length,
      }))()`);
      requireValue(preview.title === 'Preview Live Title', `preview title=${preview.title}`);
      requireValue(preview.sticky, 'sticky flag should show');
      requireValue(preview.orderDisabled, 'preview order button must stay disabled');
      requireValue(preview.openCalls === 0, 'preview must not open WhatsApp');
      const storageAfter = await evaluate(`localStorage.getItem('cart')`);
      requireValue(storageAfter === preview.storageBefore, 'preview must not mutate localStorage');
    }
    if (mode === 'restore') {
      const form = await evaluate(`!!document.getElementById('restore-checkout-display-form')`);
      requireValue(form, 'restore form missing');
      const btn = await evaluate(`(() => {
        const b = document.querySelector('#restore-checkout-display-form button[type="submit"]');
        return b ? b.textContent.trim() : '';
      })()`);
      requireValue(/Restaurar carrito y pedido/i.test(btn), `restore button text=${btn}`);
    }
  } else {
    if (mode === 'storage-corrupt') {
      await navigate('cart.php', 'cart-corrupt-seed');
      await evaluate(`(() => { localStorage.setItem('cart', '{not-json'); return true; })()`);
    } else if (mode === 'empty') {
      await navigate('cart.php', 'cart-empty-seed');
      await evaluate(`(() => { localStorage.removeItem('cart'); return true; })()`);
    } else if (mode === 'out-of-stock') {
      await navigate('cart.php', 'cart-oos-seed');
      await seedCart([{productId: '1', quantity: 999}]);
    } else if (mode === 'populated' || mode === 'default' || mode === 'alt' || mode === 'compact'
      || mode === 'mobile-390' || mode === 'quantity' || mode === 'remove'
      || mode === 'image-error' || mode === 'xss') {
      await navigate('cart.php', 'cart-seed');
      await seedCart([
        {productId: '1', quantity: 1, productName: 'IGNORAR', productPrice: '0.01'},
        {productId: '1', quantity: 1},
      ]);
    }

    await navigate('cart.php', 'cart');
    await waitFor('cart boot', `!!document.getElementById('cart-checkout-boot') && !!document.getElementById('whatsapp-order')`);
    await sleep(250);

    if (mode === 'empty' || mode === 'storage-corrupt') {
      const probe = await cartProbe();
      requireValue(probe.empty, 'expected empty cart state');
      requireValue(probe.orderDisabled, 'order button must be disabled when empty');
      requireValue(!probe.overflow, 'horizontal overflow');
      await evaluate(`(() => { document.getElementById('whatsapp-order').click(); return true; })()`);
      const opens = await evaluate(`(window.__checkoutOpenCalls || []).length`);
      requireValue(opens === 0, 'empty cart must not open WhatsApp');
    }

    if (mode === 'default' || mode === 'populated' || mode === 'alt' || mode === 'compact' || mode === 'mobile-390') {
      const probe = await cartProbe();
      requireValue(!probe.empty && probe.itemCount >= 1, `expected items, got ${probe.itemCount}`);
      requireValue(!probe.orderDisabled, 'order button should be enabled');
      requireValue(/\$\d/.test(probe.total), `total format ${probe.total}`);
      requireValue(!probe.overflow, 'horizontal overflow');
      requireValue(probe.removeAria.length > 0, 'remove aria-label missing');
      requireValue(probe.qtyAria.some(a => /Disminuir|Aumentar/i.test(a || '')), 'qty aria-labels missing');
      if (mode === 'alt') {
        requireValue(/Carrito Alt|Alt/i.test(probe.pageTitle) || probe.hasDelivery, 'alt title/delivery expected');
        requireValue(probe.hasReservation || probe.hasTerms || probe.hasDelivery, 'alt commercial blocks');
      }
      if (mode === 'compact') {
        requireValue(probe.layoutCompact, 'compact layout class missing');
      }
    }

    if (mode === 'quantity') {
      await evaluate(`(() => {
        const plus = [...document.querySelectorAll('.cart-item button[aria-label]')].find(b => /Aumentar/i.test(b.getAttribute('aria-label') || ''));
        if (!plus) throw new Error('plus missing');
        plus.click();
        return true;
      })()`);
      await sleep(200);
      const qty = await evaluate(`document.querySelector('.update-quantity')?.value || ''`);
      requireValue(Number(qty) >= 2, `qty after plus=${qty}`);
    }

    if (mode === 'remove') {
      await evaluate(`(() => {
        const btn = document.querySelector('.remove-from-cart');
        if (!btn) throw new Error('remove missing');
        btn.click();
        return true;
      })()`);
      await sleep(200);
      const probe = await cartProbe();
      requireValue(probe.empty, 'cart should be empty after remove');
      requireValue(probe.orderDisabled, 'order disabled after remove');
    }

    if (mode === 'out-of-stock') {
      const probe = await cartProbe();
      requireValue(probe.itemCount >= 1, 'oos item missing');
      requireValue(probe.orderDisabled, 'order must stay disabled when over stock');
      const stockText = await evaluate(`([...document.querySelectorAll('.cart-item small')].map(el => el.textContent || '').join(' '))`);
      requireValue(/disponibles|Solo/i.test(stockText), `stock template missing: ${stockText}`);
    }

    if (mode === 'image-error') {
      await evaluate(`(() => {
        const img = document.querySelector('.cart-product-image');
        if (!img) return !!document.querySelector('.product-image-placeholder');
        img.dispatchEvent(new Event('error'));
        return true;
      })()`);
      await sleep(150);
      const placeholder = await evaluate(`!!document.querySelector('.product-image-placeholder')`);
      requireValue(placeholder, 'image error should show placeholder');
    }

    if (mode === 'xss') {
      const probe = await evaluate(`(() => ({
        executed: typeof globalThis.checkoutXssExecuted,
        onerror: [...document.querySelectorAll('[onerror]')].length,
        onclick: [...document.querySelectorAll('[onclick]')].length,
        jsUrl: [...document.querySelectorAll('a[href]')].some(a => /^javascript:/i.test(a.getAttribute('href') || '')),
        title: document.querySelector('.cart-page-title')?.textContent || '',
      }))()`);
      requireValue(probe.executed === 'undefined', 'checkoutXssExecuted leaked');
      requireValue(probe.onerror === 0, 'onerror attrs');
      requireValue(probe.onclick === 0, 'onclick attrs');
      requireValue(!probe.jsUrl, 'javascript: href');
      requireValue(!/<script/i.test(probe.title), 'raw script in title');
    }
  }

  assertNoBrowserErrors();
  console.log(JSON.stringify({ok: true, mode, browserErrors: 0}, null, 2));
  ws.close();
  process.exit(0);
} catch (error) {
  console.error(error && error.stack ? error.stack : String(error));
  try { if (ws) ws.close(); } catch (_) {}
  process.exit(1);
}
