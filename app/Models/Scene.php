<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scene extends Model
{
    protected $fillable = [
        'chapter_alternative_id', 'title', 'position', 'summary',
        'pov_card_id', 'place_card_id', 'narrative_moment', 'plan', 'content',
    ];

    protected function casts(): array
    {
        return ['plan' => 'array', 'content' => 'array'];
    }

    public function plots()
    {
        return $this->belongsToMany(Plot::class);
    }
}
