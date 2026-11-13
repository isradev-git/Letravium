<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryChange extends Model
{
    protected $fillable = [
        'universe_id', 'card_id', 'field', 'value', 'effective_event_id', 'moment', 'created_by',
    ];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
