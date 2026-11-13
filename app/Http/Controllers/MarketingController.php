<?php

namespace App\Http\Controllers;

use App\Models\MarketingConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketingController extends Controller
{
    public function preferences(Request $request)
    {
        return view('account.marketing', [
            'consent' => MarketingConsent::where('user_id', $request->user()->id)->first(),
        ]);
    }

    public function requestConsent(Request $request)
    {
        $consent = MarketingConsent::firstOrNew(['user_id' => $request->user()->id]);

        if ($consent->status === MarketingConsent::STATUS_ACTIVE) {
            return response()->json(['status' => MarketingConsent::STATUS_ACTIVE]);
        }

        $consent->fill([
            'status' => MarketingConsent::STATUS_PENDING,
            'token' => Str::random(64),
            'text_version' => config('marketing.consent_text_version'),
            'source' => 'account',
            'requested_at' => now(),
            'confirmed_at' => null,
            'withdrawn_at' => null,
        ])->save();

        if (! $request->expectsJson()) {
            return redirect('/account/marketing')->with('status', 'Solicitud registrada. Confirma el consentimiento para activarlo.');
        }

        return response()->json([
            'status' => MarketingConsent::STATUS_PENDING,
            'text_version' => $consent->text_version,
            'token' => $consent->token,
        ]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);

        $consent = MarketingConsent::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->first();

        if (! $consent || $consent->status !== MarketingConsent::STATUS_PENDING) {
            if (! $request->expectsJson()) {
                return redirect('/account/marketing')->withErrors(['token' => 'Confirmación no válida.']);
            }

            return response()->json(['message' => 'Confirmación no válida.'], 422);
        }

        $consent->forceFill([
            'status' => MarketingConsent::STATUS_ACTIVE,
            'confirmed_at' => now(),
        ])->save();

        if (! $request->expectsJson()) {
            return redirect('/account/marketing')->with('status', 'Consentimiento comercial activado.');
        }

        return response()->json(['status' => MarketingConsent::STATUS_ACTIVE]);
    }

    public function withdraw(Request $request)
    {
        $consent = MarketingConsent::where('user_id', $request->user()->id)->first();

        if ($consent && $consent->status !== MarketingConsent::STATUS_WITHDRAWN) {
            $consent->forceFill([
                'status' => MarketingConsent::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
            ])->save();
        }

        if (! $request->expectsJson()) {
            return redirect('/account/marketing')->with('status', 'Consentimiento comercial retirado.');
        }

        return response()->json(['status' => MarketingConsent::STATUS_WITHDRAWN]);
    }

    public function unsubscribe(Request $request, string $token)
    {
        $consent = MarketingConsent::where('token', $token)->first();

        if ($consent && $consent->status !== MarketingConsent::STATUS_WITHDRAWN) {
            $consent->forceFill([
                'status' => MarketingConsent::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
            ])->save();
        }

        return $request->expectsJson()
            ? response()->json(['status' => 'unsubscribed'])
            : response('Suscripción comercial cancelada.', 200);
    }
}
