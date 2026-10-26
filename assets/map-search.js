/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The listings map: reads its config block, draws MapLibre with clustered pins, loads the pins
 * for the view after each move, and keeps the "Search this area" link on the view's box.
 */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    api.start();
  }
})(this, function () {
  'use strict';

  var DEBOUNCE = 350;
  var SOURCE = 'map-search';
  var OPS = ['get', 'literal', 'case', 'match', 'coalesce', 'step', 'interpolate', 'concat', 'to-string'];

  function round(n, up) {
    var f = up ? Math.ceil(n * 1e4 - 1e-6) : Math.floor(n * 1e4 + 1e-6);
    return f / 1e4;
  }

  function wrap(lng) {
    return ((lng + 180) % 360 + 360) % 360 - 180;
  }

  /** The view's edges as a bbox core takes: longitudes within ±180, west past east across the date line. */
  function boxFromBounds(west, south, east, north) {
    var s = Math.max(-90, round(south, false));
    var n = Math.min(90, round(north, true));
    if (east - west >= 360) {
      return [-180, s, 180, n];
    }
    var w = round(wrap(west), false);
    var e = round(wrap(east), true);
    if (e === -180 && east > west) {
      e = 180;
    }
    w = Math.max(-180, w);
    e = Math.min(180, e);
    return [w, s, e, n];
  }

  function bboxText(box) {
    return box.map(function (n) {
      var t = String(Number(n.toFixed(4)));
      return t === '-0' ? '0' : t;
    }).join(',');
  }

  /** A same-site http(s) address, or '' for anything else. */
  function safeUrl(url, base) {
    if (typeof url !== 'string' || url === '') return '';
    try {
      var u = new URL(url, base);
      return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : '';
    } catch (e) {
      return '';
    }
  }

  function searchHref(template, token, box) {
    if (!template || !token || template.indexOf(token) === -1) return '';
    return template.split(token).join(bboxText(box));
  }

  function pinsUrl(base, params, box, locale, origin) {
    var u = new URL(base, origin);
    Object.keys(params || {}).forEach(function (key) {
      var v = params[key];
      if (v !== null && typeof v === 'object') {
        var list = Array.isArray(v);
        Object.keys(v).forEach(function (k) {
          u.searchParams.append(key + (list ? '[]' : '[' + k + ']'), String(v[k]));
        });
      } else if (v !== undefined && v !== null && v !== '') {
        u.searchParams.set(key, String(v));
      }
    });
    u.searchParams.set('bbox', bboxText(box));
    if (locale) u.searchParams.set('hl', locale);
    return u.href;
  }

  function geojson(pins) {
    return {
      type: 'FeatureCollection',
      features: (pins || []).filter(function (p) {
        return p && isFinite(p.lat) && isFinite(p.lng);
      }).map(function (p) {
        return {
          type: 'Feature',
          geometry: { type: 'Point', coordinates: [Number(p.lng), Number(p.lat)] },
          properties: {
            id: p.id, title: String(p.title || ''), price: p.price ? String(p.price) : '',
            url: String(p.url || ''), thumb: p.thumb ? String(p.thumb) : '', approx: !!p.approx
          }
        };
      })
    };
  }

  function format(text, n) {
    return String(text).replace('%d', String(n));
  }

  function isDark(doc, win) {
    var h = doc.documentElement;
    var t = (h.getAttribute('data-theme') || h.getAttribute('data-bs-theme') || '').toLowerCase();
    if (t === 'dark') return true;
    if (t === 'light') return false;
    if (h.classList.contains('dark')) return true;
    return !!(win.matchMedia && win.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  /** The font the style's own labels use, so the cluster counts have glyphs. */
  function styleFont(style) {
    if (!style || !style.glyphs || !Array.isArray(style.layers)) return null;
    for (var i = 0; i < style.layers.length; i++) {
      var l = style.layers[i];
      var f = l.type === 'symbol' && l.layout ? l.layout['text-font'] : null;
      if (Array.isArray(f) && f.length && OPS.indexOf(f[0]) === -1 && f.every(function (x) { return typeof x === 'string'; })) return f;
    }
    return null;
  }

  function popupNode(p, text) {
    var href = safeUrl(p.url, location.href);
    var box = document.createElement(href ? 'a' : 'div');
    box.className = 'map-search-popup';
    if (href) box.href = href;
    var thumb = safeUrl(p.thumb, location.href);
    if (thumb) {
      var img = document.createElement('img');
      img.src = thumb;
      img.alt = '';
      img.width = 64;
      img.height = 64;
      img.loading = 'lazy';
      box.appendChild(img);
    }
    var body = document.createElement('span');
    body.className = 'map-search-popup-body';
    var title = document.createElement('strong');
    title.textContent = p.title;
    body.appendChild(title);
    if (p.price) {
      var price = document.createElement('span');
      price.className = 'map-search-popup-price';
      price.textContent = p.price;
      body.appendChild(price);
    }
    if (p.approx === true || p.approx === 'true') {
      var approx = document.createElement('small');
      approx.textContent = text.approx;
      body.appendChild(approx);
    }
    box.appendChild(body);
    return box;
  }

  function colour(el, name, fallback) {
    var v = getComputedStyle(el).getPropertyValue(name).trim();
    return v || fallback;
  }

  function init(el) {
    var cfgEl = el.querySelector('.map-search-config');
    var canvas = el.querySelector('.map-search-canvas');
    var status = el.querySelector('.map-search-status');
    var area = el.querySelector('.map-search-area');
    var cfg;
    try {
      cfg = JSON.parse(cfgEl.textContent);
    } catch (e) {
      return;
    }
    var text = cfg.text || {};
    var gl = window.maplibregl;
    if (!gl || !canvas) {
      status.textContent = text.noMap || '';
      return;
    }

    var dark = isDark(document, window);
    var styleFor = function (d) { return d && cfg.styleDark ? cfg.styleDark : cfg.style; };
    var opts = {
      container: canvas,
      style: styleFor(dark),
      center: [cfg.center[1], cfg.center[0]],
      zoom: cfg.zoom,
      attributionControl: false,
      locale: {
        'NavigationControl.ZoomIn': text.zoomIn,
        'NavigationControl.ZoomOut': text.zoomOut,
        'NavigationControl.ResetBearing': text.compass,
        'AttributionControl.ToggleAttribution': text.credits,
        'Popup.Close': text.close
      }
    };
    if (Array.isArray(cfg.box) && cfg.box.length === 4) {
      var east = cfg.box[2] < cfg.box[0] ? cfg.box[2] + 360 : cfg.box[2];
      opts.bounds = [[cfg.box[0], cfg.box[1]], [east, cfg.box[3]]];
    } else if (Array.isArray(cfg.near) && cfg.near.length === 2) {
      opts.center = [cfg.near[1], cfg.near[0]];
      opts.zoom = Math.max(cfg.zoom, 9);
    }

    var map;
    el.classList.add('is-ready');
    try {
      map = new gl.Map(opts);
    } catch (e) {
      el.classList.remove('is-ready');
      status.textContent = text.noMap || '';
      return;
    }
    map.getCanvas().setAttribute('aria-label', text.map || '');
    map.addControl(new gl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new gl.AttributionControl({ compact: false, customAttribution: cfg.credit }), 'bottom-right');

    var data = geojson([]);
    var startBox = Array.isArray(cfg.box) ? bboxText(cfg.box) : '';
    var cache = {};
    var keys = [];
    var timer = 0;
    var aborter = null;
    var popup = null;

    function colours() {
      return {
        pin: colour(el, '--map-search-pin', '#c2410c'),
        cluster: colour(el, '--map-search-cluster', '#1d4ed8'),
        text: colour(el, '--map-search-cluster-text', '#ffffff'),
        ring: colour(el, '--map-search-ring', '#ffffff')
      };
    }

    function recolour() {
      var c = colours();
      if (map.getLayer(SOURCE + '-clusters')) {
        map.setPaintProperty(SOURCE + '-clusters', 'circle-color', c.cluster);
        map.setPaintProperty(SOURCE + '-clusters', 'circle-stroke-color', c.ring);
      }
      if (map.getLayer(SOURCE + '-count')) map.setPaintProperty(SOURCE + '-count', 'text-color', c.text);
      if (map.getLayer(SOURCE + '-pins')) {
        map.setPaintProperty(SOURCE + '-pins', 'circle-color', c.pin);
        map.setPaintProperty(SOURCE + '-pins', 'circle-stroke-color', c.ring);
      }
    }

    function addLayers() {
      if (map.getSource(SOURCE)) return;
      var c = colours();
      map.addSource(SOURCE, { type: 'geojson', data: data, cluster: true, clusterRadius: 50, clusterMaxZoom: 14 });
      map.addLayer({
        id: SOURCE + '-clusters', type: 'circle', source: SOURCE, filter: ['has', 'point_count'],
        paint: {
          'circle-color': c.cluster,
          'circle-radius': ['step', ['get', 'point_count'], 15, 10, 19, 50, 24],
          'circle-stroke-width': 2, 'circle-stroke-color': c.ring
        }
      });
      var font = styleFont(map.getStyle());
      if (font) {
        map.addLayer({
          id: SOURCE + '-count', type: 'symbol', source: SOURCE, filter: ['has', 'point_count'],
          layout: { 'text-field': ['get', 'point_count_abbreviated'], 'text-font': font, 'text-size': 12, 'text-allow-overlap': true },
          paint: { 'text-color': c.text }
        });
      }
      map.addLayer({
        id: SOURCE + '-pins', type: 'circle', source: SOURCE, filter: ['!', ['has', 'point_count']],
        paint: { 'circle-color': c.pin, 'circle-radius': 7, 'circle-stroke-width': 2, 'circle-stroke-color': c.ring }
      });
    }

    function show(pins, more, cap) {
      data = geojson(pins);
      var src = map.getSource(SOURCE);
      if (src) src.setData(data);
      var n = data.features.length;
      status.textContent = n === 0 ? text.none : (more ? format(text.more, cap) : format(text.count, n));
    }

    function load() {
      var b = map.getBounds();
      var box = boxFromBounds(b.getWest(), b.getSouth(), b.getEast(), b.getNorth());
      var href = searchHref(cfg.search, cfg.token, box);
      if (area && href && safeUrl(href, location.href)) {
        area.href = href;
        if (bboxText(box) !== startBox) area.hidden = false;
      }
      var url = pinsUrl(cfg.pins, cfg.params, box, cfg.locale, location.href);
      if (cache[url]) {
        show(cache[url].pins, cache[url].more, cache[url].cap);
        return;
      }
      if (aborter) aborter.abort();
      aborter = typeof AbortController === 'function' ? new AbortController() : null;
      status.textContent = text.loading;
      fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: aborter ? aborter.signal : undefined })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); })
        .then(function (d) {
          cache[url] = d;
          keys.push(url);
          if (keys.length > 30) delete cache[keys.shift()];
          show(d.pins, d.more, d.cap);
        })
        .catch(function (e) {
          if (!e || e.name !== 'AbortError') status.textContent = text.error;
        });
    }

    function later() {
      clearTimeout(timer);
      timer = setTimeout(load, DEBOUNCE);
    }

    map.on('style.load', addLayers);
    map.on('load', load);
    map.on('moveend', later);

    map.on('click', SOURCE + '-clusters', function (e) {
      var f = e.features && e.features[0];
      if (!f) return;
      Promise.resolve(map.getSource(SOURCE).getClusterExpansionZoom(f.properties.cluster_id)).then(function (z) {
        map.easeTo({ center: f.geometry.coordinates, zoom: z });
      }).catch(function () {});
    });
    map.on('click', SOURCE + '-pins', function (e) {
      var f = e.features && e.features[0];
      if (!f) return;
      if (popup) popup.remove();
      popup = new gl.Popup({ offset: 12, maxWidth: '280px' })
        .setLngLat(f.geometry.coordinates.slice())
        .setDOMContent(popupNode(f.properties, text))
        .addTo(map);
    });
    [SOURCE + '-clusters', SOURCE + '-pins'].forEach(function (id) {
      map.on('mouseenter', id, function () { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, function () { map.getCanvas().style.cursor = ''; });
    });

    function scheme() {
      var d = isDark(document, window);
      if (d === dark) return;
      var before = styleFor(dark);
      dark = d;
      if (styleFor(d) !== before) {
        map.setStyle(styleFor(d), { diff: false });
      } else {
        recolour();
      }
    }
    if (window.MutationObserver) {
      new MutationObserver(scheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-bs-theme', 'class'] });
    }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) mq.addEventListener('change', scheme);
    }
  }

  function start() {
    var run = function () {
      var els = document.querySelectorAll('[data-map-search]');
      for (var i = 0; i < els.length; i++) init(els[i]);
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', run);
    } else {
      run();
    }
  }

  return {
    start: start,
    boxFromBounds: boxFromBounds,
    bboxText: bboxText,
    searchHref: searchHref,
    pinsUrl: pinsUrl,
    geojson: geojson,
    safeUrl: safeUrl,
    styleFont: styleFont,
    isDark: isDark
  };
});
