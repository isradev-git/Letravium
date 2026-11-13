<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Footnote extends Model
{
    protected $fillable = ['novel_id', 'scene_id', 'kind', 'number', 'text', 'content'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function novel()
    {
        return $this->belongsTo(Novel::class);
    }
}
