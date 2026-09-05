const [port, base, mode = 'desktop', scenario = 'home'] = process.argv.slice(2);
const commandTimeoutMs = 5_000;
const stageTimeoutMs = 12_000;
const adminPassword = process.env.HTTP_TEST_ADMIN_PASSWORD || '';

let stage = 'startup';
let currentUrl = base || '(missing base URL)';
let ws;
let nextId = 0;
const pending = new Map();
const browserErrors = [];

const context = message => `${message} [mode=${mode} scenario=${scenario} stage=${stage} url=${currentUrl}]`;
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

const assertNoHorizontalOverflow = async () => {
  const overflow = await evaluate(`(() => {
    const doc = document.documentElement;
    return {
      scrollWidth: doc.scrollWidth,
      clientWidth: doc.clientWidth,
      overflow: doc.scrollWidth > doc.clientWidth + 1,
    };
  })()`);
  requireValue(!overflow.overflow, `horizontal overflow: scroll=${overflow.scrollWidth} client=${overflow.clientWidth}`);
};

const assertVersionedAssets = async ({expectCatalogJs = false, expectCartJs = false} = {}) => {
  const assets = await evaluate(`(() => {
    const hrefs = [...document.querySelectorAll('link[rel="stylesheet"]')].map(el => el.getAttribute('href') || '');
    const scripts = [...document.querySelectorAll('script[src]')].map(el => el.getAttribute('src') || '');
    return {hrefs, scripts};
  })()`);
  const style = assets.hrefs.find(h => h.includes('assets/css/style.css'));
  requireValue(!!style, 'missing style.css link');
  requireValue(/assets\/css\/style\.css\?v=[a-zA-Z0-9]+/.test(style), `style.css missing safe ?v=: ${style}`);
  requireValue(!/\?v=.*\?/.test(style), `double query on style.css: ${style}`);
  const cdnBootstrap = assets.hrefs.some(h => /cdn\.jsdelivr.*bootstrap.*\.css/.test(h));
  requireValue(cdnBootstrap, 'bootstrap CDN css missing');
  requireValue(!assets.hrefs.some(h => /cdn\.jsdelivr.*bootstrap.*\.css\?v=/.test(h)), 'bootstrap CDN must not be versioned');
  if (expectCatalogJs) {
    const js = assets.scripts.find(s => s.includes('catalog-cards.js'));
    requireValue(!!js && /catalog-cards\.js\?v=[a-zA-Z0-9]+/.test(js), `catalog-cards.js missing ?v=: ${js}`);
  }
  if (expectCartJs) {
    const js = assets.scripts.find(s => s.includes('cart-checkout.js'));
    requireValue(!!js && /cart-checkout\.js\?v=[a-zA-Z0-9]+/.test(js), `cart-checkout.js missing ?v=: ${js}`);
  }
};

const assertFooterNavy = async () => {
  const probe = await evaluate(`(() => {
    const footer = document.querySelector('footer.site-footer, footer.footer, footer');
    if (!footer) return null;
    const cs = getComputedStyle(footer);
    return {
      className: footer.className,
      bg: cs.backgroundColor,
      hasBanner: !!document.querySelector('.footer-banner'),
      emptyCols: [...document.querySelectorAll('.site-footer-col')].filter(c => c.textContent.trim() === '').length,
      hasGrid: !!document.querySelector('.site-footer-grid'),
    };
  })()`);
  requireValue(!!probe, 'footer missing');
  requireValue(String(probe.className).includes('site-footer'), `footer missing site-footer: ${probe.className}`);
  requireValue(!probe.hasBanner, 'legacy .footer-banner still present');
  requireValue(probe.hasGrid, 'missing .site-footer-grid');
  requireValue(probe.emptyCols === 0, `empty footer cols: ${probe.emptyCols}`);
  const rgb = parseRgb(probe.bg);
  requireValue(!!rgb, `footer bg unparsed: ${probe.bg}`);
  // Exact target rgb(7, 26, 51) with tiny tolerance for color-mix/gamma.
  requireValue(
    Math.abs(rgb.r - 7) <= 2 && Math.abs(rgb.g - 26) <= 2 && Math.abs(rgb.b - 51) <= 2,
    `footer not rgb(7,26,51): ${probe.bg}`,
  );
  return probe;
};

