<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Support\PlatformHost;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        /** @var Store|null $store */
        $store = $request->attributes->get('store');
        if (! $store) {
            return Inertia::render('Platform/Home', [
                'domain' => app(PlatformHost::class)->domain(),
                'previewEnabled' => app()->environment('local') && config('platform.preview_enabled'),
            ]);
        }
        abort_unless($store->supportsWeb(), 404);

        return Inertia::render('Storefront/Home', [
            'store' => $store->only(['id', 'name', 'slug', 'template', 'branding', 'status']),
            'available' => $store->acceptsOrders(), 'products' => [], 'preview' => false,
        ]);
    }
}
