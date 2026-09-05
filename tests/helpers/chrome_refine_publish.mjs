const [port, base, mode = 'desktop'] = process.argv.slice(2);
const commandTimeoutMs = 5_000;
const stageTimeoutMs = 12_000;

let stage = 'startup';
let currentUrl = base || '(missing base URL)';
let ws;
let nextId = 0;
const pending = new Map();
const browserErrors = [];

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
  await sleep(250);
};

const assertNoBrowserErrors = () => {
  const relevant = browserErrors.filter(message => {
    const text = String(message);
    if (/favicon\.ico/i.test(text)) return false;
    if (/Failed to load resource:.*404/i.test(text)) return false;
    return true;
  });
  if (relevant.length) {
    throw new Error(context(`browserErrors=${relevant.length}: ${relevant.join(' | ')}`));
  }
};

const parseRgb = value => {
  const m = String(value).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
  if (!m) return null;
  return {r: Number(m[1]), g: Number(m[2]), b: Number(m[3])};
};

try {
  requireValue(port && base, 'port/base required');
  const list = await withTimeout(
    fetch(`http://127.0.0.1:${port}/json/list`).then(r => r.json()),
    commandTimeoutMs,
    'chrome list',
  );
  const target = list.find(item => item.type === 'page') || list[0];
  requireValue(target && target.webSocketDebuggerUrl, 'missing chrome target');
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await withTimeout(new Promise((resolve, reject) => {
    ws.onopen = resolve;
    ws.onerror = reject;
  }), commandTimeoutMs, 'ws open');

  ws.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown') {
      const details = message.params?.exceptionDetails || {};
      const text = details.exception?.description || details.text || 'Runtime.exceptionThrown';
      browserErrors.push(String(text));
    }
    if (message.method === 'Runtime.consoleAPICalled') {
      const type = message.params?.type;
      if (type === 'error' || type === 'assert') {
        const args = (message.params?.args || [])
          .map(arg => arg.value ?? arg.description ?? arg.type)
          .join(' ');
        browserErrors.push(String(args || 'console error'));
      }
    }
    if (message.id && pending.has(message.id)) {
      const entry = pending.get(message.id);
      if (message.error) entry.reject(new Error(message.error.message || 'cdp error'));
      else entry.resolve(message.result);
    }
  };

  await call('Runtime.enable');
  await call('Page.enable');

  const isMobile = mode === 'mobile';
  await call('Emulation.setDeviceMetricsOverride', {
    width: isMobile ? 390 : 1440,
    height: isMobile ? 844 : 900,
    deviceScaleFactor: isMobile ? 2 : 1,
    mobile: isMobile,
  });

  await navigate('index.php', 'home');

  const probe = await evaluate(`(() => {
    const hero = document.querySelector('.hero-section');
    const footer = document.querySelector('footer');
    const block = document.querySelector('.benefit-block');
    const icon = document.querySelector('.benefit-icon i, .benefit-icon');
    const styleLink = document.querySelector('link[href*="assets/css/style.css"]');
    const bodyCs = getComputedStyle(document.body);
    const heroCs = hero ? getComputedStyle(hero) : null;
    const footerCs = footer ? getComputedStyle(footer) : null;
    const blockCs = block ? getComputedStyle(block) : null;
    const iconCs = icon ? getComputedStyle(icon) : null;
    const padTop = blockCs ? parseFloat(blockCs.paddingTop) : 0;
    const padRight = blockCs ? parseFloat(blockCs.paddingRight) : 0;
    const padBottom = blockCs ? parseFloat(blockCs.paddingBottom) : 0;
    const padLeft = blockCs ? parseFloat(blockCs.paddingLeft) : 0;
    return {
      release: document.querySelector('meta[name="cyberleo-release"]')?.content || '',
      styleHref: styleLink ? styleLink.getAttribute('href') : '',
      bodyBg: bodyCs.backgroundColor,
      heroHeight: hero ? Math.round(hero.getBoundingClientRect().height) : 0,
      footerTag: footer ? footer.tagName.toLowerCase() : '',
      footerClass: footer ? footer.className : '',
      footerBg: footerCs ? footerCs.backgroundColor : '',
      hasFooterBanner: !!document.querySelector('.footer-banner'),
      hasSiteFooterGrid: !!document.querySelector('.site-footer-grid'),
      benefitBg: blockCs ? blockCs.backgroundColor : '',
      benefitBorder: blockCs ? blockCs.borderTopWidth : '',
      benefitPadMin: Math.min(padTop, padRight, padBottom, padLeft),
      iconFont: iconCs ? parseFloat(iconCs.fontSize) : 0,
      emptyFooterCols: [...document.querySelectorAll('.site-footer-col')].filter(c => c.textContent.trim() === '').length,
    };
  })()`);

  requireValue(probe.release.includes('refinamiento'), `missing release meta: ${probe.release}`);
  requireValue(/style\.css\?v=/.test(String(probe.styleHref)), `style.css missing ?v=: ${probe.styleHref}`);
  requireValue(probe.hasSiteFooterGrid, 'missing .site-footer-grid');
  requireValue(!probe.hasFooterBanner, 'legacy .footer-banner still present');
  requireValue(String(probe.footerClass).includes('site-footer'), `footer missing site-footer: ${probe.footerClass}`);

  const footerRgb = parseRgb(probe.footerBg);
  requireValue(!!footerRgb, `footer bg unparsed: ${probe.footerBg}`);
  // Navy ~ rgb(7, 26, 51)
  requireValue(footerRgb.r < 40 && footerRgb.g < 50 && footerRgb.b < 90, `footer not navy: ${probe.footerBg}`);

  const benefitRgb = parseRgb(probe.benefitBg);
  requireValue(!!benefitRgb, `benefit bg unparsed: ${probe.benefitBg}`);
  requireValue(!/transparent|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i.test(String(probe.benefitBg)),
    `benefit transparent: ${probe.benefitBg}`);
  requireValue(benefitRgb.r > 240 && benefitRgb.g > 240 && benefitRgb.b > 240, `benefit not white-ish: ${probe.benefitBg}`);
  requireValue(probe.benefitPadMin >= 20, `benefit padding < 20px: ${probe.benefitPadMin}`);
  requireValue(probe.iconFont >= 40 && probe.iconFont <= 48.5, `icon size out of range: ${probe.iconFont}`);
  requireValue(probe.emptyFooterCols === 0, `empty footer cols: ${probe.emptyFooterCols}`);

  if (!isMobile) {
    requireValue(probe.heroHeight > 0 && probe.heroHeight < 500, `desktop hero too tall: ${probe.heroHeight}`);
  } else {
    requireValue(probe.heroHeight >= 280 && probe.heroHeight <= 380, `mobile hero out of range: ${probe.heroHeight}`);
  }

  assertNoBrowserErrors();
  console.log(JSON.stringify({ok: true, mode, browserErrors: 0, probe}, null, 2));
  ws.close();
  process.exit(0);
} catch (error) {
  console.error(String(error && error.stack ? error.stack : error));
  process.exit(1);
}
