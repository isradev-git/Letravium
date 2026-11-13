<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BibliographyEntry extends Model
{
    protected $fillable = [
        'universe_id', 'novel_id', 'author_id', 'author', 'title', 'date',
        'publisher', 'url', 'observations', 'selected',
    ];

    protected function casts(): array
    {
        return ['selected' => 'boolean'];
    }

    public function notes()
    {
        return $this->belongsToMany(Note::class);
    }
}
