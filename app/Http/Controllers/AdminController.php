<?php

namespace App\Http\Controllers;

use App\Models\AccountQuota;
use App\Models\AdminAuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['area' => 'platform-admin']);
        }

        $recent = AdminAuditEvent::orderByDesc('id')->limit(5)->get();

        return view('admin.index', [
            'active' => 'index',
            'stats' => [
                'accounts' => User::count(),
                'writers' => User::whereHas('creativeMembership')->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
                'admins' => User::where('is_platform_admin', true)->count(),
            ],
            'recent' => $recent,
            'emails' => $this->emailsFor($recent),
        ]);
    }

    public function accounts(Request $request)
    {
        $accounts = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'is_platform_admin', 'marketing_consent_at', 'suspended_at'])
            ->orderBy('id')
            ->get();

        if ($request->expectsJson()) {
            return response()->json(['accounts' => $accounts]);
        }

        $quotas = AccountQuota::whereIn('user_id', $accounts->pluck('id'))->get()->keyBy('user_id');

        return view('admin.accounts', [
            'active' => 'accounts',
            'accounts' => $accounts,
            'quotas' => $quotas,
            'currentUserId' => $request->user()->id,
        ]);
    }

    public function updateQuota(Request $request, User $user)
    {
        $data = $request->validate(['bytes_limit' => ['required', 'integer', 'min:0']]);

        $quota = AccountQuota::updateOrCreate(
            ['user_id' => $user->id],
            ['bytes_limit' => $data['bytes_limit']],
        );

        $this->audit($request, 'account.quota', $user, ['bytes_limit' => $data['bytes_limit']]);

        if (! $request->expectsJson()) {
            return redirect('/admin/accounts');
        }

        return response()->json(['quota' => $quota]);
    }

    public function suspend(Request $request, User $user)
    {
        $user->forceFill(['suspended_at' => now()])->save();
        $this->audit($request, 'account.suspend', $user);

        if (! $request->expectsJson()) {
            return redirect('/admin/accounts');
        }

        return response()->json(['account' => $user->refresh()]);
    }

    public function restore(Request $request, User $user)
    {
        $user->forceFill(['suspended_at' => null])->save();
        $this->audit($request, 'account.restore', $user);

        if (! $request->expectsJson()) {
            return redirect('/admin/accounts');
        }

        return response()->json(['account' => $user->refresh()]);
    }

    public function auditTrail(Request $request)
    {
        $events = AdminAuditEvent::orderByDesc('id')->limit(200)->get();

        if ($request->expectsJson()) {
            return response()->json(['events' => $events]);
        }

        return view('admin.audit', [
            'active' => 'audit',
            'events' => $events,
            'emails' => $this->emailsFor($events),
        ]);
    }

    private function emailsFor($events): array
    {
        $ids = $events->pluck('actor_id')
            ->merge($events->pluck('subject_id'))
            ->filter()
            ->unique()
            ->all();

        return User::whereIn('id', $ids)->pluck('email', 'id')->all();
    }

    private function audit(Request $request, string $action, User $subject, array $metadata = []): void
    {
        AdminAuditEvent::create([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $subject->id,
            'metadata' => $metadata,
        ]);
    }
}
