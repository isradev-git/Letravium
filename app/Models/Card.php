<?php

namespace App\Models;

use App\Models\Concerns\ExcludesTrashed;
use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    use ExcludesTrashed;

    protected static string $trashType = 'card';

    public const AREAS = [
        'characters', 'places', 'rules', 'groups',
        'history', 'objects', 'languages', 'notes',
    ];

    protected $fillable = ['universe_id', 'area', 'name', 'template', 'fields', 'text'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'text' => 'array'];
    }
}
