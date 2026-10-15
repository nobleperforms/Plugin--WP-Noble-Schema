import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const adminScript = readFileSync(
  new URL('../src/scripts/admin.js', import.meta.url),
  'utf8'
);

test('nsAlert forwards detail items to the shared dialog', () => {
  const alertWrapper = adminScript.match(
    /window\.nsAlert = \(message, options = \{\}\) => openDialog\(\{([\s\S]*?)\n    \}\);/
  );

  assert.ok(alertWrapper, 'Expected to find the nsAlert dialog wrapper');
  assert.match(alertWrapper[1], /details:\s*options\.details/);
});
