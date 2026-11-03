<?php

namespace App\Http\Controllers;

use App\Services\LiveBackupRestore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecoveryStatusController extends Controller
{
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
