const [port, base] = process.argv.slice(2);
const password = process.env.HTTP_TEST_ADMIN_PASSWORD;
const payloadName = '<script>globalThis.xssExecuted=1;document.title="XSS_EXECUTED"</script>';
const payloadDescription = '"><img src=x onerror=globalThis.xssExecuted=2>';
const commandTimeoutMs = 5_000;
const stageTimeoutMs = 12_000;

let stage = 'startup';
let currentUrl = base || '(missing base URL)';
let ws;
let nextId = 0;
let closing = false;
const pending = new Map();
const browserErrors = [];

const context = message => `${message} [stage=${stage} url=${currentUrl}]`;
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

const assertNoBrowserErrors = () => {
  if (browserErrors.length) {
    throw new Error(context(`browser JavaScript error(s): ${browserErrors.join(' | ')}`));
  }
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
  const result = await call('Page.navigate', {url: currentUrl});
  if (result.errorText) {
    try {
      await evaluate(`location.assign(${JSON.stringify(currentUrl)})`);
    } catch {
      // A successful assignment can destroy the execution context before CDP replies.
    }
  }
  const expectedPath = JSON.stringify(new URL(currentUrl).pathname);
  try {
    await waitFor(
      `navigation to ${currentUrl}`,
      `document.readyState === 'complete' && location.pathname === ${expectedPath}`,
    );
  } catch (error) {
    if (result.errorText) {
      throw new Error(context(`navigation failed (${result.errorText}): ${error.message}`));
    }
    throw error;
  }
  currentUrl = await evaluate('location.href');
  assertNoBrowserErrors();
};

