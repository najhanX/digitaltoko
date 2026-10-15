<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Store;
use Illuminate\Http\Request;

class CustomerAccess
{
    public function store(Request $request): Store
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);

        return $store;
    }

    public function customer(Request $request, Store $store, bool $required = false): ?Customer
    {
        $customer = $request->user('customer');
        $customer = $customer && $customer->store_id === $store->id && $customer->phone_verified_at ? $customer : null;
        if ($required) {
            abort_unless($customer, 401);
        }

        return $customer;
    }

    public function order(Request $request, CustomerOrder $order): void
    {
        $store = $this->store($request);
        abort_unless($order->store_id === $store->id, 404);
        $customer = $this->customer($request, $store);
        $token = $request->query('access');
        abort_unless(($customer && $order->customer_id === $customer->id) || (is_string($token) && hash_equals(app(CustomerCommerce::class)->token($order), $token)), 404);
    }

    public function worker(Request $request): Store
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $store = Store::where('worker_token_hash', hash('sha256', $token))->first();
        abort_unless($store, 401);

        return $store;
    }
}
