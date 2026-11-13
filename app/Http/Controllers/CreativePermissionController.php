<?php

namespace App\Http\Controllers;

use App\Models\CreativePermission;
use App\Models\EditLease;
use App\Services\CreativeAccess;
use Illuminate\Http\Request;

class CreativePermissionController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'resource_type' => ['required', 'in:'.implode(',', CreativePermission::RESOURCE_TYPES)],
            'resource_id' => ['required', 'integer'],
            'scope' => ['required', 'in:'.implode(',', CreativePermission::SCOPES)],
        ]);

        $this->authorizeOwner($request->user()->id, $data['resource_type'], (int) $data['resource_id']);

        $permission = CreativePermission::firstOrCreate([
            'user_id' => $data['user_id'],
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'scope' => $data['scope'],
        ], ['granted_by' => $request->user()->id]);

        return response()->json(['permission' => $permission], 201);
    }

    public function destroy(Request $request, CreativePermission $permission)
    {
        $this->authorizeOwner(
            $request->user()->id,
            $permission->resource_type,
            (int) $permission->resource_id,
        );

        EditLease::where('user_id', $permission->user_id)
            ->where('resource_type', $permission->resource_type)
            ->where('resource_id', $permission->resource_id)
            ->delete();

        $permission->delete();

        return response()->json(['revoked' => true]);
    }

    private function authorizeOwner(int $userId, string $type, int $resourceId): void
    {
        $owner = CreativeAccess::ownerId($type, $resourceId);

        abort_if($owner === null, 404);
        abort_unless($owner === $userId, 404);
    }
}
