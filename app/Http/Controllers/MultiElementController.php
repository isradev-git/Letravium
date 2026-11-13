<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Chapter;
use App\Models\EditLease;
use App\Services\CreativeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MultiElementController extends Controller
{
    private const LEASE_SECONDS = 120;

    public function store(Request $request)
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.resource_type' => ['required', 'in:chapter,card'],
            'operations.*.resource_id' => ['required', 'integer'],
            'operations.*.base_revision' => ['required', 'integer'],
            'operations.*.title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'operations.*.plan' => ['sometimes', 'nullable', 'array'],
            'operations.*.name' => ['sometimes', 'string', 'max:200'],
            'operations.*.fields' => ['sometimes', 'nullable', 'array'],
            'operations.*.text' => ['sometimes', 'nullable', 'array'],
            'operations.*.template' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $operations = $data['operations'];

        foreach ($operations as $operation) {
            $type = $operation['resource_type'];
            $id = (int) $operation['resource_id'];

            if (CreativeAccess::allows($user, $type, $id, 'edit')) {
                continue;
            }

            abort_if(CreativeAccess::allows($user, $type, $id, 'read'), 403);

            abort(404);
        }

        usort($operations, fn (array $a, array $b) => [
            $a['resource_type'], (int) $a['resource_id'],
        ] <=> [
            $b['resource_type'], (int) $b['resource_id'],
        ]);

        $updated = DB::transaction(function () use ($operations, $user) {
            $locked = [];

            foreach ($operations as $operation) {
                $type = $operation['resource_type'];
                $id = (int) $operation['resource_id'];

                $model = $type === 'chapter'
                    ? Chapter::whereKey($id)->lockForUpdate()->first()
                    : Card::whereKey($id)->lockForUpdate()->first();

                abort_if($model === null, 404);
                abort_if((int) $model->revision !== (int) $operation['base_revision'], 409);

                $lease = EditLease::where('resource_type', $type)
                    ->where('resource_id', $id)
                    ->lockForUpdate()
                    ->first();

                abort_if(
                    $lease
                        && (int) $lease->user_id !== (int) $user->id
                        && $lease->expires_at?->isFuture(),
                    409,
                );

                $locked[] = [$operation, $model];
            }

            $result = [];

            foreach ($locked as [$operation, $model]) {
                $type = $operation['resource_type'];
                $id = (int) $operation['resource_id'];

                $updates = ['revision' => (int) $model->revision + 1];

                foreach ($this->editableFields($type) as $field) {
                    if (array_key_exists($field, $operation)) {
                        $updates[$field] = $operation[$field];
                    }
                }

                $model->forceFill($updates)->save();

                EditLease::updateOrCreate(
                    ['resource_type' => $type, 'resource_id' => $id],
                    ['user_id' => $user->id, 'expires_at' => now()->addSeconds(self::LEASE_SECONDS)],
                );

                $result[] = [
                    'resource_type' => $type,
                    'resource_id' => $id,
                    'revision' => (int) $model->revision,
                ];
            }

            return $result;
        });

        return response()->json(['updated' => $updated]);
    }

    /**
     * @return array<int, string>
     */
    private function editableFields(string $type): array
    {
        return $type === 'chapter'
            ? ['title', 'plan']
            : ['name', 'fields', 'text', 'template'];
    }
}
