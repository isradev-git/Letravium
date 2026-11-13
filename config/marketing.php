<?php

return [
    'consent_text_version' => env('MARKETING_CONSENT_TEXT_VERSION', '2026-11-v1'),

    'campaign_batch_size' => (int) env('MARKETING_CAMPAIGN_BATCH_SIZE', 50),

    'campaign_claim_ttl' => (int) env('MARKETING_CAMPAIGN_CLAIM_TTL', 300),
];
