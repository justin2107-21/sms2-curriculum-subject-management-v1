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
];
for (const [from, to] of copies) {
  const dest = path.join(dist, to);
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  fs.copyFileSync(path.join(root, from), dest);
  console.log('copied', to);
}
