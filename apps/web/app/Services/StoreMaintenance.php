<?php

namespace App\Services;

use App\Models\MaintenanceWindow;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StoreMaintenance
{
    public function scope(?Store $store): string
    {
        return $store ? 'store:'.$store->id : 'platform';
    }

    public function active(?Store $store = null): ?array
    {
        $scopes = ['platform', ...($store ? [$this->scope($store)] : [])];
        $rows = MaintenanceWindow::whereIn('scope', $scopes)->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get();
        $row = $rows->firstWhere('scope', 'platform') ?? $rows->first();
        if (! $row) {
            return null;
        }
        // The countdown must include a local window which lasts beyond a global window.
        $ends = $rows->contains(fn ($item) => ! $item->ends_at) ? null : $rows->max('ends_at');

        return ['message' => $row->message ?: 'Kami sedang melakukan pemeliharaan agar layanan lebih nyaman dan andal. Silakan kembali beberapa saat lagi.',
            'ends_at' => $ends?->toIso8601String(), 'scope' => $row->scope === 'platform' ? 'platform' : 'store'];
    }

    public function assertOpen(Store $store): void
    {
        $active = $this->active($store);
        if ($active) {
            throw ValidationException::withMessages(['maintenance' => 'Layanan sedang maintenance. '.$active['message'].($active['ends_at'] ? ' Estimasi buka: '.CarbonImmutable::parse($active['ends_at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB.' : '')]);
        }
    }

    public function settings(?Store $store): array
    {
        $row = MaintenanceWindow::where('scope', $this->scope($store))->first();

        return ['enabled' => $row?->enabled ?? false, 'message' => $row?->message ?? '', 'ends_at' => $row?->ends_at?->toIso8601String(),
            'active' => (bool) ($row?->enabled && (! $row->ends_at || $row->ends_at->isFuture()))];
    }
}
