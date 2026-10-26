<?php

/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\mapsearch;

use Closure;

/**
 * The map's markup, its JSON config and its assets. The plugin owns the markup and the
 * script; a theme only places the map and styles it.
 */
final class Widget
{
    /** Stands in the search URL for the box the script fills in. */
    public const BOX_TOKEN = '__BBOX__';

    /** fn(): bool -- replaces "distance search is on and a listing has a point" in tests. */
    public static ?Closure $ready = null;

    private static bool $done = false;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return (bool) (self::$ready)();
        }

        return (bool) osc_geo_search_enabled() && Pins::any();
    }

    /**
     * The map, once per page, or '' when it cannot show. Queues the scripts when it draws.
     *
     * @param array<string,mixed> $opts see map_search_render()
     */
    public static function render(array $opts = array()): string
    {
        if (self::$done || !self::ready()) {
            return '';
        }
        self::$done = true;
        osc_register_script('maplibre-gl', Plugin::asset('assets/maplibre/maplibre-gl.js'));
        osc_register_script('map-search', Plugin::asset('assets/map-search.js'), array('maplibre-gl'));
        osc_enqueue_script('map-search');

        $params = Pins::searchParams((array) \Params::getParamsAsArray());

        return self::html(
            self::config($opts, $params, array(
                'pins'   => (string) osc_route_url(Plugin::ROUTE_PINS),
                'search' => (string) osc_search_url($params + array('bbox' => self::BOX_TOKEN)),
                'list'   => (string) osc_search_url($params === array() ? null : $params),
                'box'    => osc_search_bbox(),
                'near'   => osc_search_near(),
                'locale' => (string) osc_current_user_locale(),
            )),
            array(Plugin::asset('assets/maplibre/maplibre-gl.css'), Plugin::asset('assets/map-search.css')),
            self::cssClass((string) ($opts['class'] ?? ''))
        );
    }

    /** Let the next render() draw again, in tests. */
    public static function reset(): void
    {
        self::$done = false;
    }

    /**
     * What the script reads.
     *
     * @param array<string,mixed> $opts
     * @param array<string,mixed> $params the search's own filters
     * @param array<string,mixed> $page   pins, search and list URLs, box, near, locale
     *
     * @return array<string,mixed>
     */
    public static function config(array $opts, array $params, array $page): array
    {
        $centre = Config::centre();
        $zoom   = Config::zoom();
        if (isset($opts['center']) && is_array($opts['center']) && count($opts['center']) === 2) {
            [$oLat, $oLng] = array_values($opts['center']);
            if (is_numeric($oLat) && is_numeric($oLng) && abs((float) $oLat) <= 90 && abs((float) $oLng) <= 180) {
                $centre = array((float) $oLat, (float) $oLng);
            }
        }
        if (isset($opts['zoom']) && is_numeric($opts['zoom'])) {
            $zoom = max((float) Config::ZOOM_MIN, min((float) Config::ZOOM_MAX, (float) $opts['zoom']));
        }
        $dark = Config::darkStyleUrl();

        return array(
            'style'     => Config::withKey(Config::styleUrl()),
            'styleDark' => $dark === '' ? '' : Config::withKey($dark),
            'pins'      => (string) ($page['pins'] ?? ''),
            'search'    => ($opts['search_button'] ?? true) ? (string) ($page['search'] ?? '') : '',
            'token'     => self::BOX_TOKEN,
            'params'    => $params,
            'locale'    => (string) ($page['locale'] ?? ''),
            'center'    => $centre,
            'zoom'      => $zoom,
            'box'       => is_array($page['box'] ?? null) ? array_map('floatval', array_values($page['box'])) : null,
            'near'      => is_array($page['near'] ?? null) ? array_map('floatval', array_values($page['near'])) : null,
            'cap'       => Config::maxPins(),
            'credit'    => '© OpenStreetMap contributors',
            'list'      => (string) ($page['list'] ?? ''),
            'text'      => self::labels(),
        );
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return array(
            'map'      => __('Map of listings', 'map-search'),
            'area'     => __('Search this area', 'map-search'),
            'list'     => __('Show the listings as a list', 'map-search'),
            'loading'  => __('Loading listings…', 'map-search'),
            'count'    => __('Listings on the map: %d', 'map-search'),
            'more'     => __('The map shows the first %d listings here. Zoom in to see the rest.', 'map-search'),
            'none'     => __('No listings in this part of the map.', 'map-search'),
            'error'    => __('The map could not load listings. Try again later.', 'map-search'),
            'approx'   => __('Approximate location', 'map-search'),
            'noMap'    => __('Your browser cannot show the map.', 'map-search'),
            'zoomIn'   => __('Zoom in', 'map-search'),
            'zoomOut'  => __('Zoom out', 'map-search'),
            'compass'  => __('Reset north', 'map-search'),
            'credits'  => __('Map credits', 'map-search'),
            'close'    => __('Close', 'map-search'),
        );
    }

    /**
     * @param array<string,mixed> $config
     * @param string[]            $css    stylesheet URLs
     */
    public static function html(array $config, array $css = array(), string $class = ''): string
    {
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = $config['text'];
        $out  = '';
        foreach ($css as $url) {
            $out .= '<link rel="stylesheet" href="' . $e($url) . '">';
        }
        $json = (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return $out
            . '<section class="map-search' . ($class !== '' ? ' ' . $e($class) : '') . '" aria-label="' . $e($text['map']) . '" data-map-search>'
            . '<script type="application/json" class="map-search-config">' . $json . '</script>'
            . '<div class="map-search-canvas"></div>'
            . '<div class="map-search-bar">'
            . ($config['search'] !== '' ? '<a class="map-search-area" href="' . $e((string) $config['list']) . '" hidden>' . $e($text['area']) . '</a>' : '')
            . '<p class="map-search-status" role="status" aria-live="polite"></p>'
            . '</div>'
            . '<a class="map-search-list" href="' . $e((string) $config['list']) . '">' . $e($text['list']) . '</a>'
            . '</section>';
    }

    /** Extra class names from a theme: letters, digits, dashes and underscores only. */
    public static function cssClass(string $class): string
    {
        $names = preg_split('/\s+/', trim($class)) ?: array();
        $names = array_filter($names, static fn (string $n): bool => preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $n) === 1);

        return implode(' ', array_slice(array_values($names), 0, 5));
    }
}
