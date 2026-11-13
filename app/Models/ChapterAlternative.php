<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChapterAlternative extends Model
{
    protected $fillable = ['chapter_id', 'work_state_id', 'alias', 'is_active', 'plan'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'plan' => 'array'];
    }

    public function scenes()
    {
        return $this->hasMany(Scene::class)->orderBy('position');
    }
}
