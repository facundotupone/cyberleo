const [port, base, mode = 'alt'] = process.argv.slice(2);
const commandTimeoutMs = 5_000;
const stageTimeoutMs = 12_000;

let stage = 'startup';
let currentUrl = base || '(missing base URL)';
let ws;
let nextId = 0;
const pending = new Map();

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

const containsChannel = (cssValue, channel) => {
  const normalized = String(cssValue).replace(/\s+/g, ' ');
  const compact = channel.replace(/\s+/g, ' ');
  return normalized.includes(compact);
};

try {
  requireValue(port && base, 'usage: chrome_hero_theme.mjs <debug-port> <base-url> [alt|restore|overlay]');
  requireValue(['alt', 'restore', 'overlay'].includes(mode), 'mode must be alt|restore|overlay');
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
      if (message.error) {
        request.reject(new Error(context(`CDP ${request.method}: ${message.error.message}`)));
      } else {
        request.resolve(message.result);
      }
    }
  });

  await call('Page.enable');
  await call('Runtime.enable');
  await navigate('/index.php', `hero-${mode}`);
  await sleep(1100);

  const info = await evaluate(`(() => {
    const hero = document.querySelector('.hero-section');
    if (!hero) return null;
    const style = getComputedStyle(hero);
    const after = getComputedStyle(hero, '::after');
    const root = getComputedStyle(document.documentElement);
    return {
      backgroundImage: style.backgroundImage,
      backgroundColor: style.backgroundColor,
      hasImage: hero.classList.contains('hero-has-image'),
      overlay: after.backgroundColor,
      navyRgb: root.getPropertyValue('--brand-navy-rgb').trim(),
      blueRgb: root.getPropertyValue('--brand-blue-rgb').trim(),
      cyanRgb: root.getPropertyValue('--brand-cyan-rgb').trim(),
      title: document.querySelector('.hero-section h1')?.textContent?.trim() || '',
      subtitle: document.querySelector('.hero-section .hero-subtitle')?.textContent?.trim() || '',
    };
  })()`);
  requireValue(info, 'hero section missing');

  if (mode === 'alt') {
    requireValue(containsChannel(info.backgroundImage, '122, 31, 31')
      || containsChannel(info.backgroundImage, '122,31,31'), 'missing alternate primary RGB in hero gradient');
    requireValue(containsChannel(info.backgroundImage, '27, 16, 48')
      || containsChannel(info.backgroundImage, '27,16,48'), 'missing alternate navy RGB in hero gradient');
    requireValue(containsChannel(info.backgroundImage, '196, 92, 38')
      || containsChannel(info.backgroundImage, '196,92,38'), 'missing alternate secondary RGB in hero gradient');
    requireValue(!containsChannel(info.backgroundImage, '0, 87, 184')
      && !containsChannel(info.backgroundImage, '0,87,184'), 'default primary RGB still present');
    requireValue(!containsChannel(info.backgroundImage, '7, 26, 51')
      && !containsChannel(info.backgroundImage, '7,26,51'), 'default navy RGB still present');
    requireValue(info.navyRgb === '27, 16, 48', `unexpected --brand-navy-rgb: ${info.navyRgb}`);
    console.log('H-HERO-THEME-ALT PASS');
  } else if (mode === 'restore') {
    requireValue(containsChannel(info.backgroundImage, '0, 87, 184')
      || containsChannel(info.backgroundImage, '0,87,184'), 'default primary RGB missing after restore');
    requireValue(containsChannel(info.backgroundImage, '7, 26, 51')
      || containsChannel(info.backgroundImage, '7,26,51'), 'default navy RGB missing after restore');
    requireValue(info.navyRgb === '7, 26, 51', `unexpected restored navy rgb: ${info.navyRgb}`);
    requireValue(info.blueRgb === '0, 87, 184', `unexpected restored blue rgb: ${info.blueRgb}`);
    console.log(JSON.stringify({
      pass: 'H-HERO-THEME-RESTORE',
      title: info.title,
      subtitle: info.subtitle,
    }));
    console.log('H-HERO-THEME-RESTORE PASS');
  } else {
    requireValue(info.hasImage, 'hero image class missing');
    requireValue(containsChannel(info.overlay, '27, 16, 48')
      || containsChannel(info.overlay, '27,16,48'), `overlay not using alternate navy: ${info.overlay}`);
    requireValue(!containsChannel(info.overlay, '7, 26, 51')
      && !containsChannel(info.overlay, '7,26,51'), `overlay still uses default navy: ${info.overlay}`);
    console.log(`H-HERO-OVERLAY-ALT PASS overlay=${info.overlay}`);
  }
} catch (error) {
  console.error(String(error && error.message ? error.message : error));
  process.exitCode = 1;
} finally {
  try { if (ws && ws.readyState === 1) ws.close(); } catch {}
}
