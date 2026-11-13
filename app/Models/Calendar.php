<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Calendar extends Model
{
    protected $fillable = ['universe_id', 'name', 'config', 'is_current', 'superseded_at'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_current' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }
}
