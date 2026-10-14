<?php

namespace App\Models;

use Database\Factories\PanelNotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PanelNotification extends Model
{
    /** @use HasFactory<PanelNotificationFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['user_id', 'staff_id', 'event_key'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
