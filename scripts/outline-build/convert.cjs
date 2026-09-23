'use strict';

const fs = require('node:fs');
const { DOMParser } = require('@xmldom/xmldom');
const PathKitInit = require('pathkit-wasm');
const svgpath = require('svgpath');
const circleCubic = 0.5522847498307936;

function circlePath(x, y, r, reverse = false) {
  const k = r * circleCubic;
  if (![x, y, r, k, x - r, x + r, y - r, y + r, x - k, x + k, y - k, y + k].every(Number.isFinite)) {
    throw new Error('Circle coordinates overflow.');
  }
  if (reverse) {
    return `M${x-r} ${y}C${x-r} ${y+k} ${x-k} ${y+r} ${x} ${y+r}C${x+k} ${y+r} ${x+r} ${y+k} ${x+r} ${y}C${x+r} ${y-k} ${x+k} ${y-r} ${x} ${y-r}C${x-k} ${y-r} ${x-r} ${y-k} ${x-r} ${y}Z`;
  }
  return `M${x-r} ${y}C${x-r} ${y-k} ${x-k} ${y-r} ${x} ${y-r}C${x+k} ${y-r} ${x+r} ${y-k} ${x+r} ${y}C${x+r} ${y+k} ${x+k} ${y+r} ${x} ${y+r}C${x-k} ${y+r} ${x-r} ${y+k} ${x-r} ${y}Z`;
}

const attributes = {
  svg: ['xmlns', 'width', 'height', 'viewBox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'],
  path: ['d'], circle: ['cx', 'cy', 'r', 'fill'], ellipse: ['cx', 'cy', 'rx', 'ry'],
  rect: ['x', 'y', 'width', 'height', 'rx', 'ry'], line: ['x1', 'x2', 'y1', 'y2'],
  polygon: ['points'], polyline: ['points'],
};

async function createConverter() {
  const kit = await PathKitInit({ wasmBinary: fs.readFileSync(require.resolve('pathkit-wasm/bin/pathkit.wasm')) });
  return function convert(source) {
    if (/<!|<\?/.test(source)) throw new Error('Document declarations are unsupported.');
    const doc = new DOMParser({ onError: (level, message) => { throw new Error(message); } }).parseFromString(source, 'image/svg+xml');
    const root = doc.documentElement;
    for (const element of Array.from(doc.getElementsByTagName('*'))) {
      if (!attributes[element.tagName]) throw new Error(`Unsupported element: ${element.tagName}`);
      for (const attr of Array.from(element.attributes)) {
        if (!attributes[element.tagName].includes(attr.name)) throw new Error(`Unsupported attribute: ${attr.name}`);
      }
      if (element !== root && element.parentNode !== root) throw new Error('Nested geometry is unsupported.');
    }
    if (root.tagName !== 'svg' || root.getAttribute('viewBox') !== '0 0 24 24' || root.getAttribute('fill') !== 'none' || root.getAttribute('stroke') !== 'currentColor' || root.getAttribute('stroke-width') !== '2' || root.getAttribute('stroke-linecap') !== 'round' || root.getAttribute('stroke-linejoin') !== 'round') {
      throw new Error('Source does not match pinned Lucide presentation.');
    }
    const paths = [];
    const number = (el, name, fallback = 0) => {
      const value = el.hasAttribute(name) ? Number(el.getAttribute(name)) : fallback;
      if (!Number.isFinite(value)) throw new Error(`Invalid ${name}`);
      return value;
    };
    for (const el of Array.from(root.childNodes)) {
      if (el.nodeType === 3 && !el.textContent.trim()) continue;
      if (el.nodeType !== 1) throw new Error('Unsupported SVG child.');
      let d;
      if (el.tagName === 'circle') {
        const x = number(el, 'cx'), y = number(el, 'cy'), r = number(el, 'r');
        if (r <= 0) throw new Error('Invalid circle radius.');
        if (el.hasAttribute('fill')) {
          if (el.getAttribute('fill') !== 'currentColor') throw new Error('Unsupported fill.');
          paths.push(circlePath(x, y, r));
        }
        paths.push(circlePath(x, y, r + 1) + (r > 1 ? circlePath(x, y, r - 1, true) : ''));
        continue;
      }
      if (el.tagName === 'path') d = el.getAttribute('d');
      if (el.tagName === 'line') d = `M${number(el, 'x1')} ${number(el, 'y1')}L${number(el, 'x2')} ${number(el, 'y2')}`;
      if (el.tagName === 'polyline' || el.tagName === 'polygon') {
        const points = el.getAttribute('points').trim().split(/[\s,]+/).map(Number);
        if (points.length < 4 || points.length % 2 || !points.every(Number.isFinite)) throw new Error('Invalid points.');
        d = `M${points[0]} ${points[1]}L${points.slice(2).join(' ')}${el.tagName === 'polygon' ? 'Z' : ''}`;
      }
      if (el.tagName === 'circle' || el.tagName === 'ellipse') {
        const x = number(el, 'cx'), y = number(el, 'cy');
        const rx = number(el, el.tagName === 'circle' ? 'r' : 'rx');
        const ry = el.tagName === 'circle' ? rx : number(el, 'ry');
        if (rx <= 0 || ry <= 0) throw new Error('Invalid ellipse radii.');
        d = `M${x-rx} ${y}a${rx} ${ry} 0 1 0 ${2*rx} 0a${rx} ${ry} 0 1 0 ${-2*rx} 0Z`;
      }
      if (el.tagName === 'rect') {
        const x = number(el, 'x'), y = number(el, 'y'), w = number(el, 'width'), h = number(el, 'height');
        let rx = number(el, 'rx', number(el, 'ry')), ry = number(el, 'ry', rx);
        if (w <= 0 || h <= 0 || rx < 0 || ry < 0) throw new Error('Invalid rectangle.');
        rx = Math.min(rx, w / 2); ry = Math.min(ry, h / 2);
        d = rx && ry ? `M${x+rx} ${y}H${x+w-rx}A${rx} ${ry} 0 0 1 ${x+w} ${y+ry}V${y+h-ry}A${rx} ${ry} 0 0 1 ${x+w-rx} ${y+h}H${x+rx}A${rx} ${ry} 0 0 1 ${x} ${y+h-ry}V${y+ry}A${rx} ${ry} 0 0 1 ${x+rx} ${y}Z` : `M${x} ${y}h${w}v${h}h${-w}Z`;
      }
      const parsed = svgpath(d);
      if (parsed.err) throw new Error(parsed.err);
      // Skia's SVG reader needs separated arc flags, unlike SVG's compact grammar.
      const path = kit.FromSVGString(parsed.toString());
      if (!path) throw new Error('Skia could not parse geometry.');
      try {
        if (el.hasAttribute('fill')) {
          if (el.getAttribute('fill') !== 'currentColor') throw new Error('Unsupported fill.');
          paths.push(path.toSVGString());
        }
        if (!path.stroke({ width: 2, cap: kit.StrokeCap.ROUND, join: kit.StrokeJoin.ROUND })) throw new Error('Skia could not expand stroke.');
        paths.push(path.toSVGString());
      } finally { path.delete(); }
    }
    if (!paths.length || paths.some(d => !d || /[^a-zA-Z0-9., +\-]/.test(d))) throw new Error('Invalid generated paths.');
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">${paths.map(d => `<path fill="currentColor" d="${d}"/>`).join('')}</svg>`;
  };
}

module.exports = { createConverter };
