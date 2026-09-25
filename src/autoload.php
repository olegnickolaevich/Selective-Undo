<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * PSR-4 autoloader for the SelectiveUndo namespace.
 *
 * The plugin has no runtime Composer dependencies, so it ships its own loader
 * and never collides with vendor copies bundled by other plugins.
 */
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'SelectiveUndo\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
);
