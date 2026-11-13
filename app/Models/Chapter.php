<?php

namespace App\Models;

use App\Models\Concerns\ExcludesTrashed;
use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    use ExcludesTrashed;

    protected static string $trashType = 'chapter';

    protected $fillable = ['novel_id', 'part_id', 'title', 'position', 'plan'];

    protected function casts(): array
    {
        return ['plan' => 'array'];
    }

    public function alternatives()
    {
        return $this->hasMany(ChapterAlternative::class)->orderBy('id');
    }

    public function activeAlternative()
    {
        return $this->hasOne(ChapterAlternative::class)->where('is_active', true);
    }
}
