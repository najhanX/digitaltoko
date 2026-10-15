<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNotice extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['message', 'phone', 'email'];

    protected function casts(): array
    {
        return ['message' => 'encrypted', 'wa_sent_at' => 'immutable_datetime', 'email_sent_at' => 'immutable_datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
