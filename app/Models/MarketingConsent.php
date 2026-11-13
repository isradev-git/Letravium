<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingConsent extends Model
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'user_id',
        'status',
        'token',
        'text_version',
        'source',
        'requested_at',
        'confirmed_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }
}
