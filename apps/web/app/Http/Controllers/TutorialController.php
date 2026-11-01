<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TutorialController extends Controller
{
    public function index(?string $slug = null): Response
    {
        $guides = collect(json_decode(file_get_contents(resource_path('js/tutorialCatalog.json')), true, 512, JSON_THROW_ON_ERROR));
        $guide = $slug ? $guides->firstWhere('slug', $slug) : null;
        abort_if($slug && ! $guide, 404);
        if (in_array($slug, ['pembayaran-custom', 'aktifkan-listener'], true)) {
            $guide['download'] = is_file(config('services.forwarder.bundle_path'))
                ? ['label' => 'Download paket HansDev Forwarder (ZIP)', 'url' => route('forwarder.download')]
                : null;
        }

        return Inertia::render('Platform/Tutorials', [
            'guides' => $guides->map(fn ($item) => array_intersect_key($item, array_flip(['slug', 'title', 'category', 'summary', 'keywords'])))->values(),
            'guide' => $guide,
        ]);
    }
}
