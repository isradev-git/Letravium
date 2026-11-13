<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountQuota extends Model
{
    public const DEFAULT_BYTES = 262144000;

    protected $fillable = ['user_id', 'bytes_limit', 'bytes_used'];

    protected function casts(): array
    {
        return ['bytes_limit' => 'integer', 'bytes_used' => 'integer'];
    }
}
