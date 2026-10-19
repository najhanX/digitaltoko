<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuestCheckout
{
    public function key(Request $request): string
    {
        $key = $request->session()->get('guest_checkout_key');
        if (! is_string($key)) {
            $key = Str::random(64);
            $request->session()->put('guest_checkout_key', $key);
        }

        return hash('sha256', $key);
    }

    public function pending(Store $store, string $key): ?CustomerOrder
    {
        return CustomerOrder::where('store_id', $store->id)->where('guest_checkout_hash', $key)->whereNull('customer_id')
            ->where('status', 'awaiting_payment')->where('payment_status', 'unpaid')->where('expires_at', '>', now())->first();
    }

    public function summary(Request $request, Store $store): ?array
    {
        if (app(CustomerAccess::class)->customer($request, $store)) {
            return null;
        }
        $order = $this->pending($store, $this->key($request));

        return $order ? ['number' => $order->number, 'url' => '/orders/'.$order->id.'?access='.app(CustomerCommerce::class)->token($order)] : null;
    }
}
