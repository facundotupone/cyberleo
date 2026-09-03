const [port, base, mode = 'default'] = process.argv.slice(2);
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
};

const sectionOrder = async () => evaluate(`(() => {
  const nodes = [...document.querySelectorAll('#productos-destacados, #promo-banner, #categorias, #beneficios')];
  return nodes.map(node => {
    if (node.id === 'productos-destacados') return 'featured';
    if (node.id === 'promo-banner') return 'promo';
    if (node.id === 'categorias') return 'categories';
    if (node.id === 'beneficios') return 'benefits';
    return node.id;
  });
})()`);

const assertNoBrowserErrors = () => {
  if (browserErrors.length === 0) return;
  const detail = browserErrors.map(entry => entry.message).join(' | ');
  throw new Error(context(`browser error: ${detail}`));
};

try {
  requireValue(port && base, 'usage: chrome_home_content.mjs <debug-port> <base-url> <mode>');
  requireValue(
    ['default', 'alt-order', 'hidden', 'banner', 'benefits', 'footer', 'mobile', 'restore', 'search-hidden'].includes(mode),
    'invalid mode',
  );
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
    if (message.method === 'Runtime.exceptionThrown') {
      const details = message.params?.exceptionDetails || {};
      const text = details.exception?.description || details.text || 'Runtime.exceptionThrown';
      browserErrors.push({
        type: 'exception',
        message: String(text),
        stage,
        url: currentUrl,
      });
    }
    if (message.method === 'Runtime.consoleAPICalled') {
      const type = message.params?.type;
      if (type === 'error' || type === 'assert') {
        const args = (message.params?.args || [])
          .map(arg => arg.value ?? arg.description ?? arg.type)
          .join(' ');
        browserErrors.push({
          type: `console.${type}`,
          message: String(args || 'console error'),
          stage,
          url: currentUrl,
        });
      }
    }
  });

  await call('Page.enable');
  await call('Runtime.enable');

  if (mode === 'mobile') {
    await call('Emulation.setDeviceMetricsOverride', {
      width: 390,
      height: 844,
      deviceScaleFactor: 2,
      mobile: true,
    });
  } else {
    await call('Emulation.setDeviceMetricsOverride', {
      width: mode === 'search-hidden' ? 1440 : 1280,
      height: 900,
      deviceScaleFactor: 1,
      mobile: false,
    });
  }

  await navigate('/index.php', 'home');
  await sleep(1000);
  assertNoBrowserErrors();

  const hasOverflow = await evaluate('document.documentElement.scrollWidth > document.documentElement.clientWidth + 1');
  requireValue(!hasOverflow, 'horizontal overflow detected');

  if (mode === 'default' || mode === 'restore') {
    const order = await sectionOrder();
    // promo disabled by default → featured, categories, benefits
    requireValue(order.includes('featured'), 'missing featured');
    requireValue(order.includes('categories'), 'missing categories');
    requireValue(order.includes('benefits'), 'missing benefits');
    requireValue(!order.includes('promo'), 'promo should be hidden when disabled');
    const featuredIdx = order.indexOf('featured');
    const categoriesIdx = order.indexOf('categories');
    const benefitsIdx = order.indexOf('benefits');
    requireValue(featuredIdx < categoriesIdx && categoriesIdx < benefitsIdx, `unexpected default visible order: ${order.join(',')}`);
    const announcement = await evaluate('document.getElementById("site-announcement")');
    requireValue(!announcement, 'announcement should be absent when disabled');
  }

  if (mode === 'alt-order') {
    const order = await sectionOrder();
    requireValue(order.join(',') === 'benefits,categories,featured,promo', `alt order got ${order.join(',')}`);
  }

  if (mode === 'hidden') {
    const order = await sectionOrder();
    requireValue(!order.includes('featured'), 'featured should be hidden');
    requireValue(!order.includes('categories'), 'categories should be hidden');
    requireValue(order.includes('benefits'), 'benefits should remain');
  }

  if (mode === 'search-hidden') {
    const state = await evaluate(`(() => {
      const hero = document.querySelector('.hero-section');
      const cart = document.querySelector('.floating-cart');
      const sections = [...document.querySelectorAll('#productos-destacados, #promo-banner, #categorias, #beneficios')];
      const visibleSection = sections.some(el => {
        const cs = getComputedStyle(el);
        const box = el.getBoundingClientRect();
        return cs.display !== 'none' && box.height > 20;
      });
      const heroVisible = !!hero && getComputedStyle(hero).display !== 'none' && hero.getBoundingClientRect().height > 40;
      const cartVisible = !!cart && getComputedStyle(cart).display !== 'none';
      return {
        hasSearchProducts: !!document.getElementById('searchProducts'),
        hasSearchResults: !!document.getElementById('searchResults'),
        ready: document.readyState === 'complete',
        heroVisible,
        visibleSection,
        cartVisible,
      };
    })()`);
    requireValue(state.ready, 'document not complete');
    requireValue(!state.hasSearchProducts, '#searchProducts should be absent');
    requireValue(!state.hasSearchResults, '#searchResults should be absent');
    requireValue(state.heroVisible, 'hero should be visible');
    requireValue(state.visibleSection, 'expected at least one content section');
    requireValue(state.cartVisible, 'floating cart should be visible');
  }

  if (mode === 'banner') {
    const promo = await evaluate(`(() => {
      const el = document.getElementById('promo-banner');
      if (!el) return null;
      const title = el.querySelector('.promo-banner-title');
      const cta = el.querySelector('.promo-banner-cta');
      const cs = getComputedStyle(el);
      return {
        title: title ? title.textContent.trim() : '',
        cta: cta ? cta.textContent.trim() : '',
        visible: cs.display !== 'none' && el.getBoundingClientRect().height > 40,
      };
    })()`);
    requireValue(promo && promo.visible, 'promo banner not visible');
    requireValue(promo.title.includes('Promo Stage2'), 'promo title missing');
    requireValue(promo.cta.includes('Ver oferta'), 'promo CTA missing');
    const announcement = await evaluate(`(() => {
      const el = document.getElementById('site-announcement');
      if (!el || el.hidden) return null;
      return el.textContent.trim();
    })()`);
    requireValue(announcement && announcement.includes('Aviso Stage2'), 'announcement missing');
  }

  if (mode === 'benefits' || mode === 'mobile') {
    const benefits = await evaluate(`(() => {
      const section = document.getElementById('beneficios');
      if (!section) return null;
      const blocks = [...section.querySelectorAll('.benefit-block')];
      return {
        count: blocks.length,
        titles: blocks.map(b => (b.querySelector('.benefit-title') || {}).textContent || '').map(t => String(t).trim()),
        stacked: window.innerWidth <= 400
          ? blocks.every((b, i, arr) => i === 0 || b.getBoundingClientRect().top >= arr[i-1].getBoundingClientRect().bottom - 2)
          : true,
      };
    })()`);
    requireValue(benefits && benefits.count === 3, 'expected 3 benefit blocks');
    requireValue(benefits.titles.some(t => t.includes('Envíos')), 'benefit titles missing');
    requireValue(benefits.stacked, 'benefits not stacked on mobile');
  }

  if (mode === 'footer' || mode === 'restore') {
    const footer = await evaluate(`(() => {
      const f = document.querySelector('footer.footer');
      if (!f) return null;
      return {
        text: f.textContent,
        hasHours: /Lunes a Viernes/.test(f.textContent),
        hasLocation: /Buenos Aires/.test(f.textContent),
        hasIg: /Instagram Stage2|Seguinos en Instagram/.test(f.textContent),
      };
    })()`);
    requireValue(footer, 'footer missing');
    if (mode === 'footer') {
      requireValue(footer.hasHours, 'business hours missing');
      requireValue(footer.hasLocation, 'location missing');
      requireValue(/Footer Stage2/.test(footer.text), 'footer description missing');
    }
    if (mode === 'restore') {
      requireValue(!footer.hasHours, 'hours should be gone after restore');
      requireValue(/Tecnología, periféricos/.test(footer.text), 'default footer description missing');
    }
  }

  assertNoBrowserErrors();
  console.log(JSON.stringify({ok: true, mode, order: await sectionOrder(), browserErrors: 0}, null, 2));
  ws.close();
  process.exit(0);
} catch (error) {
  console.error(error && error.stack ? error.stack : String(error));
  try { if (ws) ws.close(); } catch (_) {}
  process.exit(1);
}
