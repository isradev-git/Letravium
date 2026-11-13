<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlossaryTerm extends Model
{
    protected $fillable = [
        'universe_id', 'language_card_id', 'term', 'meaning', 'preferred_form', 'variants', 'examples',
    ];

    protected function casts(): array
    {
        return ['variants' => 'array', 'examples' => 'array'];
    }
}
