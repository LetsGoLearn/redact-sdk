<?php

declare(strict_types=1);

// Prefer Composer's autoloader when present; otherwise register a minimal PSR-4
// autoloader so the suite runs without `composer install`.
$vendor = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'RedactSdk\\Tests\\' => __DIR__ . '/',
        'RedactSdk\\' => __DIR__ . '/../src/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            $file = $base . $relative;
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
