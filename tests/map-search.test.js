/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The map script's pure parts: the view's box, the URLs it builds and the pins it draws.
 */
'use strict';

const assert = require('assert');
const m = require('../assets/map-search.js');

let pass = 0;
let fail = 0;
function t(name, fn) {
  try {
    fn();
    pass++;
    console.log('PASS  ' + name);
  } catch (e) {
    fail++;
    console.log('FAIL  ' + name + '\n        ' + e.message);
  }
}

t('a plain view', () => assert.deepStrictEqual(m.boxFromBounds(72.81234, 18.95, 73.1, 19.3), [72.8123, 18.95, 73.1, 19.3]));
t('edges round outwards', () => assert.deepStrictEqual(m.boxFromBounds(1.00001, 1.00001, 1.99991, 1.99991), [1, 1, 2, 2]));
t('the whole world', () => assert.deepStrictEqual(m.boxFromBounds(-400, -85, 400, 85), [-180, -85, 180, 85]));
t('a view across the date line', () => assert.deepStrictEqual(m.boxFromBounds(170, -10, 190, 10), [170, -10, -170, 10]));
t('a view past the date line on the west', () => assert.deepStrictEqual(m.boxFromBounds(-190, -10, -170, 10), [170, -10, -170, 10]));
t('a view ending on 180', () => assert.deepStrictEqual(m.boxFromBounds(160, 0, 180, 10), [160, 0, 180, 10]));
t('latitudes held to 90', () => assert.deepStrictEqual(m.boxFromBounds(0, -95, 10, 95), [0, -90, 10, 90]));
t('bbox text', () => assert.strictEqual(m.bboxText([-0.00001, 18.9, 73.1, 19.3]), '0,18.9,73.1,19.3'));

t('search link fills the token', () => assert.strictEqual(
  m.searchHref('https://shop.test/search/category,3/bbox,__BBOX__', '__BBOX__', [1, 2, 3, 4]),
  'https://shop.test/search/category,3/bbox,1,2,3,4'
));
t('no token, no search link', () => assert.strictEqual(m.searchHref('https://shop.test/search', '__BBOX__', [1, 2, 3, 4]), ''));

t('pins URL carries the filters, box and locale', () => {
  const u = new URL(m.pinsUrl('https://shop.test/index.php?page=custom&route=map-search-pins',
    { sCategory: ['3', '4'], meta: { 5: 'Blue' }, sPattern: 'red bike', sCity: '' }, [1, 2, 3, 4], 'en_US', 'https://shop.test/'));
  assert.strictEqual(u.searchParams.get('route'), 'map-search-pins');
  assert.deepStrictEqual(u.searchParams.getAll('sCategory[]'), ['3', '4']);
  assert.strictEqual(u.searchParams.get('meta[5]'), 'Blue');
  assert.strictEqual(u.searchParams.get('sPattern'), 'red bike');
  assert.strictEqual(u.searchParams.has('sCity'), false);
  assert.strictEqual(u.searchParams.get('bbox'), '1,2,3,4');
  assert.strictEqual(u.searchParams.get('hl'), 'en_US');
});

t('pins become points, bad ones are dropped', () => {
  const g = m.geojson([{ id: 1, lat: 19.07, lng: 72.87, title: 'A', price: '€ 5', url: '/i/1', thumb: null, approx: true }, { id: 2, lat: 'x', lng: 1 }, null]);
  assert.strictEqual(g.features.length, 1);
  assert.deepStrictEqual(g.features[0].geometry.coordinates, [72.87, 19.07]);
  assert.strictEqual(g.features[0].properties.thumb, '');
  assert.strictEqual(g.features[0].properties.approx, true);
});

t('only http(s) links', () => {
  assert.strictEqual(m.safeUrl('javascript:alert(1)', 'https://shop.test/'), '');
  assert.strictEqual(m.safeUrl('data:text/html,x', 'https://shop.test/'), '');
  assert.strictEqual(m.safeUrl('/item/5', 'https://shop.test/'), 'https://shop.test/item/5');
});

t('cluster counts use the style\'s own font', () => {
  assert.deepStrictEqual(m.styleFont({ glyphs: 'g', layers: [{ type: 'line' }, { type: 'symbol', layout: { 'text-font': ['Noto Sans Regular'] } }] }), ['Noto Sans Regular']);
  assert.strictEqual(m.styleFont({ layers: [{ type: 'symbol', layout: { 'text-font': ['X'] } }] }), null);
  assert.strictEqual(m.styleFont({ glyphs: 'g', layers: [{ type: 'symbol', layout: { 'text-font': ['get', 'f'] } }] }), null);
});

t('dark from the page, then the system', () => {
  const doc = (attrs) => ({ documentElement: { getAttribute: (n) => attrs[n] || null, classList: { contains: () => false } } });
  const win = (dark) => ({ matchMedia: () => ({ matches: dark }) });
  assert.strictEqual(m.isDark(doc({ 'data-theme': 'dark' }), win(false)), true);
  assert.strictEqual(m.isDark(doc({ 'data-theme': 'light' }), win(true)), false);
  assert.strictEqual(m.isDark(doc({ 'data-bs-theme': 'dark' }), win(false)), true);
  assert.strictEqual(m.isDark(doc({}), win(true)), true);
});

console.log('\nRESULT: ' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
