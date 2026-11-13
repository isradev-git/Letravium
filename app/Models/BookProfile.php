<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookProfile extends Model
{
    protected $fillable = [
        'novel_id', 'name', 'page_size', 'orientation', 'margins', 'binding',
        'fonts', 'chapter_policy', 'notes_policy', 'facing_pages',
        'right_page_starts', 'running_heads', 'widows', 'orphans',
        'keep_with_next', 'cover', 'compositor_limits',
    ];

    protected function casts(): array
    {
        return [
            'margins' => 'array',
            'fonts' => 'array',
            'chapter_policy' => 'array',
            'notes_policy' => 'array',
            'running_heads' => 'array',
            'cover' => 'array',
            'compositor_limits' => 'array',
            'facing_pages' => 'boolean',
            'right_page_starts' => 'boolean',
            'keep_with_next' => 'boolean',
        ];
    }

    public function novel()
    {
        return $this->belongsTo(Novel::class);
    }
}
