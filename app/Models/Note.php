<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $fillable = [
        'universe_id', 'novel_id', 'author_id', 'title', 'body', 'content',
        'private', 'pending', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'private' => 'boolean',
            'pending' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function links()
    {
        return $this->hasMany(NoteLink::class);
    }

    public function bibliography()
    {
        return $this->belongsToMany(BibliographyEntry::class);
    }
}
