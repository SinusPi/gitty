<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

if (PHP_SAPI === 'cli') {
    runCliMode($argv);
    exit(0);
}

$browser = new RepoBrowser(getConfiguredRepoRoots());
$browser->display();
