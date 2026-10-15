<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePaymentSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'store_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'sandbox' => 'boolean', 'allow_guest' => 'boolean', 'closing' => 'boolean', 'refund_unique' => 'boolean', 'topup_unique' => 'boolean', 'custom_licensed_at' => 'immutable_datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
