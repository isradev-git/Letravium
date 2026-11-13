<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryContext extends Model
{
    protected $fillable = [
        'universe_id', 'novel_id', 'scene_id', 'card_id', 'event_id', 'context', 'captured_at',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'captured_at' => 'datetime'];
    }
}
