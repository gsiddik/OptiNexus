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

const SCENARIOS = [
  { name: 'sign-in screen', path: '/login', session: false, ready: (page) => page.getByRole('button', { name: /Sign in|Masuk/ }).waitFor() },
  { name: 'dashboard (signed in)', path: '/', session: true, ready: (page) => page.getByRole('heading', { name: /Welcome/ }).waitFor() },
  {
    name: 'dashboard then open Roles',
    path: '/',
    session: true,
    ready: (page) => page.getByRole('heading', { name: /Welcome/ }).waitFor(),
    then: async (page) => {
      await page.getByRole('link', { name: 'Roles', exact: true }).click();
      await page.getByRole('heading', { name: 'Roles' }).first().waitFor();
    },
  },
];

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
      const t0 = Date.now();
      await page.goto(`${preview.base}${scenario.path}`, { waitUntil: 'commit' });
      await scenario.ready(page);
      const firstUsable = Date.now() - t0;
      let afterNavigation = null;
      let navRequests = null;
      if (scenario.then) {
        await page.waitForLoadState('networkidle');
        const before = requests.length;
        const t1 = Date.now();
        await scenario.then(page);
        afterNavigation = Date.now() - t1;
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
      firstUsableMs: median(runs.map((r) => r.firstUsable)),
      ...(scenario.then ? { navigationMs: median(runs.map((r) => r.afterNavigation)), navigationExtraRequests: median(runs.map((r) => r.navRequests)) } : {}),
    };
  }
  await browser.close();
  preview.stop();
}

if (process.env.MEASURE_JSON) {
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
