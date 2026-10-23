<?php

namespace App\Http\Controllers;

use App\Models\PlanOption;
use App\Support\PackageCatalog;
use App\Support\PlatformHost;
use App\Support\StoreSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PublicPricingController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Platform/Pricing', [
            'domain' => app(PlatformHost::class)->domain(),
            'offers' => PlanOption::where('active', true)->where('plan', 'galaxy')
                ->whereIn('category', PackageCatalog::keys())->orderBy('duration_days')->orderBy('price')->orderBy('id')
                ->get(['id', 'name', 'category', 'price', 'duration_days']),
        ])->toResponse($request)->header('Cache-Control', 'no-store');
    }

    public function availability(Request $request): JsonResponse
    {
        $request->merge(['slug' => StoreSubdomain::normalize($request->query('slug'))]);
        $data = $request->validate(['slug' => StoreSubdomain::rules()], StoreSubdomain::messages());

        return response()->json(['slug' => $data['slug'], 'available' => true])->header('Cache-Control', 'no-store');
    }
}
