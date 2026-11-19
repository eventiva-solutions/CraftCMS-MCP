<?php

/**
 * Loads the autoloader of a Craft project (or this plugin's own vendor directory).
 *
 * Set CRAFT_VENDOR_PATH to the vendor directory of a Craft 5 project that has this
 * plugin installed, e.g. CRAFT_VENDOR_PATH=/app/vendor
 */

$candidates = array_filter([
    getenv('CRAFT_VENDOR_PATH') ? rtrim(getenv('CRAFT_VENDOR_PATH'), '/\\') . '/autoload.php' : null,
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',          // plugin installed in vendor/eventiva/craft-mcp
    __DIR__ . '/../../../../vendor/autoload.php', // plugin in <project>/plugins/mcp
]);

foreach ($candidates as $file) {
    if (is_file($file)) {
        require $file;
        return;
    }
}

fwrite(STDERR, "No autoloader found. Set CRAFT_VENDOR_PATH to a Craft project's vendor directory.\n");
exit(1);
