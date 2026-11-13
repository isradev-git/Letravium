<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardRelationship extends Model
{
    protected $fillable = [
        'universe_id', 'source_card_id', 'target_card_id', 'label', 'directed',
    ];

    protected function casts(): array
    {
        return ['directed' => 'boolean'];
    }
}
