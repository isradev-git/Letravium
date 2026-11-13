<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = [
        'novel_id', 'chapter_id', 'scene_id', 'snapshot_id',
        'block_id', 'quoted_text', 'status', 'author_id',
    ];

    public function novel()
    {
        return $this->belongsTo(Novel::class);
    }

    public function messages()
    {
        return $this->hasMany(CommentMessage::class)->orderBy('id');
    }

    public function corrections()
    {
        return $this->hasMany(CorrectionProposal::class)->orderBy('id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function snapshot()
    {
        return $this->belongsTo(Snapshot::class);
    }
}
