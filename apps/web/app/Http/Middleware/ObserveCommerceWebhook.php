<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ObserveCommerceWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (config('backup.preview') || is_file(config('backup.restore_hold')) || app()->isDownForMaintenance()) {
            return;
        }
        if (! in_array($request->method(), ['GET', 'POST'], true) || ! preg_match('#^api/commerce/(digiflazz|midtrans|otomax|forward)(?:/|$)#D', $request->path(), $matches)) {
            return;
        }
        $code = $response->getStatusCode();
        $data = json_decode($response->getContent() ?: '{}', true);
        $outcome = $code >= 400 ? 'rejected' : 'accepted';
        if ($code < 400 && is_array($data) && is_string($data['status'] ?? null)) {
            $outcome = match (strtoupper($data['status'])) {
                'IGNORED' => 'ignored', 'REVIEW' => 'review', 'ERROR' => 'error', default => 'accepted',
            };
        }
        if ($code >= 300 && $code < 400) {
            $outcome = 'redirected';
        }
        // Aggregate only allowlisted provider/outcome and HTTP status; never URL, token, body or exception.
        try {
            $keys = ['bucket' => now()->startOfMinute()->format('Y-m-d H:i:s'), 'provider' => $matches[1], 'http_status' => $code, 'outcome' => $outcome];
            DB::table('system_webhook_health')->insertOrIgnore([...$keys, 'requests' => 0, 'last_seen_at' => now()]);
            DB::table('system_webhook_health')->where($keys)->increment('requests', 1, ['last_seen_at' => now()]);
        } catch (\Throwable) {
            // Schema rollout or monitoring outage must never reject an otherwise valid callback.
        }
    }
}
