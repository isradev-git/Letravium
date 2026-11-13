<?php

namespace App\Services;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MarketingConsent;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

class CampaignSender
{
    private ?string $unsubscribeUrl = null;

    public function sendBatch(Campaign $campaign, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('marketing.campaign_batch_size', 50);

        $counts = ['accepted' => 0, 'failed' => 0, 'uncertain' => 0];

        $recipients = $campaign->recipients()
            ->where('state', CampaignRecipient::STATE_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($recipients as $recipient) {
            $claimed = CampaignRecipient::whereKey($recipient->id)
                ->where('state', CampaignRecipient::STATE_PENDING)
                ->update([
                    'state' => CampaignRecipient::STATE_SENDING,
                    'claimed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue;
            }

            $consent = $recipient->user_id
                ? MarketingConsent::where('user_id', $recipient->user_id)->first()
                : null;

            if (! $consent || $consent->status !== MarketingConsent::STATUS_ACTIVE) {
                $this->completeClaim($recipient, CampaignRecipient::STATE_FAILED, 'consent_withdrawn');

                $counts['failed']++;

                continue;
            }

            $this->unsubscribeUrl = $consent->token
                ? route('marketing.unsubscribe', ['token' => $consent->token])
                : null;

            try {
                $this->deliver($campaign, $recipient->email);

                if ($this->completeClaim($recipient, CampaignRecipient::STATE_ACCEPTED)) {
                    $counts['accepted']++;
                }
            } catch (UnexpectedResponseException $e) {
                if ($this->completeClaim($recipient, CampaignRecipient::STATE_FAILED, mb_substr($e->getMessage(), 0, 255))) {
                    $counts['failed']++;
                }
            } catch (TransportExceptionInterface $e) {
                if ($this->completeClaim($recipient, CampaignRecipient::STATE_UNCERTAIN, 'ambiguous_transport')) {
                    $counts['uncertain']++;
                }
            } catch (Throwable $e) {
                if ($this->completeClaim($recipient, CampaignRecipient::STATE_FAILED, mb_substr($e->getMessage(), 0, 255))) {
                    $counts['failed']++;
                }
            }
        }

        $remaining = $campaign->recipients()
            ->whereIn('state', [CampaignRecipient::STATE_PENDING, CampaignRecipient::STATE_SENDING])
            ->exists();

        $campaign->forceFill([
            'status' => $remaining ? Campaign::STATUS_PAUSED : Campaign::STATUS_DONE,
        ])->save();

        return $counts + ['remaining' => $remaining ? 1 : 0];
    }

    public function completeClaim(CampaignRecipient $recipient, string $state, ?string $error = null): bool
    {
        $attributes = [
            'state' => $state,
            'error' => $error,
            'updated_at' => now(),
        ];

        if ($state === CampaignRecipient::STATE_ACCEPTED) {
            $attributes['sent_at'] = now();
        }

        return CampaignRecipient::whereKey($recipient->id)
            ->where('state', CampaignRecipient::STATE_SENDING)
            ->update($attributes) === 1;
    }

    protected function deliver(Campaign $campaign, string $email): void
    {
        Mail::to($email)->send(new CampaignMail($campaign, $this->unsubscribeUrl));
    }
}