const assertBenefitsOn = async () => {
  const probe = await evaluate(`(() => {
    const block = document.querySelector('.benefit-block');
    const icon = document.querySelector('.benefit-icon i, .benefit-icon');
    if (!block) return null;
    const blockCs = getComputedStyle(block);
    const iconCs = icon ? getComputedStyle(icon) : null;
    return {
      bg: blockCs.backgroundColor,
      padTop: parseFloat(blockCs.paddingTop),
      padRight: parseFloat(blockCs.paddingRight),
      padBottom: parseFloat(blockCs.paddingBottom),
      padLeft: parseFloat(blockCs.paddingLeft),
      iconFont: iconCs ? parseFloat(iconCs.fontSize) : 0,
      sectionPresent: !!document.querySelector('.benefits-section, .benefits-surface'),
    };
  })()`);
  requireValue(!!probe, 'benefit-block missing while benefits expected on');
  requireValue(probe.sectionPresent, 'benefits section missing');
  requireValue(!/transparent|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i.test(String(probe.bg)), `benefit transparent: ${probe.bg}`);
  const rgb = parseRgb(probe.bg);
  requireValue(!!rgb && rgb.r > 240 && rgb.g > 240 && rgb.b > 240, `benefit not white-ish: ${probe.bg}`);
  for (const [name, value] of Object.entries({
    padTop: probe.padTop,
    padRight: probe.padRight,
    padBottom: probe.padBottom,
    padLeft: probe.padLeft,
  })) {
    requireValue(Math.abs(value - 22) <= 0.5, `benefit ${name} != 22px: ${value}`);
  }
  requireValue(probe.iconFont >= 42 && probe.iconFont <= 48.5, `icon size < 42px: ${probe.iconFont}`);
  return probe;
};

const assertBenefitsOff = async () => {
  const present = await evaluate(`!!document.querySelector('.benefits-section, .benefit-block')`);
  requireValue(!present, 'benefits still visible while disabled');
};

const assertHeroCaps = async isMobile => {
  const height = await evaluate(`(() => {
    const hero = document.querySelector('.hero-section');
    return hero ? Math.round(hero.getBoundingClientRect().height) : 0;
  })()`);
  requireValue(height > 0, 'hero missing');
  if (isMobile) {
    requireValue(height <= 350, `mobile hero > 350px: ${height}`);
    requireValue(height >= 250, `mobile hero too short: ${height}`);
  } else {
    requireValue(height <= 480, `desktop hero > 480px: ${height}`);
    requireValue(height >= 300, `desktop hero too short: ${height}`);
  }
  return height;
};

