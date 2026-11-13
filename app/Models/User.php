<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password'];

    protected $hidden = [
        'password', 'remember_token', 'totp_secret', 'totp_pending_secret', 'recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_pending_secret' => 'encrypted',
            'totp_pending_started_at' => 'datetime',
            'totp_confirmed_at' => 'datetime',
            'last_totp_timestep' => 'integer',
            'recovery_codes' => 'array',
            'is_platform_admin' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function creativeMembership()
    {
        return $this->hasOne(CreativeMembership::class);
    }

    public function hasCreativeAccess(): bool
    {
        return $this->creativeMembership()->exists();
    }

    public function canManageAccounts(): bool
    {
        return $this->is_platform_admin && ! in_array($this->admin_accounts, [0, '0', false], true);
    }

    public function canManageCommunications(): bool
    {
        return $this->is_platform_admin && ! in_array($this->admin_communications, [0, '0', false], true);
    }

    public function homePath(): string
    {
        if ($this->canManageAccounts()) {
            return '/admin';
        }

        if ($this->canManageCommunications()) {
            return '/admin/campaigns';
        }

        return '/workspace';
    }
}