try {
  requireValue(port && base, 'usage: chrome_xss.mjs <debug-port> <base-url>');
  requireValue(password, 'HTTP_TEST_ADMIN_PASSWORD is required');
  requireValue(typeof globalThis.WebSocket === 'function', 'global WebSocket is unavailable');

  const targetResponse = await withTimeout(
    fetch(`http://127.0.0.1:${port}/json/list`),
    commandTimeoutMs,
    'fetching Chrome /json/list',
  );
  requireValue(targetResponse.ok, `/json/list returned HTTP ${targetResponse.status}`);
  let targets;
  try {
    targets = await withTimeout(targetResponse.json(), commandTimeoutMs, 'parsing Chrome /json/list JSON');
  } catch (error) {
    throw new Error(context(`/json/list is not valid JSON: ${error.message}`));
  }
  requireValue(Array.isArray(targets) && targets.length > 0, '/json/list did not return a non-empty array');
  const pageTarget = targets.find(target => target?.type === 'page' && typeof target.webSocketDebuggerUrl === 'string');
  requireValue(pageTarget, '/json/list has no debuggable page target');
  const websocketUrl = pageTarget.webSocketDebuggerUrl;
  requireValue(/^wss?:\/\//.test(websocketUrl), '/json/list page target has no valid WebSocket debugger URL');

  ws = new WebSocket(websocketUrl);
  await withTimeout(new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, {once: true});
    ws.addEventListener('error', () => reject(new Error(context('Chrome debugger WebSocket error'))), {once: true});
  }), commandTimeoutMs, 'opening Chrome debugger WebSocket');

  ws.addEventListener('message', event => {
    let message;
    try {
      message = JSON.parse(event.data);
    } catch (error) {
      browserErrors.push(`invalid CDP JSON: ${error.message}`);
      return;
    }
    if (message.id && pending.has(message.id)) {
      const request = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) {
        request.reject(new Error(context(`CDP ${request.method}: ${message.error.message}`)));
      } else {
        request.resolve(message.result);
      }
      return;
    }
    if (message.method === 'Runtime.exceptionThrown') {
      const details = message.params.exceptionDetails;
      browserErrors.push(details.exception?.description || details.text || 'uncaught exception');
    } else if (message.method === 'Runtime.consoleAPICalled' && ['error', 'assert'].includes(message.params.type)) {
      const text = message.params.args.map(argument => argument.value ?? argument.description ?? '').join(' ');
      browserErrors.push(`console.${message.params.type}: ${text}`);
    } else if (message.method === 'Inspector.targetCrashed') {
      browserErrors.push('Chrome target crashed');
    }
  });
  ws.addEventListener('close', () => {
    if (closing) return;
    for (const request of pending.values()) request.reject(new Error(context('Chrome debugger WebSocket closed unexpectedly')));
    pending.clear();
  });

  await call('Page.enable');
  await call('Runtime.enable');
  await call('Network.enable');
  await call('Inspector.enable');

  await navigate('/index.php', 'index');
  const indexResult = await evaluate(`(() => {
    const name = ${JSON.stringify(payloadName)};
    const description = ${JSON.stringify(payloadDescription)};
    const button = document.querySelector('.add-to-cart[data-product-id="2"]');
    const safe = typeof globalThis.xssExecuted === 'undefined'
      && document.title !== 'XSS_EXECUTED'
      && !document.querySelector('[onerror*="xssExecuted"]')
      && !Array.from(document.scripts).some(script => script.textContent.includes('globalThis.xssExecuted=1'));
    const payloadIsText = document.body.textContent.includes(name) && document.body.textContent.includes(description);
    if (button) button.click();
    let cart = [];
    try { cart = JSON.parse(localStorage.getItem('cart')) || []; } catch {}
    return {buttonFound: Boolean(button), safe, payloadIsText, inCart: cart.some(item => String(item.productId) === '2')};
  })()`);
  requireValue(indexResult.buttonFound, 'required .add-to-cart[data-product-id="2"] button was not found');
  requireValue(indexResult.payloadIsText, 'index did not render both payloads as text');
  requireValue(indexResult.inCart, 'clicking the required product 2 button did not add it to the cart');
  requireValue(indexResult.safe, 'XSS payload became executable on index');
  assertNoBrowserErrors();
  console.log('B-XSS-INDEX PASS');

  await navigate('/cart.php', 'cart');
  await waitFor(
    'product 2 cart row and name payload text',
    `document.querySelectorAll('.cart-item').length > 0
      && document.body.textContent.includes(${JSON.stringify(payloadName)})`,
  );
  const cartSafe = await evaluate(`typeof globalThis.xssExecuted === 'undefined'
    && document.title !== 'XSS_EXECUTED'
    && !document.querySelector('[onerror*="xssExecuted"]')`);
  requireValue(cartSafe, 'XSS payload became executable in cart');
  assertNoBrowserErrors();
  console.log('B-XSS-CART PASS');

  await navigate('/admin_login.php', 'admin-login');
  const submitted = await evaluate(`(() => {
    const username = document.querySelector('input[name="username"]');
    const password = document.querySelector('input[name="password"]');
    const form = username?.form;
    if (!username || !password || !form) return false;
    username.value = 'http-admin';
    password.value = ${JSON.stringify(password)};
    form.requestSubmit();
    return true;
  })()`);
  requireValue(submitted, 'admin login form was not available');

  stage = 'admin-login-redirect';
  currentUrl = `${base}/admin_products.php`;
  await waitFor(
    'verified admin login navigation and admin element',
    `location.pathname === '/admin_products.php' && document.querySelector('.edit-product-btn') !== null`,
  );
  currentUrl = await evaluate('location.href');
  const cookies = (await call('Network.getCookies', {urls: [base]})).cookies;
  requireValue(
    cookies.some(cookie => cookie.name === 'PHPSESSID' && cookie.value && (cookie.session === true || cookie.expires <= 0)),
    'verified admin login did not create a session cookie',
  );
  requireValue(new URL(currentUrl).pathname === '/admin_products.php', `login navigation ended at ${currentUrl}`);
  requireValue(await evaluate(`document.querySelector('.edit-product-btn') !== null`), 'admin product element is missing after login');
  assertNoBrowserErrors();

  stage = 'admin-product-2-modal';
  const clickedAdminProduct = await evaluate(`(() => {
    for (const button of document.querySelectorAll('.edit-product-btn')) {
      try {
        const product = JSON.parse(button.dataset.product);
        if (Number(product.id) === 2) {
          button.click();
          return true;
        }
      } catch {}
    }
    return false;
  })()`);
  requireValue(clickedAdminProduct, 'required edit button whose data-product has id=2 was not found');
  await waitFor(
    'visible edit modal for product 2',
    `(() => {
      const modal = document.querySelector('#editModal');
      return modal?.classList.contains('show')
        && getComputedStyle(modal).display !== 'none'
        && document.querySelector('#edit_id')?.value === '2';
    })()`,
  );
  const modalResult = await evaluate(`(() => {
    const modal = document.querySelector('#editModal');
    return {
      visible: modal?.classList.contains('show') && getComputedStyle(modal).display !== 'none',
      nameIsText: document.querySelector('#edit_name')?.value === ${JSON.stringify(payloadName)},
      descriptionIsText: document.querySelector('#edit_description')?.value === ${JSON.stringify(payloadDescription)},
      safe: typeof globalThis.xssExecuted === 'undefined'
        && document.title !== 'XSS_EXECUTED'
        && !modal?.querySelector('[onerror*="xssExecuted"]')
        && !Array.from(document.scripts).some(script => script.textContent.includes('globalThis.xssExecuted=1')),
    };
  })()`);
  requireValue(modalResult.visible, 'product 2 edit modal is not visible');
  requireValue(modalResult.nameIsText && modalResult.descriptionIsText, 'modal did not preserve both XSS payloads as text values');
  requireValue(modalResult.safe, 'XSS payload became executable in admin modal');
  assertNoBrowserErrors();
  console.log('B-XSS-ADMIN PASS');
} catch (error) {
  console.error(context(error?.stack || error?.message || String(error)));
  process.exitCode = 1;
} finally {
  closing = true;
  if (ws) {
    for (const request of pending.values()) request.reject(new Error(context('Chrome debugger WebSocket is closing')));
    pending.clear();
    try {
      ws.close();
    } catch {}
  }
}