const assertProductMediaCap = async () => {
  const media = await evaluate(`(() => {
    const el = document.querySelector('.product-card .product-media, .card-img-container');
    if (!el) return null;
    const rect = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return {
      height: Math.round(rect.height),
      maxHeight: cs.maxHeight,
    };
  })()`);
  if (!media) return null;
  requireValue(media.height <= 280, `product media > 280px: ${media.height}`);
  return media;
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

  const summary = {ok: true, mode, scenario, browserErrors: 0, pages: {}};

  const runHome = async ({expectBenefits}) => {
    await navigate('index.php', 'home');
    const release = await evaluate(`document.querySelector('meta[name="cyberleo-release"]')?.content || ''`);
    requireValue(release.includes('refinamiento'), `missing release meta: ${release}`);
    await assertVersionedAssets({expectCatalogJs: true});
    const footer = await assertFooterNavy();
    let benefits = null;
    let heroHeight = null;
    let productMedia = null;
    if (expectBenefits) {
      benefits = await assertBenefitsOn();
      heroHeight = await assertHeroCaps(isMobile);
      productMedia = await assertProductMediaCap();
    } else {
      await assertBenefitsOff();
      heroHeight = await assertHeroCaps(isMobile);
    }
    await assertNoHorizontalOverflow();
    summary.pages.home = {footerBg: footer.bg, benefits, heroHeight, productMedia, release};
  };

  if (scenario === 'home' || scenario === 'benefits-on') {
    await runHome({expectBenefits: true});
  } else if (scenario === 'benefits-off') {
    await runHome({expectBenefits: false});
  } else if (scenario === 'footer-full' || scenario === 'footer-logo-only' || scenario === 'footer-toggles-off') {
    await runHome({expectBenefits: true});
  } else if (scenario === 'category') {
    await navigate('index.php', 'home-for-category');
    const categoryHref = await evaluate(`(() => {
      const link = [...document.querySelectorAll('a[href*="category.php"]')].find(a => /category\\.php\\?id=\\d+/.test(a.getAttribute('href') || ''));
      return link ? link.getAttribute('href') : 'category.php?id=1';
    })()`);
    await navigate(categoryHref, 'category');
    await assertVersionedAssets({expectCatalogJs: true});
    await assertFooterNavy();
    await assertProductMediaCap();
    await assertNoHorizontalOverflow();
    summary.pages.category = {href: categoryHref};
  } else if (scenario === 'cart-empty') {
    await navigate('cart.php', 'cart-empty');
    await assertVersionedAssets({expectCartJs: true});
    await assertFooterNavy();
    await assertNoHorizontalOverflow();
    const empty = await evaluate(`!!document.querySelector('.cart-empty-state, .alert, .cart-summary-card') || document.body.innerText.toLowerCase().includes('carrito')`);
    requireValue(empty, 'cart page did not render');
    summary.pages.cartEmpty = true;
  } else if (scenario === 'cart-with-items') {
    await navigate('index.php', 'home-for-cart');
    await waitFor('add-to-cart button', `!!document.querySelector('.add-to-cart:not([disabled])')`);
    const added = await evaluate(`(() => {
      const btn = document.querySelector('.add-to-cart:not([disabled])');
      if (!btn) return {ok: false, reason: 'no add button'};
      btn.click();
      return {ok: true};
    })()`);
    requireValue(added && added.ok, `could not add to cart: ${JSON.stringify(added)}`);
    await sleep(500);
    await navigate('cart.php', 'cart-with-items');
    await assertVersionedAssets({expectCartJs: true});
    await assertFooterNavy();
    await assertNoHorizontalOverflow();
    const hasItems = await evaluate(`(() => {
      try {
        const raw = localStorage.getItem('cart') || localStorage.getItem('cyberleo_cart') || '[]';
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) && parsed.length > 0;
      } catch (e) {
        return document.body.innerText.toLowerCase().includes('total') || !!document.querySelector('.cart-item, .cart-summary-card');
      }
    })()`);
    requireValue(hasItems, 'cart did not retain items after add');
    summary.pages.cartWithItems = true;
  } else if (scenario === 'login') {
    await navigate('admin_login.php', 'login');
    await assertVersionedAssets();
    await assertNoHorizontalOverflow();
    const hasForm = await evaluate(`!!document.querySelector('form input[name="username"], form input[name="password"]')`);
    requireValue(hasForm, 'login form missing');
    // Login page may omit public footer; do not require navy footer.
    summary.pages.login = true;
  } else if (scenario === 'admin-settings') {
    requireValue(adminPassword !== '', 'HTTP_TEST_ADMIN_PASSWORD required for admin-settings');
    await navigate('admin_login.php', 'login-for-admin');
    await evaluate(`(() => {
      const user = document.querySelector('input[name="username"]');
      const pass = document.querySelector('input[name="password"]');
      if (user) user.value = 'http-admin';
      if (pass) pass.value = ${JSON.stringify(adminPassword)};
      const form = document.querySelector('form');
      if (form) form.submit();
    })()`);
    await waitFor('admin redirect', `location.pathname.includes('admin_')`);
    await navigate('admin_settings.php', 'admin-settings');
    await assertVersionedAssets();
    const previewJs = await evaluate(`([...document.querySelectorAll('script[src]')].map(s => s.getAttribute('src') || '')).filter(s => /preview\\.js/.test(s))`);
    requireValue(previewJs.length >= 1, 'preview scripts missing');
    requireValue(previewJs.every(s => /\?v=[a-zA-Z0-9]+/.test(s)), `preview js missing ?v=: ${previewJs.join(',')}`);
    await assertNoHorizontalOverflow();
    summary.pages.adminSettings = {previewJs};
  } else if (scenario === 'matrix') {
    // Full public matrix in one Chromium session (benefits expected on).
    await runHome({expectBenefits: true});
    const categoryHref = await evaluate(`(() => {
      const link = [...document.querySelectorAll('a[href*="category.php"]')].find(a => /category\\.php\\?id=\\d+/.test(a.getAttribute('href') || ''));
      return link ? link.getAttribute('href') : 'category.php?id=1';
    })()`);
    await navigate(categoryHref, 'category');
    await assertVersionedAssets({expectCatalogJs: true});
    await assertFooterNavy();
    await assertProductMediaCap();
    await assertNoHorizontalOverflow();
    await navigate('cart.php', 'cart-empty');
    await assertVersionedAssets({expectCartJs: true});
    await assertFooterNavy();
    await assertNoHorizontalOverflow();
    await navigate('admin_login.php', 'login');
    await assertVersionedAssets();
    await assertNoHorizontalOverflow();
    summary.pages.matrix = true;
  } else {
    throw new Error(context(`unknown scenario: ${scenario}`));
  }

  assertNoBrowserErrors();
  console.log(JSON.stringify(summary, null, 2));
  ws.close();
  process.exit(0);
} catch (error) {
  console.error(String(error && error.stack ? error.stack : error));
  process.exit(1);
}
