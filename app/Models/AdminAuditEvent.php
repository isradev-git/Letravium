<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditEvent extends Model
{
    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
