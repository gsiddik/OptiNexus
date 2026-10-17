// Measures what the first screens cost, for one or more production builds:
//   node e2e/measure.mjs dist [other-dist ...]        (RUNS=7 repetitions, median reported; JSON with MEASURE_JSON=1)
// For each build and scenario: requests made, bytes of the files fetched (raw and gzip -9, computed from the files), and the time
// until the first usable screen (the page heading is visible), on localhost with the mocked API, so only relative numbers mean anything.
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { frontendDir, loadPlaywright, mockApi, startPreview, withSession } from './support.mjs';

const { chromium } = loadPlaywright();
const dirs = process.argv.slice(2).length ? process.argv.slice(2) : ['dist'];
const RUNS = Number(process.env.RUNS ?? 7);
const median = (values) => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];

// "Usable" = the text of the screen is on the page. Measured inside the page (performance.now() is milliseconds since the navigation
// started) and polled every frame, so the numbers are not rounded up by the test tool's own polling.
const SCENARIOS = [
  { name: 'sign-in screen', path: '/login', session: false, ready: { selector: 'button', text: 'Sign in|Masuk' } },
  { name: 'dashboard (signed in)', path: '/', session: true, ready: { selector: 'h1,h2', text: 'Welcome' } },
  {
    name: 'dashboard then open Roles',
    path: '/',
    session: true,
    ready: { selector: 'h1,h2', text: 'Welcome' },
    then: { click: 'a[href="/roles"]', ready: { selector: 'h1,h2', text: '^Roles$' } },
  },
];

const appears = ({ selector, text }) => {
  const pattern = new RegExp(text);
  return [...document.querySelectorAll(selector)].some((el) => pattern.test((el.textContent ?? '').trim()));
};

function fileSizes(distDir, urlPath) {
  const file = path.resolve(frontendDir, distDir, urlPath.split('?')[0].replace(/^\//, ''));
  try {
    const bytes = fs.readFileSync(file);
    return { raw: bytes.length, gzip: zlib.gzipSync(bytes, { level: 9 }).length };
  } catch {
    return null;
  }
}

const report = {};
for (const distDir of dirs) {
  const preview = await startPreview(distDir);
  const browser = await chromium.launch();
  report[distDir] = {};
  const assets = path.resolve(frontendDir, distDir, 'assets');
  const all = fs.existsSync(assets) ? fs.readdirSync(assets).map((f) => ({ f, ...fileSizes(distDir, `/assets/${f}`) })) : [];
  const js = all.filter((x) => x.f.endsWith('.js'));
  report[distDir].build = {
    jsFiles: js.length,
    jsRaw: js.reduce((a, x) => a + x.raw, 0),
    jsGzip: js.reduce((a, x) => a + x.gzip, 0),
    cssRaw: all.filter((x) => x.f.endsWith('.css')).reduce((a, x) => a + x.raw, 0),
    cssGzip: all.filter((x) => x.f.endsWith('.css')).reduce((a, x) => a + x.gzip, 0),
  };

  for (const scenario of SCENARIOS) {
    const runs = [];
    for (let i = 0; i < RUNS; i++) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
      if (scenario.session) await withSession(context);
      const page = await context.newPage();
      await mockApi(page);
      const requests = [];
      page.on('requestfinished', (req) => {
        if (req.url().startsWith(preview.base)) requests.push(req.url().slice(preview.base.length) || '/');
      });
      const apiRequests = [];
      page.on('request', (req) => {
        if (req.url().includes('/api/v1/')) apiRequests.push(req.url());
      });
      await page.goto(`${preview.base}${scenario.path}`, { waitUntil: 'commit' });
      await page.waitForFunction(appears, scenario.ready, { polling: 'raf', timeout: 15000 });
      const firstUsable = await page.evaluate(() => performance.now());
      let afterNavigation = null;
      let navRequests = null;
      if (scenario.then) {
        await page.waitForLoadState('networkidle');
        const before = requests.length;
        const t1 = await page.evaluate(() => performance.now());
        await page.click(scenario.then.click);
        await page.waitForFunction(appears, scenario.then.ready, { polling: 'raf', timeout: 15000 });
        afterNavigation = (await page.evaluate(() => performance.now())) - t1;
        await page.waitForLoadState('networkidle');
        navRequests = requests.length - before;
      }
      await page.waitForLoadState('networkidle');
      runs.push({ firstUsable, afterNavigation, requests: [...requests], navRequests, api: apiRequests.length });
      await context.close();
    }
    const files = [...new Set(runs[0].requests.filter((r) => r.startsWith('/assets/') || r === '/' || r.startsWith('/login') || r.startsWith('/brand/')))];
    let raw = 0;
    let gzip = 0;
    for (const f of files.filter((x) => x.startsWith('/assets/'))) {
      const size = fileSizes(distDir, f);
      if (size) {
        raw += size.raw;
        gzip += size.gzip;
      }
    }
    report[distDir][scenario.name] = {
      requests: median(runs.map((r) => r.requests.length)),
      appFiles: files,
      jsCssRawBytes: raw,
      jsCssGzipBytes: gzip,
      firstUsableMs: Math.round(median(runs.map((r) => r.firstUsable))),
      ...(scenario.then ? { navigationMs: Math.round(median(runs.map((r) => r.afterNavigation))), navigationExtraRequests: median(runs.map((r) => r.navRequests)) } : {}),
    };
  }
  await browser.close();
  preview.stop();
}

if (process.env.MEASURE_JSON === '1') {
  console.log(JSON.stringify(report, null, 1));
} else {
  for (const [dir, data] of Object.entries(report)) {
    const b = data.build;
    console.log(`\n== ${dir}: ${b.jsFiles} JS files, ${b.jsRaw} B raw / ${b.jsGzip} B gzip in total; CSS ${b.cssRaw} B raw / ${b.cssGzip} B gzip`);
    for (const [name, m] of Object.entries(data)) {
      if (name === 'build') continue;
      console.log(`  ${name}: ${m.requests} requests, JS+CSS ${m.jsCssRawBytes} B raw / ${m.jsCssGzipBytes} B gzip, first usable ${m.firstUsableMs} ms${m.navigationMs !== undefined ? `, then ${m.navigationMs} ms (+${m.navigationExtraRequests} requests)` : ''}`);
    }
  }
}
