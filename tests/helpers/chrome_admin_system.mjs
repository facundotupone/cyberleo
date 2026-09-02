const [port, base, mode = 'desktop'] = process.argv.slice(2);
const adminPassword = process.env.HTTP_TEST_ADMIN_PASSWORD || '';

let stage = 'startup';
let currentUrl = base || '(missing)';
let ws;
let nextId = 0;
const pending = new Map();
const browserErrors = [];

const sleep = ms => new Promise(r => setTimeout(r, ms));
const context = message => `${message} [mode=${mode} stage=${stage} url=${currentUrl}]`;
const requireValue = (condition, message) => {
  if (!condition) throw new Error(context(message));
};
const withTimeout = (promise, ms, description) => {
  let timer;
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(context(`timeout: ${description}`))), ms);
    }),
  ]).finally(() => clearTimeout(timer));
};

const call = (method, params = {}) => {
  const id = ++nextId;
  const payload = {id, method, params};
  return withTimeout(new Promise((resolve, reject) => {
    pending.set(id, {resolve, reject});
    ws.send(JSON.stringify(payload));
  }), 5000, method);
};

const evaluate = expression => call('Runtime.evaluate', {
  expression,
  returnByValue: true,
  awaitPromise: true,
}).then(result => {
  if (result.exceptionDetails) {
    throw new Error(context(result.exceptionDetails.text || 'evaluate failed'));
  }
  return result.result?.value;
});

const waitFor = async (label, expression, timeoutMs = 15000) => {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (await evaluate(expression)) return;
    await sleep(100);
  }
  throw new Error(context(`waitFor failed: ${label}`));
};

const navigate = async (path, label) => {
  stage = label;
  currentUrl = `${base.replace(/\/$/, '')}/${path.replace(/^\//, '')}`;
  await call('Page.navigate', {url: currentUrl});
  await waitFor('ready', 'document.readyState === "complete" || document.readyState === "interactive"', 20000);
};

const setViewport = async (width, height) => {
  await call('Emulation.setDeviceMetricsOverride', {
    width, height, deviceScaleFactor: 1, mobile: width < 800,
  });
};

const main = async () => {
  requireValue(!!adminPassword, 'HTTP_TEST_ADMIN_PASSWORD required');
  const list = await fetch(`http://127.0.0.1:${port}/json/list`).then(r => r.json());
  const page = list.find(p => p.type === 'page') || list[0];
  requireValue(page?.webSocketDebuggerUrl, 'no CDP page');
  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    ws.onopen = resolve;
    ws.onerror = reject;
  });
  ws.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
      const {resolve, reject} = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) reject(new Error(message.error.message || 'cdp error'));
      else resolve(message.result || {});
    }
    if (message.method === 'Runtime.exceptionThrown') {
      browserErrors.push({type: 'exception', message: message.params?.exceptionDetails?.text || 'ex'});
    }
    if (message.method === 'Runtime.consoleAPICalled' && ['error', 'assert'].includes(message.params?.type)) {
      const parts = (message.params?.args || []).map(a => a.value || a.description || '');
      browserErrors.push({type: 'console', message: parts.join(' ')});
    }
  };

  await call('Runtime.enable');
  await call('Page.enable');
  if (mode === 'mobile') await setViewport(390, 844);
  else await setViewport(1440, 900);

  await navigate('admin_login.php', 'login');
  const submitted = await evaluate(`(() => {
    const u = document.querySelector('input[name="username"]');
    const p = document.querySelector('input[name="password"]');
    const form = u && u.form;
    if (!u || !p || !form) return false;
    u.value = 'http-admin';
    p.value = ${JSON.stringify(adminPassword)};
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
    return true;
  })()`);
  requireValue(submitted, 'login form missing');
  await waitFor('admin', `location.pathname.includes('admin_products.php')`, 20000);

  await navigate('admin_system.php', 'system');
  await waitFor('system heading', `!!document.querySelector('h1')`);
  const probe = await evaluate(`(() => {
    const body = document.body.innerText || '';
    return {
      title: document.querySelector('h1')?.textContent || '',
      hasPass: body.includes('PASS'),
      hasTable: !!document.querySelector('table'),
      hasSecretWord: /APP_SECRET\\s*=|DB_PASS\\s*=|password-segura|Stack trace|SQLSTATE|\\/workspace\\//i.test(body),
      link: !!document.querySelector('a[href="admin_system.php"]'),
      width: window.innerWidth,
    };
  })()`);
  requireValue(/Sistema|Estado/i.test(probe.title), `title=${probe.title}`);
  requireValue(probe.hasPass && probe.hasTable, 'system checks missing');
  requireValue(!probe.hasSecretWord, 'secrets/paths leaked in admin_system');
  if (mode === 'mobile') requireValue(probe.width === 390, `width=${probe.width}`);

  console.log(JSON.stringify({mode, browserErrors: browserErrors.length, probe}, null, 2));
  requireValue(browserErrors.length === 0, `browserErrors=${browserErrors.length}`);
  ws.close();
};

main().catch(err => {
  console.error(err);
  process.exit(1);
});
