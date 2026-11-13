<?php

namespace App\Http\Controllers;

use App\Mail\SecurityNoticeMail;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $service = app(TotpService::class);

        if ($user->totp_confirmed_at) {
            if (! $user->totp_pending_secret) {
                if (! $user->hasVerifiedEmail()) {
                    return $request->expectsJson()
                        ? response()->json([
                            'message' => 'Verifica tu correo antes de entrar.',
                            'confirmed' => true,
                            'email_verified' => false,
                        ], 409)
                        : redirect('/account/verify-email');
                }

                return $request->expectsJson()
                    ? response()->json(['message' => 'La verificación en dos pasos ya está activa.', 'confirmed' => true], 409)
                    : redirect($user->homePath());
            }

            if (! $this->recentlyAuthorized($request)) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Confirma tu contraseña y tu factor actual.'], 409)
                    : redirect('/account/confirm-password');
            }

            return $request->expectsJson()
                ? response()->json([
                    'secret' => $user->totp_pending_secret,
                    'otpauth_url' => $service->otpauthUrl($user, $user->totp_pending_secret),
                    'confirmed' => false,
                    'replacement' => true,
                ])
                : view('auth.two-factor-enroll', ['secret' => $user->totp_pending_secret]);
        }

        if (! $user->totp_secret) {
            $user->forceFill(['totp_secret' => $service->newSecret()])->save();
        }

        return $request->expectsJson()
            ? response()->json([
                'secret' => $user->totp_secret,
                'otpauth_url' => $service->otpauthUrl($user, $user->totp_secret),
                'confirmed' => false,
            ])
            : view('auth.two-factor-enroll', ['secret' => $user->totp_secret]);
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();
        $service = app(TotpService::class);
        $pendingReplacement = $user->totp_confirmed_at !== null && $user->totp_pending_secret !== null;

        if ($user->totp_confirmed_at && ! $pendingReplacement) {
            return $request->expectsJson()
                ? response()->json(['message' => 'El factor ya está confirmado; usa la sustitución protegida.'], 409)
                : back()->withErrors(['code' => 'El factor ya está confirmado.']);
        }

        if ($pendingReplacement && ! $this->recentlyAuthorized($request)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Confirma tu contraseña y tu factor actual.'], 423)
                : redirect('/account/confirm-password');
        }

        if (! $pendingReplacement && ! $user->totp_secret) {
            $user->forceFill(['totp_secret' => $service->newSecret()])->save();
        }

        $codes = $service->confirm($user, $request->string('code'));

        if ($codes === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Código no válido.'], 422)
                : back()->withErrors(['code' => 'Código no válido.']);
        }

        $request->session()->put('totp_verified_at', now()->timestamp);
        $request->session()->put('last_activity_at', now()->timestamp);
        $request->session()->put('reauth_at', now()->timestamp);
        $request->session()->put('session_started_at', now()->timestamp);

        return $request->expectsJson()
            ? response()->json([
                'status' => 'confirmed',
                'recovery_codes' => $codes,
                'email_verified' => $user->hasVerifiedEmail(),
            ], 200)
            : redirect($user->hasVerifiedEmail() ? $user->homePath() : '/account/verify-email');
    }

    public function confirmPasswordForm()
    {
        return view('auth.confirm-password');
    }

    public function confirmPassword(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->string('password'), $user->password)
            || ! app(TotpService::class)->consumeFactor($user, $request->string('code'))) {
            return $request->expectsJson()
                ? response()->json(['message' => 'No se pudo confirmar.'], 422)
                : back()->withErrors(['password' => 'No se pudo confirmar.']);
        }

        $request->session()->put('reauth_at', now()->timestamp);

        $return = $request->session()->pull('reauth_return');
        $target = is_string($return) && str_starts_with($return, '/') && ! str_starts_with($return, '//')
            ? $return
            : $user->homePath();

        return $request->expectsJson()
            ? response()->json(['status' => 'reauthenticated'], 200)
            : redirect($target);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $codes = app(TotpService::class)->replaceRecoveryCodes($request->user());

        DB::table('security_events')->insert([
            'user_id' => $request->user()->id,
            'type' => 'recovery_codes_regenerated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::to($request->user()->email)->send(new SecurityNoticeMail(
            'Se han regenerado los códigos de recuperación de tu cuenta.',
        ));

        return $request->expectsJson()
            ? response()->json(['recovery_codes' => $codes], 200)
            : response()->json(['recovery_codes' => $codes], 200);
    }

    public function replace(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->string('password'), $user->password)
            || ! app(TotpService::class)->consumeFactor($user, $request->string('code'))) {
            return response()->json(['message' => 'No se pudo sustituir el factor.'], 422);
        }

        $secret = app(TotpService::class)->newSecret();

        $user->forceFill([
            'totp_pending_secret' => $secret,
            'totp_pending_started_at' => now(),
        ])->save();

        DB::table('security_events')->insert([
            'user_id' => $user->id,
            'type' => 'two_factor_replaced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::to($user->email)->send(new SecurityNoticeMail(
            'Se ha iniciado la sustitución del autenticador de tu cuenta.',
        ));

        return response()->json([
            'status' => 'replacement_pending_confirmation',
            'secret' => $secret,
            'otpauth_url' => app(TotpService::class)->otpauthUrl($user, $secret),
        ]);
    }

    private function recentlyAuthorized(Request $request): bool
    {
        foreach (['reauth_at', 'totp_verified_at'] as $key) {
            $at = (int) $request->session()->get($key, 0);

            if ($at !== 0 && now()->timestamp - $at <= 5 * 60) {
                return true;
            }
        }

        return false;
    }
}
