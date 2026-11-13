<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    public function __construct(private Google2FA $google2fa) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('AppEscritor', $user->email, $secret);
    }

    public function consumeFactor(User $user, string $code, bool $allowRecovery = true): bool
    {
        return DB::transaction(function () use ($user, $code, $allowRecovery) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $this->consumeAgainst($locked, $locked->totp_secret, $code, $allowRecovery);
        });
    }

    public function confirm(User $user, string $code): ?array
    {
        $plainCodes = DB::transaction(function () use ($user, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $replacement = $locked->totp_confirmed_at !== null && $locked->totp_pending_secret !== null;
            $secret = $replacement ? $locked->totp_pending_secret : $locked->totp_secret;

            if (! $secret || ($locked->totp_confirmed_at !== null && ! $replacement)) {
                return null;
            }

            if (! $this->consumeAgainst($locked, $secret, $code, false, $replacement ? 0 : null)) {
                return null;
            }

            $plainCodes = $this->generateRecoveryCodes();
            $attributes = [
                'totp_confirmed_at' => now(),
                'recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plainCodes),
            ];

            if ($replacement) {
                $attributes['totp_secret'] = $secret;
                $attributes['totp_pending_secret'] = null;
                $attributes['totp_pending_started_at'] = null;
            }

            $locked->forceFill($attributes)->save();

            return $plainCodes;
        });

        $user->refresh();

        return $plainCodes;
    }

    private function consumeAgainst(User $locked, ?string $secret, string $code, bool $allowRecovery, ?int $baseline = null): bool
    {
        if ($secret && preg_match('/^\d{6,8}$/', $code) === 1) {
            $timestamp = $this->google2fa->verifyKeyNewer(
                $secret,
                $code,
                $baseline ?? ($locked->last_totp_timestep ?? 0),
                1,
            );

            if ($timestamp !== false) {
                $locked->forceFill(['last_totp_timestep' => $timestamp])->save();

                return true;
            }
        }

        if (! $allowRecovery) {
            return false;
        }

        $codes = $locked->recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$index]);
                $locked->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => strtoupper(bin2hex(random_bytes(5))),
            range(1, 8),
        );
    }

    public function replaceRecoveryCodes(User $user): array
    {
        $plainCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plainCodes),
        ])->save();

        return $plainCodes;
    }
}
