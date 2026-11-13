<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($data['email']);
        $key = 'login:'.Str::lower($identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Demasiados intentos.'], 429)
                : back()->withErrors(['email' => 'Demasiados intentos.']);
        }

        RateLimiter::hit($key, 60);

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Credenciales no válidas.'], 422)
                : redirect('/login')
                    ->withErrors(['email' => 'Credenciales no válidas.'])
                    ->withInput($request->only('email'));
        }

        RateLimiter::clear($key);

        if ($user->totp_confirmed_at) {
            $request->session()->put('pending_user_id', $user->id);
            $request->session()->put('pending_remember', $request->boolean('remember'));
            $request->session()->put('pending_password_verified', true);

            return $request->expectsJson()
                ? response()->json(['status' => 'two_factor_required'], 202)
                : redirect('/two-factor/challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('last_activity_at', now()->timestamp);
        $request->session()->put('auth_remember', $request->boolean('remember'));
        $request->session()->put('session_started_at', now()->timestamp);

        return $request->expectsJson()
            ? response()->json(['status' => 'enrollment_required'], 200)
            : redirect('/account/two-factor');
    }

    public function challengeForm()
    {
        return view('auth.two-factor-challenge');
    }

    public function challenge(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get('pending_user_id');
        $user = $userId ? User::find($userId) : null;

        if ($user) {
            $limitKey = 'totp-challenge:'.$user->id;

            if (RateLimiter::tooManyAttempts($limitKey, 5)) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Demasiados intentos de código.'], 429)
                    : back()->withErrors(['code' => 'Demasiados intentos de código.']);
            }

            RateLimiter::hit($limitKey, 60);
        }

        if (! $user || ! app(TotpService::class)->consumeFactor($user, $request->string('code'))) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Código no válido o ya utilizado.'], 422)
                : back()->withErrors(['code' => 'Código no válido o ya utilizado.']);
        }

        RateLimiter::clear('totp-challenge:'.$user->id);

        $remember = (bool) $request->session()->pull('pending_remember', false);
        $passwordVerified = (bool) $request->session()->pull('pending_password_verified', false);
        $request->session()->forget('pending_user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('totp_verified_at', now()->timestamp);
        $request->session()->put('last_activity_at', now()->timestamp);

        if ($passwordVerified) {
            $request->session()->put('reauth_at', now()->timestamp);
        }

        $request->session()->put('auth_remember', $remember);
        $startedAt = (int) $request->session()->pull('pending_started_at', 0);
        $request->session()->put('session_started_at', $startedAt ?: now()->timestamp);

        return $request->expectsJson()
            ? response()->json(['status' => 'ok'], 200)
            : redirect()->intended($user->homePath());
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['status' => 'ok'], 200)
            : redirect('/');
    }

    public function forgotPasswordForm()
    {
        return view('auth.forgot-password');
    }

    public function resetPasswordForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return $request->expectsJson()
            ? response()->json(['status' => 'sent'], 200)
            : redirect('/forgot-password')->with('status', 'Si la cuenta existe, enviamos un enlace.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $request->expectsJson()
                ? response()->json(['message' => 'No se pudo restablecer.'], 422)
                : back()->withErrors(['email' => 'No se pudo restablecer.']);
        }

        return $request->expectsJson()
            ? response()->json(['status' => 'password_reset'], 200)
            : redirect('/login');
    }

    public function verificationNotice(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($request->user()->homePath());
        }

        return view('auth.verify-email');
    }

    public function resendVerification(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['status' => 'already_verified'], 200)
                : redirect($request->user()->homePath());
        }

        $request->user()->sendEmailVerificationNotification();

        return $request->expectsJson()
            ? response()->json(['status' => 'verification_sent'], 200)
            : back()->with('status', 'Reenviamos el enlace de verificación.');
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $request->expectsJson()
            ? response()->json(['status' => 'verified'], 200)
            : redirect('/account/two-factor');
    }
}
