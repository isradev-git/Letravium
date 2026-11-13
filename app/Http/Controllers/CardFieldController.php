<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Universe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardFieldController extends Controller
{
    public const TYPES = ['text', 'long_text', 'number', 'date', 'boolean', 'select', 'multiselect', 'url'];

    public function index(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        $fields = DB::table('card_field_definitions')
            ->where('universe_id', $universe->id)
            ->whereNull('removed_at')
            ->orderBy('label')
            ->get()
            ->map(fn ($field) => $this->fieldPayload($field))
            ->all();

        if ($request->expectsJson()) {
            return response()->json(['fields' => $fields]);
        }

        return view('workspace.card-fields', [
            'universe' => $universe,
            'fields' => $fields,
        ]);
    }

    public function store(Request $request, Universe $universe)
    {
        $this->ownUniverse($request, $universe);

        if ($this->wantsHtml($request)) {
            $request->merge(['options' => $this->optionsFromText($request)]);
        }

        $data = $this->validateField($request);

        $duplicate = DB::table('card_field_definitions')
            ->where('universe_id', $universe->id)
            ->where('key', $data['key'])
            ->whereNull('removed_at')
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['key' => 'Ya existe un campo con esa clave.']);
        }

        $id = DB::table('card_field_definitions')->insertGetId([
            'universe_id' => $universe->id,
            'key' => $data['key'],
            'type' => $data['type'],
            'label' => $data['label'],
            'options' => json_encode($data['options'] ?? []),
            'temporal' => (bool) ($data['temporal'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$universe->id.'/card-fields')
                ->with('status', 'Campo creado.');
        }

        return response()->json([
            'field' => $this->fieldPayload(DB::table('card_field_definitions')->where('id', $id)->first()),
        ], 201);
    }

    public function update(Request $request, int $fieldId): JsonResponse
    {
        $field = $this->findField($request, $fieldId);
        $data = $this->validateField($request, partial: true);

        $nextKey = $data['key'] ?? $field->key;
        $nextType = $data['type'] ?? $field->type;
        $values = $this->valuesForKey((int) $field->universe_id, $field->key);
        $unsafe = $nextKey !== $field->key || $nextType !== $field->type;

        if ($values !== [] && $unsafe && ! $request->boolean('confirm')) {
            return response()->json([
                'warning' => 'Cambiar la clave o el tipo afecta a fichas con datos. Confirma para continuar.',
                'cards_affected' => count($values),
            ], 409);
        }

        if ($nextKey !== $field->key) {
            $duplicate = DB::table('card_field_definitions')
                ->where('universe_id', $field->universe_id)
                ->where('key', $nextKey)
                ->whereNull('removed_at')
                ->where('id', '!=', $field->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['key' => 'Ya existe un campo con esa clave.']);
            }
        }

        DB::table('card_field_definitions')->where('id', $field->id)->update([
            'key' => $nextKey,
            'type' => $nextType,
            'label' => $data['label'] ?? $field->label,
            'options' => array_key_exists('options', $data)
                ? json_encode($data['options'] ?? [])
                : $field->options,
            'temporal' => array_key_exists('temporal', $data) ? (bool) $data['temporal'] : (bool) $field->temporal,
            'updated_at' => now(),
        ]);

        return response()->json([
            'field' => $this->fieldPayload(DB::table('card_field_definitions')->where('id', $field->id)->first()),
        ]);
    }

    public function destroy(Request $request, int $fieldId)
    {
        $field = $this->findField($request, $fieldId);
        $values = $this->valuesForKey((int) $field->universe_id, $field->key);

        if ($values !== [] && ! $request->boolean('confirm')) {
            if ($this->wantsHtml($request)) {
                return redirect('/workspace/universes/'.$field->universe_id.'/card-fields')
                    ->withErrors(['field' => 'El campo contiene datos. Confirma que se conservarán los valores y el historial.']);
            }

            return response()->json([
                'warning' => 'El campo contiene datos. Se conservarán los valores y el historial.',
                'cards_affected' => count($values),
            ], 409);
        }

        DB::table('card_field_definitions')->where('id', $field->id)->update([
            'removed_at' => now(),
            'removed_values' => json_encode($values),
            'updated_at' => now(),
        ]);

        if ($this->wantsHtml($request)) {
            return redirect('/workspace/universes/'.$field->universe_id.'/card-fields')
                ->with('status', 'Campo eliminado; sus valores quedan conservados.');
        }

        return response()->json([
            'field' => $this->fieldPayload(DB::table('card_field_definitions')->where('id', $field->id)->first()),
            'removed' => true,
        ]);
    }

    private function wantsHtml(Request $request): bool
    {
        return ! $request->expectsJson() || $request->query('format') === 'html';
    }

    private function optionsFromText(Request $request): array
    {
        $options = array_map('trim', explode(',', (string) $request->input('options_text', '')));

        return array_values(array_filter($options, fn (string $option): bool => $option !== ''));
    }

    private function validateField(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'key' => [$presence, 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
            'type' => [$presence, 'in:'.implode(',', self::TYPES)],
            'label' => [$presence, 'string', 'max:200'],
            'options' => ['nullable', 'array'],
            'temporal' => ['sometimes', 'boolean'],
        ]);
    }

    private function findField(Request $request, int $fieldId): object
    {
        $field = DB::table('card_field_definitions')->where('id', $fieldId)->first();
        abort_unless($field, 404);

        $universe = Universe::findOrFail($field->universe_id);
        $this->ownUniverse($request, $universe);

        return $field;
    }

    private function valuesForKey(int $universeId, string $key): array
    {
        $values = [];

        foreach (Card::where('universe_id', $universeId)->get() as $card) {
            if (is_array($card->fields) && array_key_exists($key, $card->fields)) {
                $values[$card->id] = $card->fields[$key];
            }
        }

        return $values;
    }

    private function fieldPayload(object $field): array
    {
        return [
            'id' => $field->id,
            'universe_id' => $field->universe_id,
            'key' => $field->key,
            'type' => $field->type,
            'label' => $field->label,
            'options' => json_decode($field->options ?? '[]', true) ?? [],
            'temporal' => (bool) $field->temporal,
            'removed_at' => $field->removed_at,
            'removed_values' => $field->removed_values ? json_decode($field->removed_values, true) : null,
        ];
    }

    private function ownUniverse(Request $request, Universe $universe): void
    {
        abort_unless($universe->owner_id === $request->user()->id, 404);
    }
}
