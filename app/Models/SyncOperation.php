<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    protected $fillable = ['user_id', 'device_id', 'idempotency_key', 'payload_hash', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
