<?php

/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's capability,
 * and stores each field under the "map-search" section.
 */

use mindstellar\mapsearch\Config;
use mindstellar\mapsearch\Plugin;

return array(
    'title'  => __('Map Search', 'map-search'),
    'menu'   => 'settings',
    'group'  => 'general',
    'intro'  => __('A map of listings on the search page. Visitors move the map and press "Search this area" to see the listings in it.', 'map-search'),
    'groups' => array(
        array(
            'title'  => __('Status', 'map-search'),
            'fields' => array(
                array(
                    'type'    => 'custom',
                    'name'    => 'status',
                    'label'   => __('Map', 'map-search'),
                    'render'  => array(Plugin::class, 'drawStatus'),
                ),
            ),
        ),
        array(
            'title'  => __('Map tiles', 'map-search'),
            'fields' => array(
                array(
                    'type'      => 'url',
                    'name'      => 'style_url',
                    'label'     => __('Map style address', 'map-search'),
                    'help'      => __('A MapLibre style JSON over https. The default is OpenFreeMap, which needs no key. For a provider with a key, put {key} where the key goes.', 'map-search'),
                    'maxlength' => Config::URL_MAX,
                    'default'   => Config::STYLE_DEFAULT,
                    'required'  => true,
                    'purify'    => false,
                    'validate'  => array(Plugin::class, 'validateStyle'),
                ),
                array(
                    'type'      => 'url',
                    'name'      => 'style_url_dark',
                    'label'     => __('Dark map style address', 'map-search'),
                    'help'      => __('Used when the page is in dark mode. Leave empty to use the style above.', 'map-search'),
                    'maxlength' => Config::URL_MAX,
                    'default'   => Config::DARK_STYLE_DEFAULT,
                    'purify'    => false,
                    'validate'  => array(Plugin::class, 'validateDarkStyle'),
                ),
                array(
                    'type'       => 'secret',
                    'name'       => 'api_key',
                    'label'      => __('Provider key', 'map-search'),
                    'help'       => __('Only for a provider that needs one. It goes in place of {key} and is sent to every visitor\'s browser, so use a key limited to your site\'s address. Leave the box blank to keep the saved key.', 'map-search'),
                    'write_only' => false,
                    'masked'     => true,
                    'attrs'      => array('autocomplete' => 'off', 'spellcheck' => 'false'),
                    'persist'    => static fn ($value) => trim((string) $value) === '' ? null : trim((string) $value),
                    'validate'   => array(Plugin::class, 'validateKey'),
                ),
            ),
        ),
        array(
            'title'  => __('Map view', 'map-search'),
            'fields' => array(
                array(
                    'type'     => 'number',
                    'name'     => 'center_lat',
                    'label'    => __('Default centre latitude', 'map-search'),
                    'help'     => __('Where the map opens when the search has no place or area. -90 to 90.', 'map-search'),
                    'default'  => (string) Config::LAT_DEFAULT,
                    'attrs'    => array('min' => '-90', 'max' => '90', 'step' => 'any'),
                    'validate' => static fn ($value) => Plugin::validateRange($value, -90, 90),
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'center_lng',
                    'label'    => __('Default centre longitude', 'map-search'),
                    'help'     => __('-180 to 180.', 'map-search'),
                    'default'  => (string) Config::LNG_DEFAULT,
                    'attrs'    => array('min' => '-180', 'max' => '180', 'step' => 'any'),
                    'validate' => static fn ($value) => Plugin::validateRange($value, -180, 180),
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'zoom',
                    'label'    => __('Default zoom', 'map-search'),
                    'help'     => __('0 shows the world, 18 a street.', 'map-search'),
                    'default'  => (string) Config::ZOOM_DEFAULT,
                    'attrs'    => array('min' => (string) Config::ZOOM_MIN, 'max' => (string) Config::ZOOM_MAX, 'step' => '1'),
                    'validate' => static fn ($value) => Plugin::validateRange($value, Config::ZOOM_MIN, Config::ZOOM_MAX),
                ),
                array(
                    'type'     => 'number',
                    'name'     => 'max_pins',
                    'label'    => __('Pins per map view', 'map-search'),
                    'help'     => __('The most listings the map loads at once, 50 to 500. Fewer is faster.', 'map-search'),
                    'default'  => (string) Config::PINS_DEFAULT,
                    'attrs'    => array('min' => (string) Config::PINS_MIN, 'max' => (string) Config::PINS_MAX, 'step' => '1'),
                    'validate' => static fn ($value) => Plugin::validateRange($value, Config::PINS_MIN, Config::PINS_MAX, true),
                ),
            ),
        ),
        array(
            'title'  => __('Where it shows', 'map-search'),
            'fields' => array(
                array(
                    'type'    => 'checkbox',
                    'name'    => 'on_search',
                    'label'   => __('Show the map above the search results', 'map-search'),
                    'help'    => __('Works with themes that have a place for it there. Other themes show the map where they call map_search_render().', 'map-search'),
                    'default' => true,
                ),
            ),
        ),
    ),
);
