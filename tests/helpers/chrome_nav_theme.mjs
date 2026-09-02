const [port, base, expectedMode = 'navy'] = process.argv.slice(2);
const commandTimeoutMs = 5_000;
const stageTimeoutMs = 12_000;

let stage = 'startup';
let currentUrl = base || '(missing base URL)';
let ws;
let nextId = 0;
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

const parseRgb = value => {
  const match = String(value).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
  if (!match) return null;
  return {r: Number(match[1]), g: Number(match[2]), b: Number(match[3])};
};

const relativeLuminance = ({r, g, b}) => {
  const channel = c => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
};

const isNearWhite = rgb => rgb && rgb.r > 240 && rgb.g > 240 && rgb.b > 240;
const isLight = rgb => rgb && relativeLuminance(rgb) >= 0.55;
const isDark = rgb => rgb && relativeLuminance(rgb) <= 0.25;

try {
  requireValue(port && base, 'usage: chrome_nav_theme.mjs <debug-port> <base-url> [navy|white]');
  requireValue(expectedMode === 'navy' || expectedMode === 'white', 'mode must be navy or white');
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
    let message;
    try {
      message = JSON.parse(event.data);
    } catch (error) {
      browserErrors.push(`invalid CDP JSON: ${error.message}`);
      return;
    }
    if (message.id && pending.has(message.id)) {
      const request = pending.get(message.id);
      if (message.error) {
        request.reject(new Error(context(`CDP ${request.method}: ${message.error.message}`)));
      } else {
        request.resolve(message.result);
      }
    }
    if (message.method === 'Runtime.exceptionThrown') {
      browserErrors.push(message.params?.exceptionDetails?.text || 'Runtime.exceptionThrown');
    }
  });

  await call('Page.enable');
  await call('Runtime.enable');

  const readNavStyles = async () => evaluate(`(() => {
    const nav = document.querySelector('nav.site-navbar');
    const link = document.querySelector('nav.site-navbar .nav-link');
    const toggler = document.querySelector('nav.site-navbar .navbar-toggler');
    if (!nav || !link) return null;
    const navStyle = getComputedStyle(nav);
    const linkStyle = getComputedStyle(link);
    const togglerStyle = toggler ? getComputedStyle(toggler) : null;
    return {
      hasNavyClass: nav.classList.contains('site-navbar-navy'),
      backgroundColor: navStyle.backgroundColor,
      linkColor: linkStyle.color,
      togglerDisplay: togglerStyle ? togglerStyle.display : null,
      togglerVisibility: togglerStyle ? togglerStyle.visibility : null,
      togglerOpacity: togglerStyle ? togglerStyle.opacity : null,
      menuOpen: !!document.querySelector('#mainNav.show, #mainNav.collapsing'),
      menuDisplay: (() => {
        const menu = document.querySelector('#mainNav');
        return menu ? getComputedStyle(menu).display : null;
      })(),
    };
  })()`);

  // Expect caller already persisted the desired nav_style via HTTP.
  await navigate('/index.php', `${expectedMode}-home`);
  await sleep(300);
  let styles = await readNavStyles();
  requireValue(styles, 'nav/link missing on public home');

  if (expectedMode === 'navy') {
    requireValue(styles.hasNavyClass, 'expected site-navbar-navy class');
    const navyBg = parseRgb(styles.backgroundColor);
    requireValue(!isNearWhite(navyBg), `navy background is white-ish: ${styles.backgroundColor}`);
    requireValue(isDark(navyBg), `navy background not dark enough: ${styles.backgroundColor}`);
    const navyLink = parseRgb(styles.linkColor);
    requireValue(isLight(navyLink), `navy link not light enough: ${styles.linkColor}`);
    console.log(`H-NAV-NAVY-COMPUTED PASS bg=${styles.backgroundColor} link=${styles.linkColor}`);

    await call('Emulation.setDeviceMetricsOverride', {
      width: 390,
      height: 844,
      deviceScaleFactor: 1,
      mobile: true,
    });
    await sleep(200);
    await evaluate(`(() => {
      const btn = document.querySelector('nav.site-navbar .navbar-toggler');
      if (btn) btn.click();
      return true;
    })()`);
    await waitFor('mobile navy menu open', `!!document.querySelector('#mainNav.show')`);
    styles = await readNavStyles();
    requireValue(styles.togglerDisplay !== 'none' && styles.togglerVisibility !== 'hidden', 'toggler not visible');
    requireValue(Number(styles.togglerOpacity || '1') > 0.2, 'toggler opacity too low');
    requireValue(styles.menuDisplay !== 'none', `mobile menu not visible: display=${styles.menuDisplay}`);
    const openLink = parseRgb(styles.linkColor);
    requireValue(isLight(openLink), `open navy menu link not light: ${styles.linkColor}`);
    console.log('H-NAV-NAVY-MOBILE PASS');
    await call('Emulation.clearDeviceMetricsOverride');
  } else {
    requireValue(!styles.hasNavyClass, 'white nav must not include site-navbar-navy');
    const whiteBg = parseRgb(styles.backgroundColor);
    requireValue(isNearWhite(whiteBg) || (whiteBg && relativeLuminance(whiteBg) >= 0.9),
      `white background not white enough: ${styles.backgroundColor}`);
    const whiteLink = parseRgb(styles.linkColor);
    requireValue(isDark(whiteLink), `white-nav link not navy/dark: ${styles.linkColor}`);
    console.log(`H-NAV-WHITE-COMPUTED PASS bg=${styles.backgroundColor} link=${styles.linkColor}`);
  }
} catch (error) {
  console.error(String(error && error.message ? error.message : error));
  process.exitCode = 1;
} finally {
  try { if (ws && ws.readyState === 1) ws.close(); } catch {}
}
