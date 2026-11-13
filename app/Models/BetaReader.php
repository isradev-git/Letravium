<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BetaReader extends Model
{
    protected $fillable = [
        'snapshot_id', 'user_id', 'email', 'token',
        'expires_at', 'can_comment', 'scope',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'can_comment' => 'boolean',
            'scope' => 'array',
        ];
    }

    public function snapshot()
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function allowsChapter(?int $chapterId): bool
    {
        if ($this->scope === null || ! isset($this->scope['chapters'])) {
            return true;
        }

        return $chapterId !== null
            && in_array($chapterId, array_map('intval', $this->scope['chapters']), true);
    }
}
