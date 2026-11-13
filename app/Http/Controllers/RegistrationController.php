<?php

namespace App\Http\Controllers;

use App\Models\AccountQuota;
use App\Models\CreativeMembership;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function create(Request $request)
    {
        if ($request->user()) {
            return redirect($request->user()->homePath());
        }

        return view('auth.register', [
            'inviteToken' => (string) $request->query('invite', $request->query('invite_token', '')),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'invite_token' => ['nullable', 'string', 'max:64'],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'name.max' => 'El nombre es demasiado largo.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'password.required' => 'Escribe una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'invite_token.max' => 'La invitación no es válida.',
        ]);

        $invitation = null;

        if (! empty($data['invite_token'])) {
            $invitation = Invitation::where('token', $data['invite_token'])
                ->whereNull('accepted_at')
                ->first();

            if (! $invitation
                || strtolower($invitation->email) !== strtolower($data['email'])) {
                throw ValidationException::withMessages([
                    'invite_token' => 'Invitación no válida.',
                ]);
            }
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        CreativeMembership::create(['user_id' => $user->id]);
        AccountQuota::create([
            'user_id' => $user->id,
            'bytes_limit' => AccountQuota::DEFAULT_BYTES,
        ]);

        if ($invitation) {
            $invitation->forceFill(['accepted_at' => now()])->save();
        }

        $user->sendEmailVerificationNotification();

        Auth::login($user);

        return $request->expectsJson()
            ? response()->json(['status' => 'registered', 'email_verified' => false], 201)
            : redirect('/account/two-factor');
    }
}
