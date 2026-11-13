<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_SENDING = 'sending';

    public const STATE_ACCEPTED = 'accepted';

    public const STATE_FAILED = 'failed';

    public const STATE_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'email',
        'state',
        'claimed_at',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
