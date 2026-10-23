/** Isolated browser smoke tests; requires PHP, Chrome/Edge and the built core UI assets. */
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const core = path.resolve(process.argv[2] || path.join(moduleRoot, '../___majordomo'));
const output = mkdtempSync(path.join(os.tmpdir(), 'tuya-ui-smoke-'));
const variants = ['devices', 'properties', 'edit', 'new', 'error', 'empty', 'saved'];
const pages = new Map(variants.map((variant) => [variant, execFileSync('php', [path.join(moduleRoot, 'tools/tuya-ui-fixture.php'), core, variant], { encoding: 'utf8' })]));
let requests = [];
const server = createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (url.pathname === '/ajax/tuya.html') {
        requests.push(Object.fromEntries(url.searchParams));
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        const op = url.searchParams.get('op');
        res.end(op === 'scan' ? '<H4>Найдены устройства</H4><table><tr><td>Тестовое устройство</td><td>fixture-32</td><td>3.4</td><td>192.0.2.32</td></tr></table>' : '');
        return;
    }
    if (url.pathname === '/') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(pages.get(url.searchParams.get('fixture') || 'devices'));
        return;
    }
    let base, relative;
    if (url.pathname.startsWith('/assets/admin/')) { base = path.join(core, 'public/assets/admin'); relative = url.pathname.slice('/assets/admin/'.length); }
    else if (url.pathname.startsWith('/assets/modules/tuya/')) { base = path.join(moduleRoot, 'public/assets/modules/tuya'); relative = url.pathname.slice('/assets/modules/tuya/'.length); }
    else if (url.pathname === '/favicon.ico') { res.writeHead(204).end(); return; }
    else { res.writeHead(404).end(); return; }
    const file = path.resolve(base, relative);
    if (!file.startsWith(base + path.sep) || !existsSync(file)) { res.writeHead(404).end(); return; }
    const types = { '.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.woff': 'font/woff' };
    res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
    res.end(readFileSync(file));
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const chrome = [process.env.CHROME_PATH, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((file) => file && existsSync(file));
assert.ok(chrome, 'Chrome/Edge is required. Set CHROME_PATH if needed.');
const browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${output}`, 'about:blank'], { stdio: 'ignore', windowsHide: true });
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let ws;
let passed = 0;
try {
    const portFile = path.join(output, 'DevToolsActivePort');
    for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
    assert.ok(existsSync(portFile), 'Chrome did not start.');
    const port = readFileSync(portFile, 'utf8').split('\n')[0];
    const target = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' }).then((res) => res.json());
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
    let seq = 0;
    const pending = new Map();
    const errors = [];
    function send(method, params = {}) {
        const id = ++seq;
        return new Promise((resolve, reject) => {
            pending.set(id, { resolve, reject });
            ws.send(JSON.stringify({ id, method, params }));
        });
    }
    ws.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);
        if (message.id) {
            const item = pending.get(message.id);
            if (item) { pending.delete(message.id); message.error ? item.reject(new Error(JSON.stringify(message.error))) : item.resolve(message.result); }
        } else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
        else if (message.method === 'Fetch.requestPaused') {
            // A UI fixture must never access a real Tuya or MajorDoMo service.
            const local = message.params.request.url.startsWith(origin + '/');
            send(local ? 'Fetch.continueRequest' : 'Fetch.failRequest', local ? { requestId: message.params.requestId } : { requestId: message.params.requestId, errorReason: 'BlockedByClient' });
        }
    });
    async function evaluate(expression) {
        const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    }
    async function check(expression, label) {
        assert.ok(await evaluate(expression), label);
        passed++;
    }
    async function open(variant, width = 1440, dark = false) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 1100, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: `${origin}/?fixture=${variant}` });
        for (let i = 0; i < 100; i++) {
            if (await evaluate('document.readyState === "complete" && !!document.querySelector("[data-tuya-ready]")')) break;
            await sleep(75);
        }
        await evaluate(`document.documentElement.setAttribute('data-bs-theme', '${dark ? 'dark' : 'light'}')`);
        await sleep(200);
        await evaluate('document.fonts.ready');
        await check('!!document.querySelector("[data-tuya-ready]")', variant + ' initializes');
        if (await evaluate('document.documentElement.scrollWidth > innerWidth')) {
            await shot(variant + '-overflow');
            console.log('Overflow', output, await evaluate('Array.from(document.querySelectorAll("body *")).filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => [el.tagName, el.className, el.getBoundingClientRect().width]).slice(0, 15)'));
        }
        await check('document.documentElement.scrollWidth <= innerWidth', variant + ' fits viewport');
        await check('document.querySelectorAll("h1").length === 1', variant + ' has one heading');
    }
    async function shot(name) {
        const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(path.join(output, name + '.png'), Buffer.from(result.data, 'base64'));
    }
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    await open('devices');
    await check('document.querySelectorAll("[data-tuya-device]").length === 31', 'all devices are rendered');
    await check('Array.from(document.querySelectorAll("[data-tuya-device]")).filter(row => !row.hidden).length === 25', 'shared pagination works');
    await check(`!document.querySelector('.tuya-chips img') && document.querySelector('.tuya-chips').textContent.includes('<img')`, 'device property chips escape cloud values');
    await shot('devices-light-desktop');
    await evaluate(`document.querySelector('[data-bs-target="#tuya-settings"]').click()`);
    await sleep(450);
    await check(`document.querySelector('#tuya-settings').classList.contains('show') && new FormData(document.querySelector('[data-tuya-settings]')).get('tuya_sid') === 'fixture-session'`, 'connection settings preserve the session');
    await evaluate(`document.querySelector('[data-md-password-toggle="tuya_passwd"]').click()`);
    await check(`document.getElementById('tuya_passwd').type === 'text'`, 'shared password visibility control works');
    await check(`new FormData(document.querySelector('[data-tuya-settings]')).get('tuya_passwd') === 'fixture-only' && new FormData(document.querySelector('[data-tuya-settings]')).get('tuya_client_secret') === 'fixture-secret'`, 'credential controls preserve exact values');
    await shot('settings-light-desktop');
    await evaluate(`document.querySelector('[data-bs-target="#tuya-settings"]').click()`);
    await sleep(450);
    await evaluate(`document.querySelector('[data-md-table-search]').value = 'Кухня'; document.querySelector('[data-md-table-search]').dispatchEvent(new Event('input'));`);
    await sleep(220);
    await check(`Array.from(document.querySelectorAll('[data-tuya-device]')).filter(row => !row.hidden).length === 1`, 'device search works');
    await evaluate(`document.querySelector('[data-md-table-search]').value = ''; document.querySelector('[data-md-table-search]').dispatchEvent(new Event('input'));`);
    await sleep(220);
    // The native WebSocket adapter must not depend on the shell's jQuery pubsub bridge.
    await evaluate(`window.jQuery = undefined; window.$ = undefined; window.wsSocket = new EventTarget(); wsSocket.readyState = 1; wsSocket.send = payload => window.fixtureSubscribe = JSON.parse(payload); MDTuyaUI.init(document); MDTuyaUI.init(document);`);
    await check(`fixtureSubscribe.data.EVENTS === 'TUSTATUS'`, 'native socket subscribes');
    await evaluate(`wsSocket.dispatchEvent(new MessageEvent('message', {data: JSON.stringify({action:'events', data:JSON.stringify({EVENT_DATA:{NAME:'TUSTATUS', VALUE:{FIELD:'ONLINE',ID:1,VALUE:0}}})})}));`);
    await check(`document.querySelector('[data-tuya-device="1"]').dataset.status === 'offline' && document.querySelector('[data-tuya-kpi="ONLINE_DEVICES"]').textContent === '15'`, 'live status and KPI update without jQuery');
    await evaluate(`document.querySelector('[data-tuya-action="scan"]').click()`);
    await sleep(250);
    await check(`document.querySelector('[data-tuya-scan-content]').textContent.includes('fixture-32') && !document.querySelector('[data-tuya-action="scan"]').disabled`, 'scan results and loading state');
    await evaluate(`document.querySelector('[data-tuya-delete]').click()`);
    await sleep(250);
    await check(`document.querySelector('#tuya-delete').classList.contains('show') && document.querySelector('[data-tuya-delete-confirm]').href.includes('delete_tudevices')`, 'Bootstrap delete confirmation');
    await open('devices', 390, true);
    await check(`document.querySelector('[data-tuya-devices] .md-table').getBoundingClientRect().width <= document.querySelector('[data-tuya-devices] .md-table__scroll').clientWidth`, 'mobile devices expose status and actions without horizontal scrolling');
    await check(`document.querySelector('.tuya-chips').scrollWidth <= document.querySelector('.tuya-chips').clientWidth`, 'long linked values stay inside the mobile device column');
    await shot('devices-dark-mobile');
    await open('devices', 360, true);
    await check(`document.querySelector('.tuya-chips').scrollWidth <= document.querySelector('.tuya-chips').clientWidth`, 'long linked values also fit at 360px');
    await open('properties', 390, true);
    await check(`document.querySelectorAll('[data-tuya-property]').length === 2 && document.querySelectorAll('[data-md-linkedobject]:not([hidden])').length >= 6`, 'table rows reuse the core linkedobject controls');
    await check(`!document.querySelector('.tuya-property-value script') && document.querySelector('.tuya-property-preview').textContent.includes('<script>')`, 'property values are escaped');
    await evaluate(`document.querySelector('[data-tuya-property-search]').value = 'Температура'; document.querySelector('[data-tuya-property-search]').dispatchEvent(new Event('input'));`);
    await check(`Array.from(document.querySelectorAll('[data-tuya-property]')).filter(card => !card.hidden).length === 1`, 'property search works');
    await check(`new FormData(document.querySelector('[data-tuya-properties]')).has('alias102')`, 'filtered properties remain in form submission');
    await check(`document.querySelector('[data-tuya-property]').tagName === 'TBODY' && document.querySelector('[data-tuya-property-row]').getBoundingClientRect().height < 100`, 'properties are compact table rows');
    await check(`getComputedStyle(document.getElementById('tuya-options-101')).display === 'none'`, 'advanced settings start collapsed');
    await evaluate(`document.querySelector('[aria-controls="tuya-options-101"]').click()`);
    await sleep(100);
    await check(`getComputedStyle(document.getElementById('tuya-options-101')).display === 'table-row'`, 'advanced settings expand in place');
    await check(`document.getElementById('tuya-options-101').querySelectorAll('[data-bs-toggle="tooltip"]').length === 8`, 'advanced flags have explanatory tooltips');
    await shot('properties-dark-mobile');
    await open('properties', 1440, false);
    await shot('properties-light-desktop');
    await evaluate(`document.querySelector('[aria-controls="tuya-options-101"]').click()`);
    await sleep(100);
    await shot('options-light-desktop');
    await check(`document.querySelectorAll('.md-linkedobject--table').length === 6 && getComputedStyle(document.querySelector('.md-linkedobject--table .md-linkedobject__foot')).display === 'none'`, 'shared dense layout removes repeated field panels');
    await evaluate(`bootstrap.Tooltip.getOrCreateInstance(document.querySelector('#tuya-options-101 [data-bs-toggle="tooltip"]')).show()`);
    await sleep(200);
    await check(`document.querySelector('.tooltip-inner').textContent.includes('получении')`, 'parameter tooltips show real explanations');
    await evaluate(`bootstrap.Tooltip.getInstance(document.querySelector('#tuya-options-101 [data-bs-toggle="tooltip"]')).hide(); document.querySelector('[name="decode101"]').checked = true; document.querySelector('[data-tuya-property-search]').value = 'Яркость'; document.querySelector('[data-tuya-property-search]').dispatchEvent(new Event('input'));`);
    await check(`new FormData(document.querySelector('[data-tuya-properties]')).get('decode101') === '1'`, 'filtering retains changes in hidden advanced settings');
    await open('saved', 390, true);
    await check(`document.querySelector('[data-tuya-property]').textContent.includes('Сохранённое свойство') && document.querySelector('.tuya-property-preview').textContent.includes('<script>')`, 'saving a binding preserves current values');
    await open('edit', 1440);
    await shot('editor-light-desktop');
    for (const variant of ['edit', 'new', 'error', 'empty']) await open(variant, 390, true);
    await open('devices', 390, true);
    await check(`!document.querySelector('[href*="tab=scene"]') && !document.querySelector('[href*="tab=ir"]')`, 'obsolete navigation is removed');
    // Replacing content must reinitialize registered controls without jQuery.
    await evaluate(`(async () => {
        var html = await fetch(location.href).then(r => r.text());
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        document.querySelector('[data-tuya-page]').replaceWith(parsed.querySelector('[data-tuya-page]'));
        MDJAdminUI.boot(document);
    })()`);
    await check(`document.querySelector('[data-tuya-page]').dataset.tuyaReady === '1'`, 'module reinitializes after partLoad');
    await evaluate(`window.fetch = async () => new Response('', {status: 500}); document.querySelector('[data-tuya-action="scan"]').click()`);
    await sleep(250);
    await check(`document.querySelector('[data-tuya-feedback]').textContent.includes('HTTP 500') && !document.querySelector('[data-tuya-action="scan"]').disabled`, 'failed scanning can be retried');
    assert.deepEqual(errors, [], 'No uncaught browser exceptions.');
    passed++;
    console.log(JSON.stringify({ passed, screenshots: output, realServices: false }, null, 2));
} finally {
    ws?.close();
    browser.kill();
    server.close();
    await sleep(400);
    // Only the browser profile created inside this run's temporary directory is removed.
    const profile = path.join(output, 'Default');
    if (profile.startsWith(path.resolve(os.tmpdir()) + path.sep) && existsSync(profile)) rmSync(profile, { recursive: true, force: true });
}
