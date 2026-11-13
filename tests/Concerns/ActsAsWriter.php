<?php

namespace Tests\Concerns;

use App\Models\CreativeMembership;
use App\Models\User;

trait ActsAsWriter
{
    protected function writer(array $force = []): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(array_merge(['totp_confirmed_at' => now()], $force))->save();
        CreativeMembership::create(['user_id' => $user->id]);

        return $user->refresh();
    }

    protected function admin(array $force = []): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(array_merge([
            'totp_confirmed_at' => now(),
            'is_platform_admin' => true,
        ], $force))->save();

        return $user->refresh();
    }

    protected function sessionKeys(): array
    {
        return [
            'totp_verified_at' => now()->timestamp,
            'last_activity_at' => now()->timestamp,
        ];
    }

    protected function asWriter(User $user)
    {
        return $this->actingAs($user)->withSession($this->sessionKeys());
    }

    protected function asAdmin(User $user)
    {
        return $this->actingAs($user)->withSession(array_merge(
            $this->sessionKeys(),
            ['reauth_at' => now()->timestamp],
        ));
    }
}
