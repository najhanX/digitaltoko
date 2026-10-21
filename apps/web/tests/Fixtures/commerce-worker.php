<?php

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\ModulePurchase;
use App\Models\Store;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\Vouchers;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! preg_match('/^dt_commerce_test_[a-f0-9]{32}$/', $input['database'])) {
    throw new RuntimeException('Isolated database required');
}
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['config'], 'cache.default' => 'array', 'queue.default' => 'database', 'session.driver' => 'array', 'inertia.ssr.enabled' => false]);
DB::purge('mysql');
Http::preventStrayRequests();
if ($input['mode'] === 'platform_voucher') {
    try {
        echo DB::transaction(function () use ($input) {
            Store::whereKey($input['store'])->lockForUpdate()->firstOrFail();
            ModulePurchase::where('store_id', $input['store'])->exists();
            $quote = app(Vouchers::class)->quote(null, $input['code'], 20000, module: true, lock: true);
            $invoice = ModulePurchase::create(['store_id' => $input['store'], 'status' => 'pending', 'amount' => $quote['total']]);
            app(Vouchers::class)->redeem($quote, 'module', $invoice->id);

            return $invoice->id;
        }, 3);
    } catch (ValidationException $e) {
        echo 'QUOTA_REJECTED';
    }
} elseif ($input['mode'] === 'voucher') {
    try {
        $order = app(CustomerCommerce::class)->begin(Store::findOrFail($input['store']), $input['input'], Customer::findOrFail($input['customer']));
        echo $order->id;
    } catch (ValidationException $e) {
        echo array_key_exists('voucher_code', $e->errors()) ? 'QUOTA_REJECTED' : 'OTHER_REJECTION';
    }
} elseif ($input['mode'] === 'paid') {
    $order = CustomerOrder::findOrFail($input['order']);
    app(CustomerCommerce::class)->paid($order, 'test-receipt:'.$order->id, $order->total, now());
    echo $order->fresh()->status;
} elseif ($input['mode'] === 'callback') {
    $order = CustomerOrder::findOrFail($input['order']);
    $attempt = $order->attempts()->where('source', 'digiflazz')->firstOrFail();
    app(CustomerFulfillment::class)->digiflazzCallback($order, $attempt, $input['callback']);
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
