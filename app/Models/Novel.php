<?php

namespace App\Models;

use App\Models\Concerns\ExcludesTrashed;
use Illuminate\Database\Eloquent\Model;

class Novel extends Model
{
    use ExcludesTrashed;

    protected static string $trashType = 'novel';

    protected $fillable = ['universe_id', 'owner_id', 'title', 'settings'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function universe()
    {
        return $this->belongsTo(Universe::class);
    }

    public function chapters()
    {
        return $this->hasMany(Chapter::class)->orderBy('position');
    }

    public function parts()
    {
        return $this->hasMany(Part::class)->orderBy('position');
    }

    public function workStates()
    {
        return $this->hasMany(WorkState::class)->orderBy('position');
    }

    public function styles()
    {
        return $this->hasMany(Style::class);
    }

    public function footnotes()
    {
        return $this->hasMany(Footnote::class);
    }
}
