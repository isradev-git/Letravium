<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrectionProposal extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const STALE = 'stale';

    protected $fillable = ['comment_id', 'base_revision', 'original', 'proposed', 'status'];

    public function comment()
    {
        return $this->belongsTo(Comment::class);
    }
}
