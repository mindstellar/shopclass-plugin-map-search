<?php
/*
Plugin Name: Map Search
Plugin URI: https://github.com/mindstellar/shopclass-plugin-map-search
Description: A map of listings on the search page, with pin clusters and a "Search this area" button. Uses free OpenFreeMap tiles by default, with no key.
Version: 0.1.0
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: map-search
Requires Shopclass: 7.1.0
Tested up to: 7.1
Requires PHP: 8.2
Support URI: https://github.com/mindstellar/shopclass-plugin-map-search/issues
Modes: classifieds, shop
*/

/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\mapsearch\Pins;
use mindstellar\mapsearch\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\mapsearch\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// Needs the map area search of Shopclass 7.1. On an older core it loads and does nothing but say so.
if (!class_exists('mindstellar\\search\\query\\BoxFilter') || !function_exists('osc_search_bbox')) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Map Search needs Shopclass 7.1 or later and is switched off. Update Shopclass to use it.', 'map-search'))
            . '</div>';
    }, 10);

    return;
}

Plugin::$file = __FILE__;
require_once __DIR__ . '/src/functions.php';

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

osc_add_route_hook(Plugin::ROUTE_PINS, 'map-search/pins(?:\?.*)?$', 'map-search/pins');
osc_add_hook(Plugin::ROUTE_PINS, array(Pins::class, 'respond'));

osc_add_hook('search_ads_listing_top', array(Plugin::class, 'autoPlace'));
