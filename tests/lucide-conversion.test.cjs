const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createConverter } = require('../scripts/outline-build/convert.cjs');
const open = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';

test('stroke conversion preserves deterministic nonempty filled geometry', async () => {
  const convert = await createConverter();
  for (const shape of ['<circle cx="12" cy="12" r="10"/>', '<rect x="2" y="2" width="20" height="20" rx="4"/>', '<path d="M10 3a41 41 0 000 18"/>', '<line x1="2" y1="2" x2="22" y2="22"/>', '<ellipse cx="12" cy="12" rx="8" ry="4"/>', '<polyline points="2 2 12 12 22 2"/>', '<polygon points="2 2 12 22 22 2"/>']) {
    const input = open + shape + '</svg>';
    const output = convert(input);
    assert.equal(output, convert(input));
    assert.match(output, /<path fill="currentColor" d="[^"]+"/);
    assert.doesNotMatch(output, /stroke|<circle|<rect|<ellipse|<line|<poly/);
  }
  const filled = convert(open + '<circle cx="12" cy="12" r="2" fill="currentColor"/></svg>');
  assert.equal((filled.match(/<path /g) || []).length, 2);
  assert.match(filled, /C/);
  assert.doesNotMatch(filled, /Q/);
  assert.match(filled, /M9 12C9 /);
});

test('unsupported presentation and hostile markup fail closed', async () => {
  const convert = await createConverter();
  for (const shape of ['<path d="M1 1h2" opacity=".5"/>', '<path d="M1 1h2" transform="translate(1)"/>', '<script>alert(1)</script>', '<use href="https://example.com/x"/>', '<path d="bogus"/>', '<circle cx="NaN" cy="12" r="1"/>', '<g><path d="M1 1h2"/></g>']) {
    assert.throws(() => convert(open + shape + '</svg>'));
  }
  assert.throws(() => convert('<!DOCTYPE svg>' + open + '<path d="M1 1h2"/></svg>'));
  assert.throws(() => convert(open.replace('stroke-width="2"', 'stroke-width="3"') + '<path d="M1 1h2"/></svg>'));
});

test('Lucide manifest preserves provenance, taxonomy and disabled variant', () => {
  const root = path.join(__dirname, '../assets/icons/lucide');
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json')));
  assert.equal(manifest.version, '1.47.0');
  assert.equal(manifest.source.revision, '3b9ea6d08707edc439f25a4c354cb0d6b8bee973');
  assert.equal(manifest.icons.length, 1848);
  assert.equal(manifest.variants[0].defaultEnabled, false);
  assert.equal(manifest.variants[0].coreCompatible, true);
  assert.equal(manifest.license.name, 'ISC AND MIT');
  const license = fs.readFileSync(path.join(root, 'LICENSE'), 'utf8');
  assert.match(license, /Cole Bemis/);
  assert.match(license, /Lucide Icons and Contributors/);
  for (const icon of manifest.icons) {
    assert.ok(icon.categories.length && icon.keywords.length);
    assert.match(icon.id, /^lucide\/outline\//);
    assert.doesNotMatch(fs.readFileSync(path.join(root, icon.path), 'utf8'), /stroke|<circle|<rect|<ellipse|<line|<poly/);
  }
});
