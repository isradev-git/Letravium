<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContinuityDismissal extends Model
{
    protected $fillable = ['universe_id', 'user_id', 'fingerprint', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }
}
