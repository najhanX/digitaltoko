<?php

if (PHP_SAPI !== 'cli-server' || getenv('SUPPLIER_SIMULATOR_ENABLED') !== 'true') {
    http_response_code(404);
    exit;
}

$web = dirname(__DIR__).'/apps/web';
if (! is_file($web.'/.env.supplier-lab')) {
    http_response_code(503);
    exit('Jalankan npm run lab:setup sebelum membuka Supplier Lab.');
}
$public = realpath($web.'/public');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($public.$path);
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $web.'/vendor/autoload.php';
$app = require $web.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.supplier-lab');
$app->handleRequest(Illuminate\Http\Request::capture());
