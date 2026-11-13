<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MarketingConsent;
use App\Models\User;
use App\Services\CampaignSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignAdminController extends Controller
{
    public function index(Request $request)
    {
        $campaigns = Campaign::orderByDesc('id')->get();

        if ($request->expectsJson()) {
            return response()->json(['campaigns' => $campaigns]);
        }

        return view('admin.campaigns', [
            'campaigns' => $campaigns,
            'active' => 'campaigns',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'from_email' => ['required', 'email', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
        ]);

        $campaign = Campaign::create($data + [
            'status' => Campaign::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        if ($this->wantsHtml($request)) {
            return redirect("/admin/campaigns/{$campaign->id}")->with('status', 'Campaña creada.');
        }

        return response()->json(['campaign' => $campaign], 201);
    }

    public function show(Request $request, Campaign $campaign)
    {
        $counts = $campaign->recipients()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $states = [];

        foreach ([
            CampaignRecipient::STATE_PENDING,
            CampaignRecipient::STATE_SENDING,
            CampaignRecipient::STATE_ACCEPTED,
            CampaignRecipient::STATE_FAILED,
            CampaignRecipient::STATE_UNCERTAIN,
        ] as $state) {
            $states[$state] = (int) ($counts[$state] ?? 0);
        }

        $data = [
            'campaign' => $campaign,
            'eligible' => MarketingConsent::where('status', MarketingConsent::STATUS_ACTIVE)->count(),
            'excluded' => [
                'pending' => MarketingConsent::where('status', MarketingConsent::STATUS_PENDING)->count(),
                'withdrawn' => MarketingConsent::where('status', MarketingConsent::STATUS_WITHDRAWN)->count(),
            ],
            'states' => $states,
            'recipients' => $campaign->recipients()
                ->orderBy('id')
                ->get(['id', 'email', 'state', 'error', 'sent_at']),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return view('admin.campaign', $data + ['active' => 'campaigns']);
    }

    public function reconcileRecipient(Request $request, Campaign $campaign, CampaignRecipient $recipient)
    {
        abort_unless((int) $recipient->campaign_id === (int) $campaign->id, 404);

        $data = $request->validate([
            'state' => ['required', 'in:'.implode(',', [
                CampaignRecipient::STATE_ACCEPTED,
                CampaignRecipient::STATE_FAILED,
                CampaignRecipient::STATE_UNCERTAIN,
            ])],
        ]);

        $ttl = max(1, (int) config('marketing.campaign_claim_ttl', 300));

        $result = DB::transaction(function () use ($request, $campaign, $recipient, $data, $ttl) {
            $locked = CampaignRecipient::whereKey($recipient->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->campaign_id !== (int) $campaign->id) {
                return ['status' => 404];
            }

            if (! in_array($locked->state, [CampaignRecipient::STATE_SENDING, CampaignRecipient::STATE_UNCERTAIN], true)) {
                return [
                    'status' => 409,
                    'message' => 'Solo se reconcilian envíos interrumpidos o inciertos.',
                ];
            }

            if ($locked->state === CampaignRecipient::STATE_SENDING
                && $locked->claimed_at !== null
                && $locked->claimed_at->gt(now()->subSeconds($ttl))) {
                return [
                    'status' => 409,
                    'message' => 'El envío sigue activo; espera a que se interrumpa o venza el plazo.',
                ];
            }

            $previous = $locked->state;

            $locked->forceFill([
                'state' => $data['state'],
                'sent_at' => $data['state'] === CampaignRecipient::STATE_ACCEPTED
                    ? ($locked->sent_at ?? now())
                    : $locked->sent_at,
                'error' => match ($data['state']) {
                    CampaignRecipient::STATE_ACCEPTED => null,
                    CampaignRecipient::STATE_UNCERTAIN => 'operator_uncertain',
                    default => 'operator_failed',
                },
            ])->save();

            AdminAuditEvent::create([
                'actor_id' => $request->user()->id,
                'action' => 'campaign_recipient_reconciled',
                'subject_type' => CampaignRecipient::class,
                'subject_id' => $locked->id,
                'metadata' => [
                    'campaign_id' => $campaign->id,
                    'from' => $previous,
                    'to' => $data['state'],
                ],
            ]);

            $open = $campaign->recipients()
                ->whereIn('state', [CampaignRecipient::STATE_PENDING, CampaignRecipient::STATE_SENDING])
                ->exists();

            $campaign->forceFill([
                'status' => $open ? Campaign::STATUS_PAUSED : Campaign::STATUS_DONE,
            ])->save();

            return [
                'status' => 200,
                'campaign' => $campaign->fresh(),
                'recipient' => $locked->refresh(),
            ];
        });

        if ($result['status'] === 404) {
            abort(404);
        }

        if ($result['status'] === 409) {
            if ($this->wantsHtml($request)) {
                return redirect("/admin/campaigns/{$campaign->id}")->withErrors(['campaign' => $result['message']]);
            }

            return response()->json(['message' => $result['message']], 409);
        }

        if ($this->wantsHtml($request)) {
            return redirect("/admin/campaigns/{$campaign->id}")
                ->with('status', 'Reconciliación guardada: '.$result['recipient']->state.'.');
        }

        return response()->json([
            'status' => $result['campaign']->status,
            'recipient' => $result['recipient']->only(['id', 'state', 'error', 'sent_at']),
        ]);
    }

    public function start(Request $request, Campaign $campaign, CampaignSender $sender)
    {
        if ($campaign->status === Campaign::STATUS_DONE) {
            if ($this->wantsHtml($request)) {
                return redirect("/admin/campaigns/{$campaign->id}")->withErrors(['campaign' => 'La campaña ya terminó.']);
            }

            return response()->json(['message' => 'La campaña ya terminó.'], 409);
        }

        if ($campaign->status === Campaign::STATUS_DRAFT) {
            $this->prepareRecipients($campaign);
        }

        $campaign->forceFill(['status' => Campaign::STATUS_RUNNING])->save();

        $result = $sender->sendBatch($campaign);

        if ($this->wantsHtml($request)) {
            return redirect("/admin/campaigns/{$campaign->id}")->with(
                'status',
                "Lote procesado: {$result['accepted']} aceptados, {$result['failed']} fallidos, {$result['uncertain']} inciertos.",
            );
        }

        return response()->json([
            'status' => $campaign->fresh()->status,
            'result' => $result,
        ]);
    }

    public function pause(Request $request, Campaign $campaign)
    {
        if (! in_array($campaign->status, [Campaign::STATUS_DRAFT, Campaign::STATUS_RUNNING], true)) {
            if ($this->wantsHtml($request)) {
                return redirect("/admin/campaigns/{$campaign->id}")->withErrors(['campaign' => 'La campaña no se puede pausar.']);
            }

            return response()->json(['message' => 'La campaña no se puede pausar.'], 409);
        }

        $campaign->forceFill(['status' => Campaign::STATUS_PAUSED])->save();

        if ($this->wantsHtml($request)) {
            return redirect("/admin/campaigns/{$campaign->id}")->with('status', 'Campaña pausada.');
        }

        return response()->json(['status' => Campaign::STATUS_PAUSED]);
    }

    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    private function prepareRecipients(Campaign $campaign): void
    {
        $consents = MarketingConsent::where('status', MarketingConsent::STATUS_ACTIVE)
            ->orderBy('id')
            ->get(['user_id']);

        $users = User::whereIn('id', $consents->pluck('user_id'))
            ->whereNull('closed_at')
            ->get(['id', 'email'])
            ->keyBy('id');

        foreach ($consents as $consent) {
            $user = $users->get($consent->user_id);

            if (! $user) {
                continue;
            }

            CampaignRecipient::firstOrCreate(
                ['campaign_id' => $campaign->id, 'user_id' => $user->id],
                ['email' => $user->email],
            );
        }
    }
}
