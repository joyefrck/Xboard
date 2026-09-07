const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const compose = fs.readFileSync(
  path.resolve(__dirname, '..', 'docker-compose.yaml'),
  'utf8',
);
const dockerfile = fs.readFileSync(
  path.resolve(__dirname, '..', 'Dockerfile'),
  'utf8',
);

function serviceBlock(name) {
  const marker = new RegExp(`^  ${name}:\\n`, 'm').exec(compose);
  assert.ok(marker, `expected ${name} service`);

  const remainder = compose.slice(marker.index + marker[0].length);
  const nextSection = remainder.search(
    /^(?:  [a-zA-Z0-9_-]+|[a-zA-Z0-9_-]+):\n/m,
  );

  return nextSection === -1 ? remainder : remainder.slice(0, nextSection);
}

test('database-backed services retain the external 1Panel network', () => {
  for (const service of ['web', 'horizon']) {
    assert.match(
      serviceBlock(service),
      /networks:\n\s{6}- default\n\s{6}- 1panel-network/,
    );
  }

  assert.match(
    compose,
    /^networks:\n  1panel-network:\n    external: true$/m,
  );
});

test('application containers cannot take ownership of the Redis data bind mount', () => {
  for (const service of ['web', 'horizon']) {
    assert.doesNotMatch(serviceBlock(service), /\.docker\/\.data\/redis\/?:\/data\/?/);
  }

  assert.match(serviceBlock('redis'), /\.docker\/\.data\/redis:\/data/);
});

test('the application image bypasses mutable upstream startup scripts', () => {
  assert.match(dockerfile, /^ENTRYPOINT \["docker-php-entrypoint"\]$/m);
});
