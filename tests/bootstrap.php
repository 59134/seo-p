<?php

require __DIR__ . '/vendor/autoload.php';

$cmsRoot = getenv('PSEO_CMS_DIR') ?: dirname(__DIR__, 4) . '/cms';
spl_autoload_register(static function (string $class) use ($cmsRoot): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4)) . '.php';
    foreach ([dirname(__DIR__) . '/files/src', $cmsRoot . '/src'] as $root) {
        if (is_file($root . '/' . $relative)) {
            require $root . '/' . $relative;
            return;
        }
    }
});
