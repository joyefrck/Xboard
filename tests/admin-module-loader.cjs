// Load the small readable admin module graph without changing the CJS project
// package type. Data modules work on the Node versions used by CI as well.
const fs = require('node:fs');
const path = require('node:path');
const directory = path.resolve(__dirname, '../public/assets/admin/assets');
const cache = new Map();
function moduleUrl(name) {
  if (cache.has(name)) return cache.get(name);
  const source = fs.readFileSync(path.join(directory, name), 'utf8').replace(
    /from (['"])(\.\/[^'"]+)\1/g,
    (_, quote, dependency) => 'from ' + quote + moduleUrl(dependency.slice(2).split('?')[0]) + quote,
  );
  const url = 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
  cache.set(name, url);
  return url;
}
module.exports = name => import(moduleUrl(name));
