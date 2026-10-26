<?php
/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The map's markup: nothing while it cannot show, assets only when it draws, once per page,
 * escaped config, and the placement above the search results.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\mapsearch\Pins;
use mindstellar\mapsearch\Plugin;
use mindstellar\mapsearch\Widget;

Plugin::$file = '/x/map-search/index.php';

function fresh(bool $ready = true, array $settings = array()): void
{
    settings($settings);
    Widget::reset();
    Widget::$ready          = static fn (): bool => $ready;
    $GLOBALS['scripts']     = array();
    $GLOBALS['filters']     = array();
    $GLOBALS['view']        = array('bbox' => null, 'near' => null, 'search_page' => true);
    Params::$values         = array();
}

function config_of(string $html): array
{
    preg_match('#<script type="application/json" class="map-search-config">(.*?)</script>#s', $html, $m);

    return (array) json_decode($m[1] ?? 'null', true);
}

harness_section('nothing when it cannot show');
fresh(false);
pin('render prints nothing', '', Widget::render());
pin('no script queued', array(), $GLOBALS['scripts']);
ob_start();
map_search_render();
pin('the theme helper prints nothing', '', ob_get_clean());

harness_section('ready() through the real checks');
fresh();
Widget::$ready   = null;
$GLOBALS['geo']  = false;
Pins::$search    = static fn (): array => array(row(1));
pin('off while distance search is off', false, Widget::ready());
pin('and nothing is drawn', '', Widget::render());
$GLOBALS['geo']   = true;
$GLOBALS['cache'] = array();
Pins::$search     = static fn (): array => array();
pin('off while no listing has a point', false, Widget::ready());
$GLOBALS['cache'] = array();
Pins::$search     = static fn (): array => array(row(1));
pin('on with both', true, Widget::ready());

harness_section('drawn');
fresh();
Params::$values = array('sCategory' => '3', 'sPattern' => 'bike', 'iPage' => '4', 'sEmail' => 'x@y.z');
$GLOBALS['view']['bbox'] = array(72.8, 18.9, 73.1, 19.3);
$html = Widget::render();
pin('script queued', array('map-search'), $GLOBALS['scripts']['enqueued'] ?? array());
pin('needs MapLibre', array('maplibre-gl'), $GLOBALS['scripts']['registered']['map-search'][1]);
pin('MapLibre from the plugin, not a CDN', 'https://shop.test/oc-content/plugins/map-search/assets/maplibre/maplibre-gl.js?v=1', $GLOBALS['scripts']['registered']['maplibre-gl'][0]);
check('both stylesheets linked', str_contains($html, 'assets/maplibre/maplibre-gl.css?v=1') && str_contains($html, 'assets/map-search.css?v=1'));
check('a labelled region', str_contains($html, '<section class="map-search" aria-label="Map of listings" data-map-search>'));
check('a status line read out', str_contains($html, 'role="status" aria-live="polite"'));
check('a list link', preg_match('#<a class="map-search-list" href="https://shop.test/search\?sPattern=bike&amp;sCategory=3">Show the listings as a list</a>#', $html) === 1);
check('a hidden Search this area link', str_contains($html, '<a class="map-search-area" href="') && str_contains($html, '" hidden>Search this area</a>'));
$cfg = config_of($html);
pin('style', 'https://tiles.openfreemap.org/styles/liberty', $cfg['style']);
pin('pins route', 'https://shop.test/map-search/pins', $cfg['pins']);
pin('search filters only, no page', array('sPattern' => 'bike', 'sCategory' => '3'), $cfg['params']);
check('search URL keeps the filters and has the box token', str_contains($cfg['search'], 'sCategory=3') && str_contains($cfg['search'], 'bbox=' . Widget::BOX_TOKEN) && !str_contains($cfg['search'], 'iPage'));
pin('the current box', array(72.8, 18.9, 73.1, 19.3), $cfg['box']);
pin('the cap', 200, $cfg['cap']);
pin('the map credit', '© OpenStreetMap contributors', $cfg['credit']);
pin('a second render on the page prints nothing', '', Widget::render());
pin('and queues nothing more', 1, count($GLOBALS['scripts']['enqueued']));

harness_section('config is escaped for a script block');
fresh(true, array('style_url' => 'https://x.example/s.json?a=</script><b>'));
Params::$values = array('sPattern' => '</script><script>alert(1)</script>"\'&');
$html  = Widget::render();
$inner = substr($html, strpos($html, 'class="map-search-config">') + 26);
$inner = substr($inner, 0, strpos($inner, '</script>'));
check('no raw < > & \' in the block', strpbrk($inner, "<>&'") === false, $inner);
pin('the pattern survives the round trip', '</script><script>alert(1)</script>"\'&', json_decode($inner, true)['params']['sPattern']);
pin('a bad stored style is not used', 'https://tiles.openfreemap.org/styles/liberty', json_decode($inner, true)['style']);
check('the list link is escaped', !str_contains($html, '<script>alert(1)'));

harness_section('options from a theme');
fresh();
$html = Widget::render(array('class' => 'home-map "><b> x', 'center' => array(51.5, -0.12), 'zoom' => 30, 'search_button' => false));
check('only safe class names', str_contains($html, '<section class="map-search home-map x"'));
$cfg = config_of($html);
pin('centre', array(51.5, -0.12), $cfg['center']);
pin('zoom held to 18', 18.0, (float) $cfg['zoom']);
pin('no Search this area', '', $cfg['search']);
check('no area link', !str_contains($html, 'map-search-area'));
fresh(true, array('api_key' => 'k3y', 'style_url' => 'https://api.example/s.json?key={key}', 'style_url_dark' => ''));
$cfg = config_of(Widget::render());
pin('the key is put in the style', 'https://api.example/s.json?key=k3y', $cfg['style']);
pin('no dark style', '', $cfg['styleDark']);

harness_section('placed above the search results');
fresh();
check('on the search page', Plugin::autoPlaces(true));
check('not on other pages', !Plugin::autoPlaces(false));
fresh(true, array('on_search' => '0'));
check('not when switched off', !Plugin::autoPlaces(true));
fresh();
$GLOBALS['filters'][Plugin::AUTO_FILTER] = static fn ($on) => false;
check('not when the theme places it', !Plugin::autoPlaces(true));
ob_start();
Plugin::autoPlace();
pin('and the hook prints nothing', '', ob_get_clean());
fresh();
$GLOBALS['view']['search_page'] = false;
ob_start();
Plugin::autoPlace();
pin('the hook prints nothing on the home page', '', ob_get_clean());
pin('nor queues the script', array(), $GLOBALS['scripts']);
fresh();
ob_start();
Plugin::autoPlace();
check('the hook draws the map on the search page', str_contains((string) ob_get_clean(), 'data-map-search'));
ob_start();
map_search_render();
pin('a theme call after it prints nothing', '', ob_get_clean());

exit(harness_result());
