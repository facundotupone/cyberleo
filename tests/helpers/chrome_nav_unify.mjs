const [port, base, mode = 'unify'] = process.argv.slice(2);
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

const measureNav = async () => evaluate(`(() => {
  const nav = document.querySelector('nav.site-navbar[data-cyberleo-nav="public"]');
  if (!nav) return null;
  const logo = nav.querySelector('img.brand-logo');
  const link = nav.querySelector('.cyberleo-nav-link');
  const cart = nav.querySelector('.site-nav-cart, .nav-cart-btn');
  const toggler = nav.querySelector('.site-navbar-toggler, .navbar-toggler');
  const cs = getComputedStyle(nav);
  const logoCs = logo ? getComputedStyle(logo) : null;
  const linkCs = link ? getComputedStyle(link) : null;
  return {
    height: Math.round(nav.getBoundingClientRect().height),
    background: cs.backgroundColor,
    logoWidth: logo ? Math.round(logo.getBoundingClientRect().width) : 0,
    logoHeight: logo ? Math.round(logo.getBoundingClientRect().height) : 0,
    linkFont: linkCs ? linkCs.fontSize + '|' + linkCs.fontWeight + '|' + linkCs.fontFamily : '',
    linkPadding: linkCs ? linkCs.padding : '',
    cartDisplay: cart ? getComputedStyle(cart).display : '',
    togglerDisplay: toggler ? getComputedStyle(toggler).display : '',
    markup: nav.outerHTML.replace(/\\s+/g, ' ').slice(0, 400),
    activeHrefs: [...nav.querySelectorAll('[aria-current="page"]')].map(a => a.getAttribute('href')),
    footerPresent: !!document.querySelector('footer.site-footer, footer.footer'),
    footerCols: [...document.querySelectorAll('.site-footer-col')].map(col => ({
      className: col.className,
      empty: col.textContent.trim() === '',
      childCount: col.children.length,
    })),
  };
})()`);

