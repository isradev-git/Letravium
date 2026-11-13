<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookMetadata extends Model
{
    protected $fillable = [
        'novel_id', 'title', 'subtitle', 'author', 'language', 'description',
        'rights', 'edition', 'publisher', 'isbn', 'extra',
    ];

    protected function casts(): array
    {
        return ['extra' => 'array'];
    }
}
