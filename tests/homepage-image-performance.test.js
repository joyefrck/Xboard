const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');
const assetRoot = path.join(repoRoot, 'public/landing/assets');

const contentAssets = [
  'pain-google-ip',
  'pain-ai-tools',
  'pain-streaming',
];
const avatarAssets = [
  'avatar-pm',
  'avatar-tiktok',
  'avatar-educator',
  'avatar-freelancer',
];
const optimizedAssets = [...contentAssets, ...avatarAssets];

function assetPath(name, extension) {
  return path.join(assetRoot, `${name}.${extension}`);
}

function readLossyWebpDimensions(buffer) {
  assert.equal(buffer.toString('ascii', 0, 4), 'RIFF');
  assert.equal(buffer.toString('ascii', 8, 12), 'WEBP');
  assert.equal(buffer.toString('ascii', 12, 16), 'VP8 ');

  return {
    width: buffer.readUInt16LE(26) & 0x3fff,
    height: buffer.readUInt16LE(28) & 0x3fff,
  };
}

function imageTagFor(html, source) {
  const escapedSource = source.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = html.match(new RegExp(`<img[^>]*src="${escapedSource}"[^>]*>`, 's'));
  assert.ok(match, `${source} is referenced by an img tag`);
  return match[0];
}

test('optimized WebP assets meet signature, dimension, and byte budgets', () => {
  let optimizedBytes = 0;
  let legacyBytes = 0;

  for (const name of optimizedAssets) {
    const optimizedPath = assetPath(name, 'webp');
    const legacyPath = assetPath(name, 'png');
    assert.equal(fs.existsSync(optimizedPath), true, `${name}.webp exists`);
    assert.equal(fs.existsSync(legacyPath), true, `${name}.png remains available`);

    const buffer = fs.readFileSync(optimizedPath);
    const dimensions = readLossyWebpDimensions(buffer);
    const expectedSize = contentAssets.includes(name) ? 640 : 96;
    assert.deepEqual(dimensions, {width: expectedSize, height: expectedSize});

    optimizedBytes += buffer.length;
    legacyBytes += fs.statSync(legacyPath).size;
  }

  assert.ok(optimizedBytes <= 80 * 1024, `optimized bytes ${optimizedBytes} exceed 80 KiB`);
  assert.ok(optimizedBytes <= legacyBytes * 0.35, `${optimizedBytes} is more than 35% of ${legacyBytes}`);
});

test('homepage uses optimized images with explicit loading behavior', () => {
  const html = fs.readFileSync(path.join(repoRoot, 'public/landing/index.html'), 'utf8');

  for (const name of optimizedAssets) {
    assert.doesNotMatch(html, new RegExp(`/landing/assets/${name}\\.png`));
    const tag = imageTagFor(html, `/landing/assets/${name}.webp`);
    const isContentImage = contentAssets.includes(name);
    assert.match(tag, new RegExp(`width="${isContentImage ? 640 : 48}"`));
    assert.match(tag, new RegExp(`height="${isContentImage ? 640 : 48}"`));
    assert.match(tag, /loading="lazy"/);
    assert.match(tag, /decoding="async"/);
    assert.match(tag, /fetchpriority="low"/);
  }

  const logoTag = imageTagFor(html, '/landing/assets/elephant-route-logo.jpg');
  assert.match(logoTag, /width="40"/);
  assert.match(logoTag, /height="40"/);
  assert.match(logoTag, /decoding="async"/);
  assert.match(logoTag, /fetchpriority="high"/);
  assert.doesNotMatch(logoTag, /loading="lazy"/);
});
