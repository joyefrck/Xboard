// Simulate the runtime's theme publication for files intentionally ignored by Git.
// Existing (including tracked) mirrors stay untouched so drift checks remain useful.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
for (const theme of ['ElephantRoute', 'Xboard']) {
  for (const asset of ['umi.js', 'umi.js.gz', 'umi.js.br', 'elephant-route-order-refund.js']) {
    const source = path.join(root, 'theme', theme, 'assets', asset);
    const target = path.join(root, 'public/theme', theme, 'assets', asset);
    if (!fs.existsSync(target)) {
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.copyFileSync(source, target);
    }
  }
  const target = path.join(root, 'public/theme', theme, 'dashboard.blade.php');
  if (!fs.existsSync(target)) fs.copyFileSync(path.join(root, 'theme', theme, 'dashboard.blade.php'), target);
}
