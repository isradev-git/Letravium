<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceProject;
use App\Models\Novel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncDeviceController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'projects' => ['required', 'array', 'min:1'],
        ]);

        $projects = collect($data['projects'])->map(function ($item) {
            if (is_numeric($item)) {
                return ['novel_id' => (int) $item, 'manifest' => null];
            }

            if (is_array($item) && isset($item['novel_id']) && is_numeric($item['novel_id'])) {
                return [
                    'novel_id' => (int) $item['novel_id'],
                    'manifest' => $item['manifest'] ?? null,
                ];
            }

            return null;
        });

        if ($projects->contains(null)) {
            throw ValidationException::withMessages(['projects' => 'Proyecto no válido.']);
        }

        $ids = $projects->pluck('novel_id')->unique()->values();
        $novels = Novel::where('owner_id', $request->user()->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($novels->count() !== $ids->count()) {
            throw ValidationException::withMessages(['projects' => 'Proyecto no autorizado.']);
        }

        $device = DB::transaction(function () use ($request, $data, $projects, $novels) {
            $device = Device::create([
                'user_id' => $request->user()->id,
                'name' => $data['name'],
                'authorized_at' => now(),
            ]);

            foreach ($projects as $project) {
                DeviceProject::create([
                    'device_id' => $device->id,
                    'novel_id' => $project['novel_id'],
                    'manifest' => $project['manifest'] ?? $this->manifest($novels[$project['novel_id']]),
                ]);
            }

            return $device;
        });

        return response()->json(['device' => $this->payload($device)], 201);
    }

    public function show(Request $request, Device $device)
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        if ($device->isRevoked()) {
            return response()->json(['status' => 'device_revoked'], 403);
        }

        return response()->json(['device' => $this->payload($device)]);
    }

    public function destroy(Request $request, Device $device)
    {
        abort_unless($device->user_id === $request->user()->id, 404);

        if (! $device->isRevoked()) {
            $device->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json(['status' => 'revoked', 'device' => $this->payload($device->refresh())]);
    }

    private function payload(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'authorized_at' => $device->authorized_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'projects' => $device->projects()->get()->map(fn (DeviceProject $project) => [
                'novel_id' => $project->novel_id,
                'manifest' => $project->manifest,
            ])->all(),
        ];
    }

    private function manifest(Novel $novel): array
    {
        $novel->load(['chapters.alternatives.scenes']);

        return [
            'novel_id' => $novel->id,
            'title' => $novel->title,
            'revision' => $novel->revision,
            'updated_at' => $novel->updated_at?->toIso8601String(),
            'chapters' => $novel->chapters->map(fn ($chapter) => [
                'chapter_id' => $chapter->id,
                'revision' => $chapter->revision,
                'position' => $chapter->position,
                'alternatives' => $chapter->alternatives->map(fn ($alternative) => [
                    'alternative_id' => $alternative->id,
                    'revision' => $alternative->revision,
                    'is_active' => $alternative->is_active,
                    'scenes' => $alternative->scenes->map(fn ($scene) => [
                        'scene_id' => $scene->id,
                        'revision' => $scene->revision,
                    ])->all(),
                ])->all(),
            ])->all(),
        ];
    }
}
