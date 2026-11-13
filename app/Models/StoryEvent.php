<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryEvent extends Model
{
    protected $table = 'events';

    protected $fillable = [
        'universe_id', 'name', 'type', 'description', 'moment', 'participants', 'places',
    ];

    protected function casts(): array
    {
        return ['participants' => 'array', 'places' => 'array'];
    }
}
