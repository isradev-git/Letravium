<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookMark extends Model
{
    protected $fillable = [
        'novel_id', 'type', 'label', 'caption', 'target_type', 'target_id',
        'sort_key', 'position', 'data',
    ];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
