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
use mindstellar\search\query\BoxFilter;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchRunner;
use mindstellar\security\RateLimit;
use mindstellar\utility\Utils;

/**
 * GET map-search/pins?bbox=west,south,east,north and the search's own filters: the live
 * listings with a point in that box, from core's search, as JSON for the map.
 */
final class Pins
{
    /** The search page filters a pins request may carry; anything else is dropped. */
    public const KEYS = array(
        'sPattern', 'sCategory', 'sCountry', 'sRegion', 'sCity', 'sCityArea', 'sUser',
        'sPriceMin', 'sPriceMax', 'bPic', 'bPremium', 'meta', 'iRadius', 'sLat', 'sLng',
    );

    public const RATE_CONTEXT = 'map_search_pins';
    public const RATE_MAX     = 120;
    public const RATE_WINDOW  = 60;

    /** The same box and filters get the same pins for a minute, so a shared cache may keep them. */
    public const CACHE_OK = 'public, max-age=60, s-maxage=60';

    /** fn(array $params, int $pageSize): array -- replaces core's search in tests. */
    public static ?Closure $search = null;

    /** fn(string $key): bool -- replaces the rate limit in tests. */
    public static ?Closure $limiter = null;

    /** Seconds the answer of any() is kept. */
    public const ANY_TTL = 600;

