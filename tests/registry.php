<?php
/*
 * This file is part of the Map Search plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The settings page goes through core's real SettingsPageRegistry, so a field option core
 * refuses fails here instead of taking the site down.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\mapsearch\Plugin;
use mindstellar\settings\SettingsPageRegistry;

$classes = $core . '/oc-includes/osclass/classes/';
spl_autoload_register(static function (string $class) use ($classes): void {
    if (strncmp($class, 'mindstellar\\', 12) === 0) {
        $file = $classes . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

harness_section("core's registry takes the settings page");
$registry = SettingsPageRegistry::getInstance();
$error    = '';
try {
    $registry->register(Plugin::PAGE, require __DIR__ . '/../settings.php');
} catch (Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}
pin('no error', '', $error);
check('page is registered', $registry->get(Plugin::PAGE) !== null);
check('every field is kept', isset($registry->fields(Plugin::PAGE)['style_url'], $registry->fields(Plugin::PAGE)['max_pins']));

exit(harness_result());