const near = (a, b, tol = 2) => Math.abs(a - b) <= tol;

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

  if (mode === 'unify' || mode === 'desktop') {
    await call('Emulation.setDeviceMetricsOverride', {
      width: 1440, height: 900, deviceScaleFactor: 1, mobile: false,
    });

    await navigate('index.php', 'home');
    const home = await measureNav();
    requireValue(home, 'home nav missing');
    requireValue(home.footerPresent, 'home footer missing');
    requireValue(home.activeHrefs.some(h => h === 'index.php' || /index\.php$/.test(String(h))), 'home active missing');
    requireValue(home.footerCols.every(c => !c.empty && c.childCount > 0), 'home footer empty column');

    // Prefer first category link from nav.
    const categoryHref = await evaluate(`(() => {
      const a = document.querySelector('nav.site-navbar a.cyberleo-nav-link[href*="category.php"]');
      return a ? a.getAttribute('href') : null;
    })()`);
    requireValue(categoryHref, 'category link missing');
    await navigate(categoryHref, 'category');
    const category = await measureNav();
    requireValue(category, 'category nav missing');
    requireValue(category.footerPresent, 'category footer missing');
    requireValue(category.activeHrefs.some(h => String(h).includes('category.php')), 'category active missing');

    await navigate('cart.php', 'cart');
    const cart = await measureNav();
    requireValue(cart, 'cart nav missing');
    requireValue(cart.footerPresent, 'cart footer missing');
    requireValue(cart.activeHrefs.some(h => h === 'cart.php' || /cart\.php$/.test(String(h))), 'cart active missing');

    for (const key of ['height', 'background', 'logoWidth', 'logoHeight', 'linkFont', 'linkPadding', 'cartDisplay', 'togglerDisplay']) {
      requireValue(key === 'height' || key === 'logoWidth' || key === 'logoHeight'
        ? near(home[key], category[key]) && near(home[key], cart[key])
        : home[key] === category[key] && home[key] === cart[key],
        `nav style mismatch on ${key}: home=${home[key]} category=${category[key]} cart=${cart[key]}`);
    }

    // Benefits layout on home at desktop.
    await navigate('index.php', 'benefits-desktop');
    const benefits = await evaluate(`(() => {
      const section = document.getElementById('beneficios');
      if (!section) return null;
      const blocks = [...section.querySelectorAll('.benefit-block')];
      if (blocks.length < 2) return {count: blocks.length, columns: false, title: ''};
      const tops = blocks.map(b => Math.round(b.getBoundingClientRect().top));
      const sameRow = Math.abs(tops[0] - tops[1]) <= 4;
      const title = (section.querySelector('#beneficios-heading') || {}).textContent || '';
      const icon = section.querySelector('.benefit-icon i');
      const iconSize = icon ? Math.round(parseFloat(getComputedStyle(icon).fontSize)) : 0;
      return {count: blocks.length, columns: sameRow, title: title.trim(), iconSize};
    })()`);
    requireValue(benefits && benefits.count === 3, 'expected 3 benefits');
    requireValue(benefits.columns, 'benefits not in columns on desktop');
    requireValue(/CyberLeo|Beneficio|Comprar|elegir/i.test(benefits.title), 'benefits title missing');
    requireValue(benefits.iconSize >= 40 && benefits.iconSize <= 52, `icon size out of range: ${benefits.iconSize}`);
  }

  if (mode === 'mobile') {
    await call('Emulation.setDeviceMetricsOverride', {
      width: 390, height: 844, deviceScaleFactor: 2, mobile: true,
    });
    await navigate('index.php', 'mobile-home');
    const closed = await evaluate(`(() => {
      const nav = document.querySelector('nav.site-navbar');
      const toggler = nav && nav.querySelector('.navbar-toggler');
      const collapse = nav && nav.querySelector('#mainNav');
      return {
        togglerVisible: !!(toggler && getComputedStyle(toggler).display !== 'none'),
        expanded: toggler ? toggler.getAttribute('aria-expanded') : null,
        open: collapse ? collapse.classList.contains('show') : null,
        height: nav ? Math.round(nav.getBoundingClientRect().height) : 0,
      };
    })()`);
    requireValue(closed && closed.togglerVisible, 'mobile toggler hidden');
    requireValue(closed.expanded === 'false', 'toggler should start collapsed');

    await evaluate(`(() => {
      const btn = document.querySelector('nav.site-navbar .navbar-toggler');
      if (btn) btn.click();
      return true;
    })()`);
    await waitFor('mobile menu open', `document.querySelector('#mainNav')?.classList.contains('show')`);
    const open = await evaluate(`(() => {
      const toggler = document.querySelector('nav.site-navbar .navbar-toggler');
      return toggler ? toggler.getAttribute('aria-expanded') : null;
    })()`);
    requireValue(open === 'true', 'aria-expanded should be true when open');

    const benefits = await evaluate(`(() => {
      const section = document.getElementById('beneficios');
      if (!section) return null;
      const blocks = [...section.querySelectorAll('.benefit-block')];
      const stacked = blocks.every((b, i, arr) => i === 0 || b.getBoundingClientRect().top >= arr[i-1].getBoundingClientRect().bottom - 2);
      const overflow = document.documentElement.scrollWidth > window.innerWidth + 1;
      return {count: blocks.length, stacked, overflow};
    })()`);
    requireValue(benefits && benefits.count === 3 && benefits.stacked, 'benefits not stacked on mobile');
    requireValue(!benefits.overflow, 'horizontal overflow on mobile');

    const footer = await evaluate(`(() => {
      const f = document.querySelector('footer.site-footer, footer.footer');
      if (!f) return null;
      const cols = [...f.querySelectorAll('.site-footer-col')];
      return {
        present: true,
        emptyCols: cols.filter(c => c.textContent.trim() === '').length,
        navy: getComputedStyle(f).backgroundColor,
      };
    })()`);
    requireValue(footer && footer.present, 'mobile footer missing');
    requireValue(footer.emptyCols === 0, 'mobile footer empty columns');
  }

  if (mode === 'footer-toggles') {
    await call('Emulation.setDeviceMetricsOverride', {
      width: 1440, height: 900, deviceScaleFactor: 1, mobile: false,
    });
    await navigate('index.php', 'footer-toggles');
    const footer = await evaluate(`(() => {
      const f = document.querySelector('footer.site-footer, footer.footer');
      if (!f) return null;
      const cols = [...f.querySelectorAll('.site-footer-col')];
      const hasContact = !!f.querySelector('.site-footer-contact');
      const hasBrand = !!f.querySelector('.site-footer-brand');
      const hasNav = !!f.querySelector('.site-footer-nav');
      return {
        colCount: cols.length,
        emptyCols: cols.filter(c => c.textContent.trim() === '').length,
        hasContact,
        hasBrand,
        hasNav,
        text: f.textContent,
      };
    })()`);
    requireValue(footer, 'footer missing');
    requireValue(footer.emptyCols === 0, 'footer empty columns with toggles off');
    requireValue(footer.hasNav, 'footer nav column required');
    // When IG/WA/hours/location off, contact column should be absent.
    requireValue(!footer.hasContact, 'contact column should be hidden when toggles off');
  }

  assertNoBrowserErrors();
  console.log(JSON.stringify({ok: true, mode, browserErrors: 0}, null, 2));
  ws.close();
  process.exit(0);
} catch (error) {
  console.error(error.stack || error.message || error);
  try { ws && ws.close(); } catch (_) {}
  process.exit(1);
}
