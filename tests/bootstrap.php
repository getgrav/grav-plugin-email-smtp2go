<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds nothing
 * but Composer's autoloader - Symfony Mailer comes from Grav at runtime, which
 * is why the plugin's composer.json replaces it rather than requiring it.
 *
 * Installing PHPUnit and a real Symfony Mailer into that same directory would
 * put development packages into the released plugin, so the suite keeps its own
 * composer.json and its own vendor directory here under tests/ instead. Run
 * `composer install` in this directory once, then `phpunit` from the repository
 * root.
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;
