<?php
/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The settings: defaults, ranges, the style address rules and the checks the settings page runs.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\mapsearch\Config;
use mindstellar\mapsearch\Plugin;

harness_section('defaults');
settings(array());
pin('style is OpenFreeMap', 'https://tiles.openfreemap.org/styles/liberty', Config::styleUrl());
pin('dark style is OpenFreeMap dark', 'https://tiles.openfreemap.org/styles/dark', Config::darkStyleUrl());
pin('no key', '', Config::apiKey());
pin('centre', array(20.0, 0.0), Config::centre());
pin('zoom', 2.0, Config::zoom());
pin('max pins 200', 200, Config::maxPins());
pin('on the search page', true, Config::onSearchPage());

harness_section('the settings page declares the same defaults');
$page   = require __DIR__ . '/../settings.php';
$fields = array();
foreach ($page['groups'] as $group) {
    foreach ($group['fields'] as $field) {
        $fields[$field['name']] = $field;
    }
}
pin('menu', array('settings', 'general'), array($page['menu'], $page['group']));
pin('style default', Config::STYLE_DEFAULT, $fields['style_url']['default']);
pin('max pins default', '200', $fields['max_pins']['default']);
pin('max pins range', array('50', '500'), array($fields['max_pins']['attrs']['min'], $fields['max_pins']['attrs']['max']));
pin('key is a secret field', 'secret', $fields['api_key']['type']);
pin('secret says it can be read back', false, $fields['api_key']['write_only']);
pin('a blank key keeps the saved one', null, ($fields['api_key']['persist'])('  '));
pin('every stored field is removed on uninstall', array_values(array_diff(array_keys($fields), array('status'))), Plugin::PREFERENCES);

harness_section('ranges');
settings(array('max_pins' => '10'));
pin('below 50 is 50', 50, Config::maxPins());
settings(array('max_pins' => '9000'));
pin('above 500 is 500', 500, Config::maxPins());
settings(array('max_pins' => 'lots'));
pin('not a number is the default', 200, Config::maxPins());
settings(array('max_pins' => '350'));
pin('in range is kept', 350, Config::maxPins());
settings(array('zoom' => '25', 'center_lat' => '95', 'center_lng' => '-200'));
pin('zoom out of range is the default', 2.0, Config::zoom());
pin('centre out of range is the default', array(20.0, 0.0), Config::centre());
settings(array('zoom' => '11', 'center_lat' => '19.07', 'center_lng' => '72.87'));
pin('a set view', array(array(19.07, 72.87), 11.0), array(Config::centre(), Config::zoom()));
settings(array('on_search' => '0'));
pin('search page off', false, Config::onSearchPage());

harness_section('style addresses');
check('https is fine', Config::validStyleUrl('https://api.maptiler.com/maps/streets-v2/style.json?key={key}'));
check('http is refused', !Config::validStyleUrl('http://tiles.openfreemap.org/styles/liberty'));
check('javascript: is refused', !Config::validStyleUrl('javascript:alert(1)'));
check('a quote is refused', !Config::validStyleUrl('https://x.example/"onload="1'));
check('OSM tile server refused', !Config::validStyleUrl('https://tile.openstreetmap.org/{z}/{x}/{y}.png'));
check('OSM a. tile server refused', !Config::validStyleUrl('https://a.tile.openstreetmap.org/style.json'));
check('OSM with a trailing dot refused', !Config::validStyleUrl('https://tile.openstreetmap.org./style.json'));
check('too long refused', !Config::validStyleUrl('https://x.example/' . str_repeat('a', 500)));
settings(array('style_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'));
pin('a stored OSM address falls back to the default', Config::STYLE_DEFAULT, Config::styleUrl());
settings(array('style_url_dark' => ''));
pin('an empty dark style uses the light one', '', Config::darkStyleUrl());

harness_section('the key');
settings(array('api_key' => 'abc.DEF-12_3'));
pin('key goes in place of {key}', 'https://x.example/s.json?key=abc.DEF-12_3', Config::withKey('https://x.example/s.json?key={key}'));
pin('no placeholder, no key', 'https://x.example/s.json', Config::withKey('https://x.example/s.json'));
settings(array('api_key' => 'a&b=c'));
pin('a bad stored key is none', '', Config::apiKey());

harness_section('save checks');
pin('good style', null, Plugin::validateStyle('https://tiles.openfreemap.org/styles/bright'));
check('OSM style refused with its own message', str_contains((string) Plugin::validateStyle('https://tile.openstreetmap.org/x'), 'OpenStreetMap tile servers'));
check('http style refused', is_string(Plugin::validateStyle('http://x.example/s.json')));
pin('empty dark style is fine', null, Plugin::validateDarkStyle(''));
check('bad dark style refused', is_string(Plugin::validateDarkStyle('ftp://x.example/s.json')));
pin('good key', null, Plugin::validateKey('abc123'));
check('key with a space refused', is_string(Plugin::validateKey('abc 123')));
pin('50 pins fine', null, Plugin::validateRange('50', 50, 500, true));
pin('500 pins fine', null, Plugin::validateRange('500', 50, 500, true));
check('49 pins refused', is_string(Plugin::validateRange('49', 50, 500, true)));
check('501 pins refused', is_string(Plugin::validateRange('501', 50, 500, true)));
check('2.5 pins refused', is_string(Plugin::validateRange('2.5', 0, 500, true)));
pin('a decimal latitude is fine', null, Plugin::validateRange('-33.8688', -90, 90));
pin('range message', 'Use a number from -90 to 90.', Plugin::validateRange('91', -90, 90));

harness_section('status line');
[$on, $text] = Plugin::status(false, true, true);
check('off while distance search is off, and says so', !$on && str_contains($text, 'distance search is off'));
[$on, $text] = Plugin::status(true, false, true);
check('off with no listing on the map, and says so', !$on && str_contains($text, 'no live listing has a map position'));
[$on, $text] = Plugin::status(true, true, false);
check('on, placed by the theme', $on && str_contains($text, 'map_search_render()') && !str_contains($text, 'above the search results'));

harness_section('uninstall');
Plugin::uninstall();
pin('removes each preference', count(Plugin::PREFERENCES), count($GLOBALS['deleted']));
check('in its own section', $GLOBALS['deleted'][0] === 'map-search.style_url');

exit(harness_result());
