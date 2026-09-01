const [port, base] = process.argv.slice(2);
const targets = await fetch(`http://127.0.0.1:${port}/json/list`).then(r => r.json());
const ws = new WebSocket(targets[0].webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
let id = 0;
const pending = new Map();
ws.onmessage = event => {
  const msg = JSON.parse(event.data);
  if (msg.id && pending.has(msg.id)) {
    const {resolve, reject} = pending.get(msg.id); pending.delete(msg.id);
    msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
  }
};
const call = (method, params = {}) => new Promise((resolve, reject) => {
  const callId = ++id; pending.set(callId, {resolve, reject});
  ws.send(JSON.stringify({id: callId, method, params}));
});
const evaluate = async expression => (await call('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true})).result.value;
const navigate = async url => { await call('Page.navigate', {url}); await new Promise(r => setTimeout(r, 1200)); };
await call('Page.enable'); await call('Runtime.enable');

await navigate(`${base}/index.php`);
if (!await evaluate(`typeof globalThis.xssExecuted === 'undefined' && document.title !== 'XSS_EXECUTED' && !document.querySelector('[onerror*="xssExecuted"]') && !Array.from(document.scripts).some(s=>s.textContent.includes('globalThis.xssExecuted=1'))`)) process.exit(11);

await navigate(`${base}/cart.php`);
await evaluate(`localStorage.setItem('cart',JSON.stringify([{productId:'2',quantity:1}]));location.reload()`);
await new Promise(r => setTimeout(r, 1200));
if (!await evaluate(`typeof globalThis.xssExecuted === 'undefined' && document.title !== 'XSS_EXECUTED' && !document.querySelector('[onerror*="xssExecuted"]') && document.body.textContent.includes('<script>globalThis.xssExecuted')`)) process.exit(12);

await evaluate(`fetch('${base}/admin_login.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:'http-admin',password:'HttpTestPass!42'})})`);
await new Promise(r => setTimeout(r, 500));
await navigate(`${base}/admin_products.php`);
await evaluate(`document.querySelector('.edit-product-btn')?.click()`);
await new Promise(r => setTimeout(r, 300));
if (!await evaluate(`typeof globalThis.xssExecuted === 'undefined' && document.title !== 'XSS_EXECUTED' && !document.querySelector('[onerror*="xssExecuted"]')`)) process.exit(13);
console.log('index, cart and admin modal remained non-executable');
ws.close();
