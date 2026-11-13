<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoteLink extends Model
{
    protected $fillable = ['note_id', 'linkable_type', 'linkable_id', 'anchor'];

    public function note()
    {
        return $this->belongsTo(Note::class);
    }
}
