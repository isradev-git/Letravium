<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookSection extends Model
{
    protected $fillable = [
        'novel_id', 'area', 'type', 'title', 'content', 'position', 'include_in_export',
    ];

    protected function casts(): array
    {
        return ['include_in_export' => 'boolean'];
    }
}
