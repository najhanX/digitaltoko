<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuardRecoveryRuntime
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('recovery-status/*')) {
            return $next($request);
        }
        $preview = config('backup.preview');
        $hold = is_file(config('backup.restore_hold'));
        if ($preview && ! in_array($request->getHost(), ['localhost', '127.0.0.1'], true) && ! str_ends_with($request->getHost(), '.localhost')) {
            abort(403);
        }
        if (($preview || $hold) && ($request->is('api/*') || (! in_array($request->method(), ['GET', 'HEAD'], true) && ! $request->is('admin/login', 'staff/*/login', 'logout') && ! (! $preview && $request->is('admin/backups/resume'))))) {
            return response()->json(['message' => $preview ? 'Pratinjau pemulihan hanya untuk membaca. Transaksi dan perubahan data dinonaktifkan.' : 'Hasil pemulihan sedang ditinjau. Jalankan prosedur resume setelah rekonsiliasi.'], 423);
        }
        if ($preview && $request->is('storage/*')) {
            $root = realpath(storage_path('app/public'));
            $file = realpath(storage_path('app/public/'.substr($request->path(), 8)));
            abort_unless($root && $file && is_file($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

            return response()->file($file, ['X-Content-Type-Options' => 'nosniff']);
        }
        if (! config('backup.live_enabled') || $preview) {
            $response = $next($request);
            if ($preview) {
                $response->headers->set('X-DigitalToko-Recovery-Preview', 'read-only');
                if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
                    $banner = '<div style="position:fixed;bottom:12px;left:12px;z-index:9999;background:#fff3c4;color:#513a00;border:1px solid #e4c657;border-radius:12px;padding:10px 16px;font:600 14px system-ui">Pratinjau pemulihan · hanya baca</div>';
                    $response->setContent(str_replace('</body>', $banner.'</body>', $response->getContent()));
                }
            }

            return $response;
        }
        $lease = fopen(config('backup.restore_lock'), 'c');
        if (! flock($lease, LOCK_SH | LOCK_NB)) {
            fclose($lease);

            return response('Pemulihan sedang berlangsung. Silakan tunggu.', 503, ['Retry-After' => '15']);
        }
        try {
            return $next($request);
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }
}