    /**
     * The search filters kept from a request: the KEYS only, each a string or a flat list of
     * strings ('meta' may be keyed by field id). An empty value is dropped.
     *
     * @param array<string,mixed> $request
     *
     * @return array<string,string|array<int|string,string>>
     */
    public static function searchParams(array $request): array
    {
        $out = array();
        foreach (self::KEYS as $key) {
            $value = $request[$key] ?? null;
            if (is_array($value)) {
                $list = array();
                foreach ($value as $k => $v) {
                    if (is_scalar($v) && (string) $v !== '' && (is_int($k) || preg_match('/^[0-9]{1,10}$/', (string) $k))) {
                        $list[$k] = (string) $v;
                    }
                }
                if ($list !== array() && count($list) <= 50) {
                    $out[$key] = $list;
                }
            } elseif (is_scalar($value) && (string) $value !== '') {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * What core's search gets: the kept filters, the box and one page of $pageSize.
     *
     * @param array<string,mixed>                    $request
     * @param array{0:float,1:float,2:float,3:float} $box
     *
     * @return array<string,mixed>
     */
    public static function params(array $request, array $box, int $pageSize): array
    {
        return self::searchParams($request) + array(
            'bbox'      => BoxFilter::format($box),
            'iPage'     => '1',
            'iPagesize' => (string) $pageSize,
        );
    }

    /**
     * The answer to a pins request.
     *
     * @param array<string,mixed> $request
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function answer(array $request, bool $geoOn, int $cap): array
    {
        if (!$geoOn) {
            return array('status' => 404, 'body' => array('error' => 'off'));
        }
        $box = BoxFilter::parse($request['bbox'] ?? null);
        if ($box === null) {
            return array('status' => 422, 'body' => array('error' => 'bbox'));
        }
        $rows = self::search(self::params($request, $box, $cap + 1), $cap + 1);
        $more = count($rows) > $cap;
        $rows = array_slice($rows, 0, $cap);

        return array('status' => 200, 'body' => array(
            'pins' => self::pins($rows, self::covers($rows)),
            'more' => $more,
            'cap'  => $cap,
        ));
    }

    /**
     * Search rows as pins: only rows with a search point, and only fields any visitor sees
     * on the listing page.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $covers listing id => thumbnail URL
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pins(array $rows, array $covers = array()): array
    {
        $pins = array();
        foreach ($rows as $row) {
            $lat = $row['d_geo_lat'] ?? null;
            $lng = $row['d_geo_long'] ?? null;
            $id  = (int) ($row['pk_i_id'] ?? 0);
            if ($id <= 0 || !is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }
            $pins[] = array(
                'id'     => $id,
                'lat'    => round((float) $lat, 5),
                'lng'    => round((float) $lng, 5),
                'title'  => trim(strip_tags((string) ($row['s_title'] ?? ''))),
                'price'  => self::price($row),
                'url'    => (string) osc_item_url_from_item($row),
                'thumb'  => $covers[$id] ?? null,
                'approx' => ($row['e_geo_precision'] ?? 'exact') !== 'exact',
            );
        }

        return $pins;
    }

    /**
     * Whether any live listing has a search point, through core's search with a box over the
     * whole world. Call it only while distance search is on: off, the box is not applied.
     */
    public static function any(): bool
    {
        $found = false;
        $held  = osc_cache_get('map_search_any', $found);
        if ($found) {
            return (bool) $held;
        }
        $any = self::search(self::params(array(), array(-180.0, -90.0, 180.0, 90.0), 1), 1) !== array();
        osc_cache_set('map_search_any', $any ? 1 : 0, self::ANY_TTL);

        return $any;
    }

    /** The route hook: rate limit, answer, JSON. */
    public static function respond(): void
    {
        $ip = class_exists(Utils::class) ? Utils::getClientIp() : '';
        if (!self::allowed($ip)) {
            if (!headers_sent()) {
                header('Retry-After: ' . self::RATE_WINDOW);
            }
            self::json(array('error' => 'rate_limited'), 429, 'no-store');

            return;
        }
        $answer = self::answer(\Params::getParamsAsArray(), (bool) osc_geo_search_enabled(), Config::maxPins());
        self::json($answer['body'], $answer['status'], $answer['status'] === 200 ? self::CACHE_OK : 'public, max-age=60');
    }

    public static function allowed(string $key): bool
    {
        if (self::$limiter !== null) {
            return (bool) (self::$limiter)($key);
        }

        return $key === '' || RateLimit::hit(self::RATE_CONTEXT, $key, self::RATE_MAX, self::RATE_WINDOW);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     */
    private static function search(array $params, int $pageSize): array
    {
        if (self::$search !== null) {
            return array_values((array) (self::$search)($params, $pageSize));
        }
        $criteria = SearchCriteria::fromRequest($params, array('pageSize' => $pageSize, 'maxPageSize' => $pageSize));

        return array_values(SearchRunner::run($criteria, new \Search(), 'request', null, false, $params)->items());
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,string>
     */
    private static function covers(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) ($row['pk_i_id'] ?? 0), $rows);

        return $ids === array() ? array() : osc_item_cover_urls($ids, 'thumbnail');
    }

    /** The price as the listing page shows it, or null when the listing shows none. */
    private static function price(array $row): ?string
    {
        if (!is_numeric($row['i_price'] ?? null) || !osc_price_enabled_at_items() || !self::categoryShowsPrice((int) ($row['fk_i_category_id'] ?? 0))) {
            return null;
        }
        $code     = (string) ($row['fk_c_currency_code'] ?? '');
        $currency = $code === '' ? false : \Currency::getInstance()->findByPrimaryKey($code);
        $symbol   = is_array($currency) && ($currency['s_description'] ?? '') !== '' ? (string) $currency['s_description'] : $code;

        return trim(strip_tags((string) osc_format_price((float) $row['i_price'], $symbol)));
    }

    private static function categoryShowsPrice(int $categoryId): bool
    {
        static $seen = array();
        if ($categoryId <= 0) {
            return true;
        }
        if (!isset($seen[$categoryId])) {
            $category          = \Category::getInstance()->findByPrimaryKey($categoryId);
            $seen[$categoryId] = !is_array($category) || (int) ($category['b_price_enabled'] ?? 1) === 1;
        }

        return $seen[$categoryId];
    }

    /** @param array<string,mixed> $data */
    private static function json(array $data, int $status, string $cache): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: ' . $cache);
            header('X-Robots-Tag: noindex');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
