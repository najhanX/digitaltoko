<?php

namespace App\Http\Controllers;

use App\Services\LiveBackupRestore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecoveryStatusController extends Controller
{
    public function review(): Response
    {
        $state = config('backup.preview') ? 'preview' : (is_file(config('backup.restore_hold')) ? 'review' : 'ready');
        if ($state === 'ready' && config('backup.live_enabled') && is_file(config('backup.restore_lock'))) {
            $lease = fopen(config('backup.restore_lock'), 'r');
            if ($lease !== false) {
                if (! flock($lease, LOCK_SH | LOCK_NB)) {
                    $state = 'processing';
                } else {
                    flock($lease, LOCK_UN);
                }
                fclose($lease);
            }
        }

        return response()->view('recovery-review', ['state' => $state, 'platformUrl' => rtrim(config('app.url'), '/')])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function show(Request $request, string $id, LiveBackupRestore $restore): Response
    {
        try {
            $journal = $restore->read($id);
        } catch (\RuntimeException) {
            abort(404);
        }
        $data = ['state' => $journal['state'], 'phase' => $journal['phase'], 'id' => $id];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'no-store') : response()->view('recovery-status', $data)->header('Cache-Control', 'no-store');
    }
}
