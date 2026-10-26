<?php
/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The pins route: what it hands core's search, the cap, and that a pin carries only public fields.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\mapsearch\Pins;

$asked = array();
/** A search that records what it got and returns $rows. */
function searching(array $rows): void
{
    Pins::$search = static function (array $params, int $size) use ($rows): array {
        $GLOBALS['asked'][] = array($params, $size);

        return array_slice($rows, 0, $size);
    };
    $GLOBALS['asked'] = array();
}

harness_section('distance search off');
searching(array(row(1)));
$a = Pins::answer(array('bbox' => '72.8,18.9,73.1,19.3'), false, 200);
pin('404', 404, $a['status']);
pin('says why', array('error' => 'off'), $a['body']);
pin('core search not called', array(), $GLOBALS['asked']);

harness_section('a bad box');
foreach (array('missing' => null, 'three numbers' => '1,2,3', 'south above north' => '0,10,1,5', 'longitude past 180' => '0,0,181,1', 'a list' => array('1', '2', '3', '4')) as $label => $bbox) {
    $a = Pins::answer($bbox === null ? array() : array('bbox' => $bbox), true, 200);
    pin("422 for $label", 422, $a['status']);
}
pin('core search not called for a bad box', array(), $GLOBALS['asked']);

harness_section('the box and filters reach core search');
searching(array(row(1)));
Pins::answer(array(
    'bbox'      => ' 72.81234567, 18.9 ,73.1,19.3',
    'sCategory' => array('3', '4'),
    'sPattern'  => 'red bike',
    'meta'      => array('5' => 'Blue', 'x' => 'dropped'),
    'sPriceMax' => '500',
    'iRadius'   => '25',
    'sLat'      => '19.07',
    'sLng'      => '72.87',
    'page'      => 'custom',
    'route'     => 'map-search-pins',
    'sEmail'    => 'a@b.c',
    'iPagesize' => '100000',
    'sOrder'    => 'i_price',
    'hl'        => 'en_US',
), true, 200);
[$params, $size] = $GLOBALS['asked'][0];
pin('bbox in core\'s short form', '72.8123,18.9,73.1,19.3', $params['bbox']);
pin('one page of cap + 1', array('1', '201', 201), array($params['iPage'], $params['iPagesize'], $size));
pin('filters kept', array('3', '4'), $params['sCategory']);
pin('pattern kept', 'red bike', $params['sPattern']);
pin('custom field kept by id only', array('5' => 'Blue'), $params['meta']);
pin('radius and point kept', array('25', '19.07', '72.87'), array($params['iRadius'], $params['sLat'], $params['sLng']));
$keys = array_keys($params);
sort($keys);
pin('nothing else reaches the search', array('bbox', 'iPage', 'iPagesize', 'iRadius', 'meta', 'sCategory', 'sLat', 'sLng', 'sPattern', 'sPriceMax'), $keys);
searching(array());
Pins::answer(array('bbox' => '170,-10,-170,10'), true, 50);
pin('a box across the date line passes as sent', '170,-10,-170,10', $GLOBALS['asked'][0][0]['bbox']);

harness_section('the cap');
$rows = array();
for ($i = 1; $i <= 260; $i++) {
    $rows[] = row($i);
}
searching($rows);
$a = Pins::answer(array('bbox' => '-180,-90,180,90'), true, 200);
pin('200', 200, $a['status']);
pin('at most cap pins', 200, count($a['body']['pins']));
pin('says there are more', true, $a['body']['more']);
pin('says the cap', 200, $a['body']['cap']);
searching(array_slice($rows, 0, 120));
$a = Pins::answer(array('bbox' => '-180,-90,180,90'), true, 200);
pin('under the cap: all, no more', array(120, false), array(count($a['body']['pins']), $a['body']['more']));

harness_section('only public fields');
searching(array(row(2), row(3, null, null), row(4, 51.5, -0.12, array('e_geo_precision' => 'city', 'fk_i_category_id' => 9)), row(5, 1.0, 2.0, array('i_price' => null))));
$pins = Pins::answer(array('bbox' => '-180,-90,180,90'), true, 200)['body']['pins'];
pin('a row with no point is left out', array(2, 4, 5), array_column($pins, 'id'));
$keys = array_keys($pins[0]);
sort($keys);
pin('the pin fields', array('approx', 'id', 'lat', 'lng', 'price', 'thumb', 'title', 'url'), $keys);
$json = json_encode($pins);
foreach (array('seller@example.com', '555 0100', 'abc123', '203.0.113.9', 'Main Street') as $private) {
    check("no $private in the answer", !str_contains($json, $private));
}
pin('title without tags', 'Listing 2', $pins[0]['title']);
pin('price formatted with the currency', '€ 12.50', $pins[0]['price']);
pin('photo thumb', 'https://shop.test/uploads/2_thumbnail.jpg', $pins[0]['thumb']);
pin('no photo is null', null, $pins[2]['thumb']);
pin('listing url', 'https://shop.test/item/2', $pins[0]['url']);
pin('exact point', array(19.07, 72.87, false), array($pins[0]['lat'], $pins[0]['lng'], $pins[0]['approx']));
pin('a city centre is approximate', true, $pins[1]['approx']);
pin('a category without prices shows none', null, $pins[1]['price']);
pin('no price shows none', null, $pins[2]['price']);
$GLOBALS['prices'] = false;
pin('prices off site-wide show none', null, Pins::answer(array('bbox' => '-180,-90,180,90'), true, 200)['body']['pins'][0]['price']);
$GLOBALS['prices'] = true;

harness_section('the route');
searching(array(row(2)));
Params::$values = array('bbox' => '72.8,18.9,73.1,19.3');
settings(array('max_pins' => '75'));
Pins::$limiter = static fn (string $key): bool => true;
ob_start();
Pins::respond();
$out = json_decode((string) ob_get_clean(), true);
pin('answers JSON pins', array(2), array_column($out['pins'] ?? array(), 'id'));
pin('uses the saved cap', array(75, '76'), array($out['cap'], $GLOBALS['asked'][0][0]['iPagesize']));
Pins::$limiter = static fn (string $key): bool => false;
$GLOBALS['asked'] = array();
ob_start();
Pins::respond();
pin('over the rate limit', array('error' => 'rate_limited'), json_decode((string) ob_get_clean(), true));
pin('no search when limited', array(), $GLOBALS['asked']);
$GLOBALS['geo'] = false;
Pins::$limiter = static fn (string $key): bool => true;
ob_start();
Pins::respond();
pin('the route says off when distance search is off', array('error' => 'off'), json_decode((string) ob_get_clean(), true));
$GLOBALS['geo'] = true;
check('ok answers may be cached for a minute', Pins::CACHE_OK === 'public, max-age=60, s-maxage=60');

harness_section('any listing on the map');
searching(array());
pin('none', false, Pins::any());
pin('asks core search for one listing in the whole world', array('-180,-90,180,90', '1'), array($GLOBALS['asked'][0][0]['bbox'], $GLOBALS['asked'][0][0]['iPagesize']));
searching(array(row(1)));
pin('kept in the cache', false, Pins::any());
pin('no second search', array(), $GLOBALS['asked']);
$GLOBALS['cache'] = array();
pin('one found', true, Pins::any());

exit(harness_result());
