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

use mindstellar\mapsearch\Widget;

if (!function_exists('map_search_render')) {
    /**
     * Print the listings map here. Prints nothing while distance search is off, while no
     * listing has a map position, or when the map is already on the page.
     *
     * @param array{class?:string,center?:array{0:float,1:float},zoom?:float|int,search_button?:bool} $opts
     */
    function map_search_render(array $opts = array()): void
    {
        echo Widget::render($opts);
    }
}
