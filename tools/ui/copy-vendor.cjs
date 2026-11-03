'use strict';
// Copies vendored runtime files into assets/ui/dist. Dev-time only; the compiled
// output is committed and production never needs node_modules.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..', '..');
const dist = path.join(root, 'assets', 'ui', 'dist');
const copies = [
  ['node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', 'js/bootstrap.bundle.min.js'],
  ['node_modules/bootstrap-icons/font/fonts/bootstrap-icons.woff2', 'fonts/bootstrap-icons.woff2'],
  ['node_modules/bootstrap-icons/font/fonts/bootstrap-icons.woff', 'fonts/bootstrap-icons.woff'],
  // Dashboard: Chart.js (MIT) and self-hosted variable fonts (SIL OFL 1.1).
  ['node_modules/chart.js/dist/chart.umd.min.js', 'js/chart.umd.min.js'],
  ['node_modules/chart.js/LICENSE.md', 'js/chart.js-LICENSE.md'],
  ['node_modules/@fontsource-variable/dm-sans/files/dm-sans-latin-wght-normal.woff2', 'fonts/dm-sans-latin-wght-normal.woff2'],
  ['node_modules/@fontsource-variable/dm-sans/files/dm-sans-latin-ext-wght-normal.woff2', 'fonts/dm-sans-latin-ext-wght-normal.woff2'],
  ['node_modules/@fontsource-variable/dm-sans/LICENSE', 'fonts/OFL-DMSans.txt'],
  ['node_modules/@fontsource-variable/newsreader/files/newsreader-latin-wght-normal.woff2', 'fonts/newsreader-latin-wght-normal.woff2'],
  ['node_modules/@fontsource-variable/newsreader/files/newsreader-latin-ext-wght-normal.woff2', 'fonts/newsreader-latin-ext-wght-normal.woff2'],
  ['node_modules/@fontsource-variable/newsreader/LICENSE', 'fonts/OFL-Newsreader.txt'],
];
for (const [from, to] of copies) {
  const dest = path.join(dist, to);
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  fs.copyFileSync(path.join(root, from), dest);
  console.log('copied', to);
}
