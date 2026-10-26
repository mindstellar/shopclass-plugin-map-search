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

/**
 * Install and uninstall, the settings checks and status line, and placing the map above the
 * search results.
 */
final class Plugin
{
    public const PAGE       = 'map-search';
    public const ROUTE_PINS = 'map-search-pins';

    /** The filter a theme returns false from to place the map itself. */
    public const AUTO_FILTER = 'map_search_auto_place';

    /** Every preference the settings page stores. */
    public const PREFERENCES = array('style_url', 'style_url_dark', 'api_key', 'center_lat', 'center_lng', 'zoom', 'max_pins', 'on_search');

    /** The plugin's index.php, set by it. */
    public static string $file = '';

    public static function install(): void
    {
    }

    public static function uninstall(): void
    {
        foreach (self::PREFERENCES as $name) {
            osc_delete_preference($name, self::PAGE);
        }
    }

    /** A plugin file as a web address, with the version for cache busting. */
    public static function asset(string $path): string
    {
        return osc_asset_url_versioned(osc_plugin_url(self::$file) . $path);
    }

    /** The `search_ads_listing_top` hook: the map above the results, on the search page only. */
    public static function autoPlace(): void
    {
        if (!self::autoPlaces(function_exists('osc_is_search_page') && osc_is_search_page())) {
            return;
        }
        echo Widget::render();
    }

    public static function autoPlaces(bool $searchPage): bool
    {
        return $searchPage && Config::onSearchPage() && (bool) osc_apply_filter(self::AUTO_FILTER, true);
    }

    /** Settings check: the light style address. */
    public static function validateStyle(mixed $value, array $field = array()): ?string
    {
        return Config::validStyleUrl(trim((string) $value)) ? null : self::styleError(trim((string) $value));
    }

    /** Settings check: the dark style address, which may be empty. */
    public static function validateDarkStyle(mixed $value, array $field = array()): ?string
    {
        $value = trim((string) $value);

        return $value === '' || Config::validStyleUrl($value) ? null : self::styleError($value);
    }

    public static function validateKey(mixed $value, array $field = array()): ?string
    {
        return Config::validKey(trim((string) $value)) ? null : __('Use the key as the provider gives it: letters, digits, dots, dashes and underscores.', 'map-search');
    }

    /** Both centre fields set, or both empty. */
    public static function validateCentre(array $values, string $pageId = '', mixed $id = null): ?string
    {
        $lat = trim((string) ($values['center_lat'] ?? ''));
        $lng = trim((string) ($values['center_lng'] ?? ''));

        return ($lat === '') === ($lng === '') ? null : __('Set both centre latitude and longitude, or leave both empty.', 'map-search');
    }

    public static function validateOptionalRange(mixed $value, float $min, float $max): ?string
    {
        return trim((string) $value) === '' ? null : self::validateRange($value, $min, $max);
    }

    public static function validateRange(mixed $value, float $min, float $max, bool $whole = false): ?string
    {
        $value = trim((string) $value);
        $ok    = is_numeric($value) && (float) $value >= $min && (float) $value <= $max
            && (!$whole || preg_match('/^-?[0-9]+$/', $value) === 1);

        return $ok ? null : sprintf(__('Use a number from %1$s to %2$s.', 'map-search'), self::num($min), self::num($max));
    }

    /**
     * Why the map shows or not, for the settings page.
     *
     * @return array{0:bool,1:string} whether it shows, and the line to print
     */
    public static function status(bool $geoOn, bool $hasPoints, bool $onSearch): array
    {
        if (!$geoOn) {
            return array(false, __('The map does not show: distance search is off. Turn it on under Settings → General.', 'map-search'));
        }
        if (!$hasPoints) {
            return array(false, __('The map does not show: no live listing has a map position yet. A listing gets one from its city or region, or from its address when an address lookup plugin is set up.', 'map-search'));
        }

        return array(true, $onSearch
            ? __('The map is on. It shows above the search results, and where your theme calls map_search_render().', 'map-search')
            : __('The map is on. It shows where your theme calls map_search_render().', 'map-search'));
    }

    /** The custom status row on the settings page. */
    public static function drawStatus(): void
    {
        $geoOn       = (bool) osc_geo_search_enabled();
        [$on, $text] = self::status($geoOn, $geoOn && Pins::any(), Config::onSearchPage());
        echo '<p class="' . ($on ? 'text-success' : 'text-warning') . '">' . osc_esc_html($text) . '</p>';
    }

    private static function styleError(string $value): string
    {
        $host = strtolower((string) parse_url(str_replace(Config::KEY_PLACEHOLDER, 'key', $value), PHP_URL_HOST));
        if ($host !== '' && Config::blockedHost($host)) {
            return __('The OpenStreetMap tile servers are not for sites like this one. Use OpenFreeMap or another provider.', 'map-search');
        }

        return __('Use the https address of a MapLibre style JSON.', 'map-search');
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
    }
}
