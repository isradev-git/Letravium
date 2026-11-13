<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\EditLease;
use App\Models\User;
use App\Services\CreativeAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EditLeaseController extends Controller
{
    private const LEASE_SECONDS = 120;

    public function acquire(Request $request, string $type, int $id)
    {
        $this->assertType($type);
        $this->requireEdit($request->user(), $type, $id);

        $user = $request->user();

        $lease = DB::transaction(function () use ($type, $id, $user) {
            if ($type === 'chapter') {
                Chapter::whereKey($id)->lockForUpdate()->firstOrFail();
            } else {
                Card::whereKey($id)->lockForUpdate()->firstOrFail();
            }

            $existing = EditLease::where('resource_type', $type)
                ->where('resource_id', $id)
                ->lockForUpdate()
                ->first();

            if ($existing
                && (int) $existing->user_id !== (int) $user->id
                && $existing->expires_at?->isFuture()) {
                return null;
            }

            try {
                return EditLease::updateOrCreate(
                    ['resource_type' => $type, 'resource_id' => $id],
                    ['user_id' => $user->id, 'expires_at' => now()->addSeconds(self::LEASE_SECONDS)],
                );
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });

        if ($lease === null) {
            $holder = EditLease::where('resource_type', $type)->where('resource_id', $id)->first();

            return response()->json([
                'message' => 'Reservado por otro editor.',
                'holder_id' => $holder?->user_id,
                'expires_at' => $holder?->expires_at,
            ], 409);
        }

        return response()->json([
            'lease' => $lease,
            'renew_in' => 30,
        ]);
    }

    public function show(Request $request, string $type, int $id)
    {
        $this->assertType($type);
        $this->requireRead($request->user(), $type, $id);

        $lease = EditLease::where('resource_type', $type)->where('resource_id', $id)->first();

        return response()->json(['lease' => $lease]);
    }

    public function release(Request $request, string $type, int $id)
    {
        $this->assertType($type);
        $lease = $this->findLease($type, $id);
        $this->authorizeHolderOrOwner($request->user(), $type, $id, $lease);

        $lease->delete();

        return response()->json(['released' => true]);
    }

    public function expire(Request $request, string $type, int $id)
    {
        $this->assertType($type);
        $lease = $this->findLease($type, $id);
        $this->authorizeHolderOrOwner($request->user(), $type, $id, $lease);

        $lease->update(['expires_at' => now()]);

        return response()->json(['lease' => $lease->refresh(), 'expired' => true]);
    }

    public function updateChapter(Request $request, Chapter $chapter)
    {
        $user = $request->user();
        $this->requireEdit($user, 'chapter', $chapter->id);

        $data = $request->validate([
            'base_revision' => ['required', 'integer'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'plan' => ['sometimes', 'nullable', 'array'],
        ]);

        DB::transaction(function () use ($user, $chapter, $data) {
            $lease = $this->lockLease('chapter', $chapter->id);
            $this->assertLeaseHolder($lease, $user);

            $fresh = Chapter::whereKey($chapter->id)->lockForUpdate()->firstOrFail();

            abort_if((int) $fresh->revision !== (int) $data['base_revision'], 409);

            $updates = ['revision' => (int) $fresh->revision + 1];

            foreach (['title', 'plan'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            $fresh->forceFill($updates)->save();

            $lease->update(['expires_at' => now()->addSeconds(self::LEASE_SECONDS)]);
        });

        return response()->json(['chapter' => $chapter->fresh()]);
    }

    public function updateCard(Request $request, Card $card)
    {
        $user = $request->user();
        $this->requireEdit($user, 'card', $card->id);

        $data = $request->validate([
            'base_revision' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:200'],
            'fields' => ['sometimes', 'nullable', 'array'],
            'text' => ['sometimes', 'nullable', 'array'],
            'template' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        DB::transaction(function () use ($user, $card, $data) {
            $lease = $this->lockLease('card', $card->id);
            $this->assertLeaseHolder($lease, $user);

            $fresh = Card::whereKey($card->id)->lockForUpdate()->firstOrFail();

            abort_if((int) $fresh->revision !== (int) $data['base_revision'], 409);

            $updates = ['revision' => (int) $fresh->revision + 1];

            foreach (['name', 'fields', 'text', 'template'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            $fresh->forceFill($updates)->save();

            $lease->update(['expires_at' => now()->addSeconds(self::LEASE_SECONDS)]);
        });

        return response()->json(['card' => $card->fresh()]);
    }

    private function assertType(string $type): void
    {
        abort_unless(in_array($type, ['chapter', 'card'], true), 404);
    }

    private function requireEdit(User $user, string $type, int $id): void
    {
        if (CreativeAccess::allows($user, $type, $id, 'edit')) {
            return;
        }

        abort_if(CreativeAccess::allows($user, $type, $id, 'read'), 403);

        abort(404);
    }

    private function requireRead(User $user, string $type, int $id): void
    {
        abort_unless(CreativeAccess::allows($user, $type, $id, 'read'), 404);
    }

    private function findLease(string $type, int $id): EditLease
    {
        return EditLease::where('resource_type', $type)->where('resource_id', $id)->firstOrFail();
    }

    private function authorizeHolderOrOwner(User $user, string $type, int $id, EditLease $lease): void
    {
        $owner = CreativeAccess::ownerId($type, $id);

        if ($owner !== null && $owner === (int) $user->id) {
            return;
        }

        abort_unless((int) $lease->user_id === (int) $user->id, 403);
    }

    private function lockLease(string $type, int $id): ?EditLease
    {
        return EditLease::where('resource_type', $type)
            ->where('resource_id', $id)
            ->lockForUpdate()
            ->first();
    }

    private function assertLeaseHolder(?EditLease $lease, User $user): void
    {
        abort_unless(
            $lease && (int) $lease->user_id === (int) $user->id && $lease->expires_at?->isFuture(),
            409,
        );
    }
}
