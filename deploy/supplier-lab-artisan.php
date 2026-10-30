<?php

if (PHP_SAPI !== 'cli' || getenv('SUPPLIER_SIMULATOR_ENABLED') !== 'true') {
    exit(1);
}

$web = dirname(__DIR__).'/apps/web';
require $web.'/vendor/autoload.php';
$app = require $web.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.supplier-lab');
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
