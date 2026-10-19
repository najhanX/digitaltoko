<?php

namespace App\Http\Controllers;

use App\Services\QuickOrderCatalog;
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
            if ($page === 'leaderboard') {
                $period = in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'month';

                return Inertia::render('Storefront/Leaderboard', ['store' => [...$store, 'preview' => true], 'preview' => true,
                    'period' => $period, 'ranking' => [['name' => 'Gamer 7C00••', 'amount' => 2450000, 'purchases' => 28]],
                    'filters' => ['q' => '', 'per_page' => 10],
                    'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 1, 'from' => 1, 'to' => 1]]);
            }
            if (in_array($page, ['calculator', 'calculator/winrate', 'calculator/magic-wheel', 'calculator/zodiac'], true)) {
                return Inertia::render('Storefront/Calculator', ['store' => [...$store, 'preview' => true], 'preview' => true,
                    'calculator' => str_contains($page, '/') ? substr($page, strlen('calculator/')) : null]);
            }

            return Inertia::render('Storefront/Home', ['store' => $store, 'available' => false, 'products' => [], 'preview' => true, 'renderedAt' => (int) now()->format('Uv'), 'quickOrderProviders' => app(QuickOrderCatalog::class)->providers(),
                'filters' => ['q' => is_string($request->query('q')) ? mb_substr($request->query('q'), 0, 120) : '', 'per_page' => 10],
                'page' => in_array($page, ['home', 'catalog', 'order', 'profile', 'lookup'], true) ? $page : 'home']);
        }

        return Inertia::render('Panel/Dashboard', ['role' => $surface, 'preview' => true,
            'viewer' => ['name' => 'Pratinjau '.$surface], 'counts' => [], 'permissions' => ['dashboard.view'], 'stores' => [$store]]);
    }
}
