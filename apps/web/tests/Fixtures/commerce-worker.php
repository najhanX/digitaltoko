<?php

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! preg_match('/^dt_commerce_test_[a-f0-9]{32}$/', $input['database'])) {
    throw new RuntimeException('Isolated database required');
}
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['config'], 'cache.default' => 'array', 'session.driver' => 'array', 'inertia.ssr.enabled' => false]);
DB::purge('mysql');
Http::preventStrayRequests();
if ($input['mode'] === 'paid') {
    $order = CustomerOrder::findOrFail($input['order']);
    app(CustomerCommerce::class)->paid($order, 'test-receipt:'.$order->id, $order->total, now());
    echo $order->fresh()->status;
} elseif ($input['mode'] === 'fulfill') {
    $order = CustomerOrder::findOrFail($input['order']);
    app(CustomerFulfillment::class)->run($order);
    app(CustomerFulfillment::class)->run($order->fresh());
    echo $order->fresh()->status;
} else {
    $store = Store::findOrFail($input['store']);
    $customer = Customer::findOrFail($input['customer']);
    $order = app(CustomerCommerce::class)->begin($store, $input['input'], $customer);
    echo $order->id;
}
