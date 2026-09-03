const [port, base, mode = 'default'] = process.argv.slice(2);
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
  'alt-home',
  'alt-category',
  'cols-2',
  'cols-3',
  'cols-4',
  'mobile-390',
  'description-expand',
  'hidden',
  'cart',
  'copy',
  'image-error',
  'image-cover',
  'image-contain',
  'xss',
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

const gridMetrics = async selector => evaluate(`(() => {
  const grid = document.querySelector(${JSON.stringify(selector)});
  if (!grid) return null;
  const items = [...grid.querySelectorAll(':scope > .product-grid-item')];
  const cards = items.map(item => item.querySelector('.product-card')).filter(Boolean);
  const tops = {};
  items.forEach(item => {
    const top = Math.round(item.getBoundingClientRect().top);
    tops[top] = (tops[top] || 0) + 1;
  });
  const rowCounts = Object.values(tops);
  const firstCard = cards[0];
  const media = firstCard ? firstCard.querySelector('.product-media') : null;
  const img = firstCard ? firstCard.querySelector('img.product-card-image, img.single-product-image') : null;
  const heights = cards.map(card => Math.round(card.getBoundingClientRect().height));
  const sameRow = items.slice(0, Math.min(items.length, 4));
  const rowTop = sameRow.length ? Math.round(sameRow[0].getBoundingClientRect().top) : null;
  const sameRowCards = sameRow
    .filter(item => Math.round(item.getBoundingClientRect().top) === rowTop)
    .map(item => item.querySelector('.product-card'))
    .filter(Boolean);
  const rowHeights = sameRowCards.map(card => Math.round(card.getBoundingClientRect().height));
  const paired = rowHeights.length <= 1 || Math.max(...rowHeights) - Math.min(...rowHeights) <= 8;
  return {
    className: grid.className,
    count: items.length,
    firstRowCount: rowCounts.length ? Math.max(...rowCounts) : 0,
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    objectFit: img ? getComputedStyle(img).objectFit : null,
    mediaHeight: media ? Math.round(parseFloat(getComputedStyle(media).height)) : null,
    align: firstCard ? (firstCard.classList.contains('product-card-align-center') ? 'center' : 'left') : null,
    style: firstCard ? (
      firstCard.classList.contains('product-card-minimal') ? 'minimal'
        : firstCard.classList.contains('product-card-bordered') ? 'bordered' : 'elevated'
    ) : null,
    paired,
    heights,
    title: document.querySelector('#productos-destacados')?.textContent?.trim() || null,
    hasShare: !!document.querySelector('.product-share'),
    hasStock: [...document.querySelectorAll('.stock-display')].some(el => !el.classList.contains('visually-hidden')),
    hasDesc: !!document.querySelector('.description-container'),
    hasSaleBadge: !!document.querySelector('.product-sale-badge'),
    hasEmptyImage: [...document.querySelectorAll('.product-image-empty')].some(el => /Sin imagen/.test(el.textContent || '')),
  };
})()`);

const loadedImageProbe = async () => evaluate(`(() => {
  const cards = [...document.querySelectorAll('.product-card')];
  for (const card of cards) {
    const img = card.querySelector('img.product-card-image, img.single-product-image');
    if (!img) continue;
    if (!(img.naturalWidth > 0 && img.naturalHeight > 0)) continue;
    const empty = card.querySelector('.product-image-empty');
    const media = card.querySelector('.product-media');
    return {
      found: true,
      naturalWidth: img.naturalWidth,
      naturalHeight: img.naturalHeight,
      objectFit: getComputedStyle(img).objectFit,
      mediaHeight: media ? Math.round(parseFloat(getComputedStyle(media).height)) : null,
      hasEmptyInCard: !!empty,
      heightClass: card.classList.contains('product-height-compact') ? 'compact'
        : card.classList.contains('product-height-large') ? 'large' : 'normal',
      fitClass: card.classList.contains('product-fit-cover') ? 'cover'
        : card.classList.contains('product-fit-contain') ? 'contain' : null,
    };
  }
  return {found: false};
})()`);

