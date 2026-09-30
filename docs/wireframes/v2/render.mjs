// Render the v2 wireframes to PNG at 2x.
//   npm i playwright-core   (anywhere on the path; uses the installed Edge)
//   node render.mjs <output-dir>
import { chromium } from 'playwright-core';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = process.argv[2] ?? here;
const files = ['4.1-submission', '4.2-verdict-bands', '4.3-verified-institution', '4.4-moderation-queue'];
const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';

const browser = await chromium.launch({ executablePath: edge });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 2 });
for (const f of files) {
  await page.goto(pathToFileURL(path.join(here, `${f}.html`)).href);
  await page.locator('.sheet').screenshot({ path: path.join(out, `figure-${f}.png`) });
  console.log('rendered', f);
}
await browser.close();
