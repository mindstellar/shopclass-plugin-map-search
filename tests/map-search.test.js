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

const fake = () => {
  const calls = [];
  return { calls, fitBounds: (b, o) => calls.push(['fitBounds', b, o]), easeTo: (o) => calls.push(['easeTo', o]), jumpTo: (o) => calls.push(['jumpTo', o]) };
};
const pin = (lat, lng) => ({ lat, lng });

t('start order: box, then near, then centre, then fit', () => {
  const base = { zoom: 2, box: null, near: null, center: null };
  assert.strictEqual(m.startPlan({ ...base, box: [1, 2, 3, 4], near: [5, 6], center: [7, 8] }).mode, 'box');
  assert.strictEqual(m.startPlan({ ...base, near: [5, 6], center: [7, 8] }).mode, 'near');
  assert.deepStrictEqual(m.startPlan({ ...base, near: [5, 6] }).center, [6, 5]);
  assert.strictEqual(m.startPlan({ ...base, near: [5, 6] }).zoom, 9);
  assert.deepStrictEqual(m.startPlan({ ...base, center: [19, 72] }), { mode: 'centre', center: [72, 19], zoom: 2 });
  const fit = m.startPlan(base);
  assert.strictEqual(fit.mode, 'fit');
  assert.ok(fit.zoom >= 1 && fit.zoom <= 2);
});

t('a box across the date line', () => assert.deepStrictEqual(m.startPlan({ box: [170, -10, -170, 10] }).bounds, [[170, -10], [190, 10]]));

t('fit: pins give one fitBounds with padding and a zoom cap', () => {
  const map = fake();
  assert.strictEqual(m.fitPins(map, [pin(19, 72), pin(28, 77), pin(18, 73)], false), true);
  assert.strictEqual(map.calls.length, 1);
  assert.deepStrictEqual(map.calls[0], ['fitBounds', [[72, 18], [77, 28]], { padding: 50, maxZoom: 13, animate: true }]);
});

t('fit: one pin zooms to 12', () => {
  const map = fake();
  m.fitPins(map, [pin(19, 72), pin(19, 72)], false);
  assert.deepStrictEqual(map.calls, [['easeTo', { center: [72, 19], zoom: 12 }]]);
});

t('fit: no pins, no move', () => {
  const map = fake();
  assert.strictEqual(m.fitPins(map, [], false), false);
  assert.strictEqual(m.fitPins(map, [{ lat: 'x', lng: 1 }, null], false), false);
  assert.strictEqual(map.calls.length, 0);
});

t('fit: reduced motion does not animate', () => {
  const a = fake();
  m.fitPins(a, [pin(1, 1), pin(2, 2)], true);
  assert.strictEqual(a.calls[0][2].animate, false);
  const b = fake();
  m.fitPins(b, [pin(1, 1)], true);
  assert.strictEqual(b.calls[0][0], 'jumpTo');
});

console.log('\nRESULT: ' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