try {
  requireValue(port && base, 'usage: chrome_catalog_display.mjs <debug-port> <base-url> <mode>');
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
      if (message.error) {
        request.reject(new Error(context(`CDP ${request.method}: ${message.error.message}`)));
      } else {
        request.resolve(message.result);
      }
    }
    if (message.method === 'Runtime.exceptionThrown') {
      const details = message.params?.exceptionDetails || {};
      const text = details.exception?.description || details.text || 'Runtime.exceptionThrown';
      browserErrors.push({type: 'exception', message: String(text), stage, url: currentUrl});
    }
    if (message.method === 'Runtime.consoleAPICalled') {
      const type = message.params?.type;
      if (type === 'error' || type === 'assert') {
        const args = (message.params?.args || [])
          .map(arg => arg.value ?? arg.description ?? arg.type)
          .join(' ');
        browserErrors.push({type: `console.${type}`, message: String(args || 'console error'), stage, url: currentUrl});
      }
    }
  });

  await call('Page.enable');
  await call('Runtime.enable');

  const width = mode === 'mobile-390' ? 390 : (mode === 'cols-4' || mode === 'default' || mode === 'alt-home' || mode === 'alt-category' ? 1440 : 1280);
  const height = mode === 'mobile-390' ? 844 : 900;
  await call('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: mode === 'mobile-390' ? 2 : 1,
    mobile: mode === 'mobile-390',
  });

  if (mode === 'alt-category' || mode === 'cols-2' || mode === 'cols-3' || mode === 'cols-4' && false) {
    // placeholder kept for readability; category modes navigate below
  }

  if (mode === 'alt-category' || mode === 'hidden') {
    await navigate('/category.php?id=1', 'category');
  } else {
    await navigate('/index.php', 'home');
  }
  await sleep(1100);
  assertNoBrowserErrors();

  if (mode === 'default') {
    const m = await gridMetrics('.product-grid.product-cols-3');
    requireValue(m && m.count > 0, 'expected featured products');
    requireValue(m.className.includes('product-cols-3'), 'expected 3 columns class');
    requireValue(m.firstRowCount >= 2 && m.firstRowCount <= 3, `unexpected first row count ${m.firstRowCount}`);
    requireValue(!m.overflow, 'horizontal overflow');
    requireValue(m.hasDesc, 'description should be visible');
    requireValue(m.hasStock, 'stock should be visible');
    requireValue(m.hasShare, 'share should be visible');
    requireValue(m.paired, 'cards should be visually paired');
    requireValue(m.title && /Productos Destacados/.test(m.title), 'default title missing');
  }

  if (mode === 'alt-home') {
    const m = await gridMetrics('.product-grid');
    requireValue(m && m.className.includes('product-cols-4'), 'expected 4 columns on home');
    requireValue(m.style === 'minimal' || m.style === 'bordered', `unexpected style ${m.style}`);
    requireValue(m.align === 'center', 'expected center align');
    requireValue(m.title && /Destacados Alt/.test(m.title), 'alt title missing');
    requireValue(!m.overflow, 'overflow on alt home');
  }

  if (mode === 'alt-category') {
    const m = await gridMetrics('.product-grid');
    requireValue(m && m.className.includes('product-cols-4'), 'expected 4 columns on category');
    requireValue(!m.overflow, 'overflow on category');
    const crumbs = await evaluate('!!document.querySelector("nav[aria-label=breadcrumb]")');
    requireValue(crumbs, 'breadcrumbs should be visible in alt-category fixture');
  }

  if (mode === 'cols-2' || mode === 'cols-3' || mode === 'cols-4') {
    const expected = mode === 'cols-2' ? '2' : mode === 'cols-3' ? '3' : '4';
    const m = await gridMetrics(`.product-grid.product-cols-${expected}`);
    requireValue(m, `missing product-cols-${expected}`);
    requireValue(!m.overflow, `overflow with ${expected} cols`);
    if (mode === 'cols-2') requireValue(m.firstRowCount === 2 || m.count < 2, `cols-2 row=${m.firstRowCount}`);
    if (mode === 'cols-3') requireValue(m.firstRowCount === 3 || m.count < 3, `cols-3 row=${m.firstRowCount}`);
    if (mode === 'cols-4') requireValue(m.firstRowCount === 4 || m.count < 4, `cols-4 row=${m.firstRowCount}`);
  }

  if (mode === 'mobile-390') {
    const m = await gridMetrics('.product-grid');
    requireValue(m, 'grid missing on mobile');
    requireValue(m.firstRowCount === 1 || m.count === 0, `mobile should stack to 1 col, got ${m.firstRowCount}`);
    requireValue(!m.overflow, 'mobile overflow');
  }

  if (mode === 'description-expand') {
    const before = await evaluate(`(() => {
      const btn = document.querySelector('.ver-mas');
      if (!btn) return {hasBtn:false};
      return {hasBtn:true, text: btn.textContent.trim(), showing: btn.getAttribute('data-showing-full')};
    })()`);
    requireValue(before.hasBtn, 'Ver más missing');
    await evaluate(`document.querySelector('.ver-mas').click()`);
    await sleep(200);
    const after = await evaluate(`(() => {
      const btn = document.querySelector('.ver-mas');
      const full = document.querySelector('.full-description');
      return {
        text: btn ? btn.textContent.trim() : '',
        showing: btn ? btn.getAttribute('data-showing-full') : null,
        fullVisible: full ? !full.hidden : false,
      };
    })()`);
    requireValue(after.text === 'Ver menos', 'expected Ver menos');
    requireValue(after.showing === 'true', 'expected expanded state');
    requireValue(after.fullVisible, 'full description should be visible');
  }

  if (mode === 'hidden') {
    const state = await evaluate(`(() => ({
      desc: !!document.querySelector('.description-container'),
      stockVisible: [...document.querySelectorAll('.stock-display')].some(el => !el.classList.contains('visually-hidden')),
      share: !!document.querySelector('.product-share'),
      sale: !!document.querySelector('.product-sale-badge'),
      crumbs: !!document.querySelector('nav[aria-label=breadcrumb]'),
      countText: /producto/.test(document.body.innerText) && !!document.querySelector('p.text-muted'),
      filter: !!document.querySelector('.dropdown .bi-funnel, .dropdown-toggle .bi-funnel'),
    }))()`);
    // On category with hidden toggles:
    requireValue(!state.desc, 'description should be hidden');
    requireValue(!state.stockVisible, 'stock text should be hidden');
    requireValue(!state.share, 'share should be hidden');
    requireValue(!state.sale, 'sale badge should be hidden');
    requireValue(!state.crumbs, 'breadcrumbs should be hidden');
  }

  if (mode === 'cart') {
    await evaluate(`localStorage.removeItem('cart')`);
    await navigate('/index.php', 'home-cart');
    await sleep(800);
    const clicked = await evaluate(`(() => {
      const btn = document.querySelector('.add-to-cart');
      if (!btn || btn.disabled) return false;
      btn.click();
      return true;
    })()`);
    requireValue(clicked, 'add-to-cart not clickable');
    await sleep(300);
    const cartState = await evaluate(`(() => {
      let cart = [];
      try { cart = JSON.parse(localStorage.getItem('cart') || '[]'); } catch (e) { cart = []; }
      const item = cart[0] || null;
      const keys = item ? Object.keys(item).sort() : [];
      const count = document.querySelector('.cart-count')?.textContent || '0';
      const btn = document.querySelector('.add-to-cart');
      return {
        keys,
        item,
        count,
        stockText: btn?.closest('.card-body')?.querySelector('.stock-display')?.textContent || '',
        label: btn?.querySelector('.add-to-cart-label')?.textContent || '',
      };
    })()`);
    requireValue(JSON.stringify(cartState.keys) === JSON.stringify(['productId', 'productName', 'productPrice', 'quantity'].sort()), `schema ${cartState.keys}`);
    requireValue(Number(cartState.item.quantity) === 1, 'quantity should be 1');
    requireValue(cartState.count === '1', 'cart count should be 1');
  }

  if (mode === 'copy') {
    await evaluate(`(() => {
      const btn = document.querySelector('.share-copy-link');
      if (!btn) throw new Error('copy button missing');
      btn.click();
      return true;
    })()`);
    await sleep(300);
    const toast = await evaluate(`!![...document.querySelectorAll('.alert')].some(el => /copiado/i.test(el.textContent || ''))`);
    requireValue(toast, 'copy toast missing');
  }

  if (mode === 'image-error') {
    await evaluate(`(() => {
      const img = document.querySelector('.product-card-image, .single-product-image');
      if (!img) {
        return !!document.querySelector('.product-image-empty');
      }
      img.dispatchEvent(new Event('error'));
      return true;
    })()`);
    await sleep(200);
    const empty = await evaluate(`!![...document.querySelectorAll('.product-image-empty')].some(el => /Sin imagen/.test(el.textContent || ''))`);
    requireValue(empty, 'placeholder not shown after image error');
  }

  if (mode === 'image-cover') {
    await waitFor('loaded product image', `(() => {
      const img = document.querySelector('.product-card img.product-card-image, .product-card img.single-product-image');
      return !!(img && img.complete && img.naturalWidth > 0);
    })()`);
    const probe = await loadedImageProbe();
    requireValue(probe.found, 'expected a loaded product image');
    requireValue(probe.naturalWidth > 0, `naturalWidth=${probe.naturalWidth}`);
    requireValue(probe.naturalHeight > 0, `naturalHeight=${probe.naturalHeight}`);
    requireValue(!probe.hasEmptyInCard, 'target card should not show empty placeholder');
    requireValue(probe.objectFit === 'cover', `object-fit=${probe.objectFit}`);
    requireValue(probe.fitClass === 'cover', `fit class=${probe.fitClass}`);
    requireValue(probe.heightClass === 'large', `height class=${probe.heightClass}`);
    requireValue(probe.mediaHeight >= 240, `large media height=${probe.mediaHeight}`);
  }

  if (mode === 'image-contain') {
    await waitFor('loaded product image', `(() => {
      const img = document.querySelector('.product-card img.product-card-image, .product-card img.single-product-image');
      return !!(img && img.complete && img.naturalWidth > 0);
    })()`);
    const probe = await loadedImageProbe();
    requireValue(probe.found, 'expected a loaded product image');
    requireValue(probe.naturalWidth > 0, `naturalWidth=${probe.naturalWidth}`);
    requireValue(probe.naturalHeight > 0, `naturalHeight=${probe.naturalHeight}`);
    requireValue(!probe.hasEmptyInCard, 'target card should not show empty placeholder');
    requireValue(probe.objectFit === 'contain', `object-fit=${probe.objectFit}`);
    requireValue(probe.fitClass === 'contain', `fit class=${probe.fitClass}`);
    requireValue(probe.heightClass === 'normal', `height class=${probe.heightClass}`);
    requireValue(probe.mediaHeight >= 180 && probe.mediaHeight <= 260, `normal media height=${probe.mediaHeight}`);
  }

  if (mode === 'xss') {
    const probe = await evaluate(`(() => ({
      executed: typeof globalThis.catalogXssExecuted,
      scripts: [...document.querySelectorAll('script')].some(s => /catalogXssExecuted/.test(s.textContent || '')),
      onerror: [...document.querySelectorAll('[onerror]')].length,
      onclick: [...document.querySelectorAll('[onclick]')].length,
      jsUrl: [...document.querySelectorAll('a[href]')].some(a => /^javascript:/i.test(a.getAttribute('href') || '')),
    }))()`);
    requireValue(probe.executed === 'undefined', 'catalogXssExecuted should remain undefined');
    requireValue(!probe.scripts, 'injected script text found');
    requireValue(probe.onerror === 0, 'onerror attributes found');
    requireValue(probe.onclick === 0, 'onclick attributes found');
    requireValue(!probe.jsUrl, 'javascript: URL found');
  }

  assertNoBrowserErrors();
  const summary = {ok: true, mode, browserErrors: 0};
  if (mode === 'image-cover' || mode === 'image-contain') {
    summary.imageProbe = await loadedImageProbe();
  }
  console.log(JSON.stringify(summary, null, 2));
  ws.close();
  process.exit(0);
} catch (error) {
  console.error(error && error.stack ? error.stack : String(error));
  try { if (ws) ws.close(); } catch (_) {}
  process.exit(1);
}
