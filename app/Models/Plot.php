<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plot extends Model
{
    protected $fillable = ['novel_id', 'name', 'description', 'notes'];

    protected function casts(): array
    {
        return ['notes' => 'array'];
    }

    public function scenes()
    {
        return $this->belongsToMany(Scene::class);
    }
}
