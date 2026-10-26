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
 * The saved settings, each checked again on read: a value out of range or a style address that
 * fails the rules counts as the default, so nothing bad reaches a page.
 */
final class Config
{
    public const STYLE_DEFAULT      = 'https://tiles.openfreemap.org/styles/liberty';
    public const DARK_STYLE_DEFAULT = 'https://tiles.openfreemap.org/styles/dark';

    public const PINS_DEFAULT = 200;
    public const PINS_MIN     = 50;
    public const PINS_MAX     = 500;

    public const ZOOM_DEFAULT = 2;
    public const ZOOM_MIN     = 0;
    public const ZOOM_MAX     = 18;

    /** Longest style address taken. */
    public const URL_MAX = 500;

    /** Where a keyed provider's style address takes the key. */
    public const KEY_PLACEHOLDER = '{key}';

    /** fn(string $name): mixed -- replaces the settings store in tests. */
    public static ?Closure $reader = null;

    public static function styleUrl(): string
    {
        $url = trim((string) self::value('style_url'));

        return self::validStyleUrl($url) ? $url : self::STYLE_DEFAULT;
    }

    /** The style for a dark page; '' uses the light one. */
    public static function darkStyleUrl(): string
    {
        $url = self::value('style_url_dark');
        if ($url === null) {
            return self::DARK_STYLE_DEFAULT;
        }
        $url = trim((string) $url);

        return self::validStyleUrl($url) ? $url : '';
    }

    public static function apiKey(): string
    {
        $key = trim((string) self::value('api_key'));

        return self::validKey($key) ? $key : '';
    }

    /** A style address with the key put in place of {key}. */
    public static function withKey(string $url): string
    {
        if ($url === '' || strpos($url, self::KEY_PLACEHOLDER) === false) {
            return $url;
        }

        return str_replace(self::KEY_PLACEHOLDER, rawurlencode(self::apiKey()), $url);
    }

    /** @return array{0:float,1:float}|null latitude, longitude; null when the admin set none (fit to the listings) */
    public static function centre(): ?array
    {
        $lat = self::number('center_lat', -90.0, 90.0);
        $lng = self::number('center_lng', -180.0, 180.0);

        return $lat === null || $lng === null ? null : array($lat, $lng);
    }

    public static function zoom(): float
    {
        return self::number('zoom', self::ZOOM_MIN, self::ZOOM_MAX) ?? (float) self::ZOOM_DEFAULT;
    }

    public static function maxPins(): int
    {
        $value = self::value('max_pins');
        if (!is_numeric($value)) {
            return self::PINS_DEFAULT;
        }

        return max(self::PINS_MIN, min(self::PINS_MAX, (int) $value));
    }

    /** Draw the map above the search results by itself. On unless switched off. */
    public static function onSearchPage(): bool
    {
        $value = self::value('on_search');

        return $value === null || $value === '' ? true : (bool) (int) $value;
    }

    /**
     * A style address the map may load: https, at most URL_MAX long, and never the
     * OpenStreetMap tile servers, which are not for use by sites like this one.
     */
    public static function validStyleUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::URL_MAX || preg_match('/[\s"\'<>\\\\]/', $url)) {
            return false;
        }
        $check = str_replace(self::KEY_PLACEHOLDER, 'key', $url);
        if (filter_var($check, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($check, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        $host = strtolower((string) parse_url($check, PHP_URL_HOST));

        return $host !== '' && !self::blockedHost($host);
    }

    public static function blockedHost(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');

        return $host === 'tile.openstreetmap.org' || str_ends_with($host, '.tile.openstreetmap.org');
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{0,200}$/', $key) === 1;
    }

    private static function number(string $name, float $min, float $max): ?float
    {
        $value = self::value($name);
        if (!is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        return $value < $min || $value > $max ? null : $value;
    }

    private static function value(string $name): mixed
    {
        if (self::$reader !== null) {
            return (self::$reader)($name);
        }

        return osc_settings_value(Plugin::PAGE, $name);
    }
}
