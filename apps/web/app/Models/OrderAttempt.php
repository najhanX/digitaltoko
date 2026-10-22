<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class OrderAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['request_snapshot', 'result'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class);
    }

    public function recordSupplierResponse(string $message, ?string $error = null): void
    {
        DB::transaction(function () use ($message, $error): void {
            $attempt = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $snapshot = $attempt->request_snapshot;
            $snapshot['supplier_response'] = ['message' => $message, 'parser_error' => $error];
            $attempt->update(['request_snapshot' => $snapshot]);
            $this->request_snapshot = $snapshot;
        });
    }

    protected function casts(): array
    {
        return ['request_snapshot' => 'encrypted:array', 'result' => 'encrypted', 'cost' => 'integer', 'last_checked_at' => 'immutable_datetime'];
    }
}
