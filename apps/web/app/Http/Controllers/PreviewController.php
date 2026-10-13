<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class PreviewController extends Controller
{
    public function __invoke(Request $request, string $surface)
    {
        abort_unless(app()->environment('local') && config('platform.preview_enabled'), 404);
        abort_unless(in_array($surface, ['admin', 'owner', 'staff', 'storefront'], true), 404);
        $store = ['id' => 'preview', 'name' => 'Studio Digital', 'slug' => 'studio', 'category' => 'full',
            'plan' => 'galaxy', 'status' => 'draft', 'template' => $request->query('template', 'aurora'),
            'expires_at' => null, 'url' => '/preview/storefront', 'branding' => null];
        if ($surface === 'storefront') {
            $page = $request->query('page', 'home');

            return Inertia::render('Storefront/Home', ['store' => $store, 'available' => false, 'products' => [], 'preview' => true,
                'page' => in_array($page, ['home', 'catalog', 'order', 'profile'], true) ? $page : 'home']);
        }

        return Inertia::render('Panel/Dashboard', ['role' => $surface, 'preview' => true,
            'viewer' => ['name' => 'Pratinjau '.$surface], 'counts' => [], 'permissions' => ['dashboard.view'], 'stores' => [$store]]);
    }
}
