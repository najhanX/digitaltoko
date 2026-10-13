<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'brand' => ['name' => 'DigitalToko'],
            'flash' => ['error' => fn () => $request->session()->get('error'), 'success' => fn () => $request->session()->get('success')]];
    }
}
