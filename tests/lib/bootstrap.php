<?php
/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes and core's BoxFilter (from SHOPCLASS_CORE, or ../osclass), with no
 * database and no network. The core helpers the plugin calls are stood in for here and record
 * what they were asked.
 */

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

define('ABS_PATH', '/x/');

$core = rtrim((string) (getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 2) . '/../osclass'), '/');
$box  = $core . '/oc-includes/osclass/classes/search/query/BoxFilter.php';
if (!is_file($box)) {
    fwrite(STDERR, "Core's BoxFilter is not at $box. Set SHOPCLASS_CORE to a Shopclass 7.1 checkout.\n");
    exit(1);
}
require_once $box;

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\mapsearch\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require_once __DIR__ . '/../../src/functions.php';

function __($text, $domain = '')
{
    return $text;
}

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$GLOBALS['scripts'] = array();
function osc_register_script($id, $url, $deps = null)
{
    $GLOBALS['scripts']['registered'][$id] = array($url, $deps);
}
function osc_enqueue_script($id)
{
    $GLOBALS['scripts']['enqueued'][] = $id;
}
function osc_asset_url_versioned($url)
{
    return $url . '?v=1';
}
function osc_plugin_url($file)
{
    return 'https://shop.test/oc-content/plugins/map-search/';
}
function osc_route_url($id, $args = array())
{
    return 'https://shop.test/map-search/pins';
}
function osc_search_url($params = null)
{
    $params = is_array($params) ? $params : array();

    return 'https://shop.test/search' . ($params === array() ? '' : '?' . str_replace('%5F', '_', http_build_query($params)));
}
$GLOBALS['view'] = array('bbox' => null, 'near' => null, 'search_page' => true);
function osc_search_bbox()
{
    return $GLOBALS['view']['bbox'];
}
function osc_search_near()
{
    return $GLOBALS['view']['near'];
}
function osc_is_search_page()
{
    return $GLOBALS['view']['search_page'];
}
function osc_current_user_locale()
{
    return 'en_US';
}

$GLOBALS['filters'] = array();
function osc_apply_filter($name, $value)
{
    return isset($GLOBALS['filters'][$name]) ? ($GLOBALS['filters'][$name])($value) : $value;
}

$GLOBALS['geo'] = true;
function osc_geo_search_enabled()
{
    return $GLOBALS['geo'];
}

$GLOBALS['cache'] = array();
function osc_cache_get($key, &$found)
{
    $found = array_key_exists($key, $GLOBALS['cache']);

    return $GLOBALS['cache'][$key] ?? false;
}
function osc_cache_set($key, $data, $expire = 0)
{
    $GLOBALS['cache'][$key] = $data;

    return true;
}

$GLOBALS['deleted'] = array();
function osc_delete_preference($name, $section)
{
    $GLOBALS['deleted'][] = $section . '.' . $name;
}

function osc_item_url_from_item($item)
{
    return 'https://shop.test/item/' . (int) $item['pk_i_id'];
}
function osc_item_cover_urls(array $ids, string $variant = 'thumbnail'): array
{
    $out = array();
    foreach ($ids as $id) {
        if ($id % 2 === 0) {
            $out[$id] = 'https://shop.test/uploads/' . $id . '_' . $variant . '.jpg';
        }
    }

    return $out;
}
$GLOBALS['prices'] = true;
function osc_price_enabled_at_items()
{
    return $GLOBALS['prices'];
}
function osc_format_price($price, $symbol = null)
{
    return $symbol . ' ' . number_format($price / 1000000, 2);
}

final class Currency
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function findByPrimaryKey($code)
    {
        return $code === 'EUR' ? array('pk_c_code' => 'EUR', 's_description' => '€') : false;
    }
}

final class Category
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function findByPrimaryKey($id)
    {
        return array('pk_i_id' => $id, 'b_price_enabled' => $id === 9 ? 0 : 1);
    }
}

final class Params
{
    /** @var array<string,mixed> */
    public static array $values = array();

    public static function getParamsAsArray(): array
    {
        return self::$values;
    }
}

/** Settings over an array. */
function settings(array $values): void
{
    \mindstellar\mapsearch\Config::$reader = static fn (string $name) => $values[$name] ?? null;
}

/** A search result row as core's search gives it, with fields a pin must never carry. */
function row(int $id, ?float $lat = 19.07, ?float $lng = 72.87, array $extra = array()): array
{
    return $extra + array(
        'pk_i_id'            => $id,
        's_title'            => 'Listing <b>' . $id . '</b>',
        'i_price'            => 12500000,
        'fk_c_currency_code' => 'EUR',
        'fk_i_category_id'   => 3,
        'd_geo_lat'          => $lat,
        'd_geo_long'         => $lng,
        'e_geo_precision'    => 'exact',
        'd_coord_lat'        => $lat,
        's_contact_email'    => 'seller@example.com',
        's_contact_phone'    => '+1 555 0100',
        's_secret'           => 'abc123',
        's_ip'               => '203.0.113.9',
        'fk_i_user_id'       => 7,
        's_address'          => '1 Main Street',
    );
}
