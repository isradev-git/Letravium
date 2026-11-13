<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\MarketingConsent;
use App\Models\Novel;
use App\Models\Universe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountClosureController extends Controller
{
    private function wantsHtml(Request $request): bool
    {
        return $request->query('format') === 'html';
    }

    public function show()
    {
        return view('account.close');
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $universeIds = Universe::where('owner_id', $user->id)->pluck('id');

        $response = response()->json([
            'owned' => [
                'universes' => Universe::where('owner_id', $user->id)
                    ->get(['id', 'name'])
                    ->all(),
                'novels' => Novel::where('owner_id', $user->id)
                    ->get(['id', 'universe_id', 'title'])
                    ->all(),
                'cards' => Card::whereIn('universe_id', $universeIds)
                    ->get(['id', 'universe_id', 'area', 'name'])
                    ->all(),
            ],
            'contributions' => DB::table('content_revisions')
                ->where('author_id', $user->id)
                ->get(['scene_id', 'revision', 'reason', 'created_at'])
                ->all(),
        ]);

        if ($request->boolean('download')) {
            $response->headers->set(
                'Content-Disposition',
                'attachment; filename="datos-cuenta-'.($user->username ?: $user->id).'.json"',
            );
        }

        return $response;
    }

    public function close(Request $request)
    {
        $user = $request->user();

        if ($this->wantsHtml($request)) {
            $data = $request->validate(['confirmation' => ['required', 'string', 'max:255']]);
            $identifiers = array_filter([$user->email, $user->username]);

            if (! in_array($data['confirmation'], $identifiers, true)) {
                return redirect('/account/close')
                    ->withErrors(['confirmation' => 'Escribe exactamente tu correo o tu usuario para confirmar.'])
                    ->withInput();
            }
        }

        $export = $request->boolean('export') ? $this->export($request)->getData(true) : null;

        DB::transaction(function () use ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('creative_memberships')->where('user_id', $user->id)->delete();
            DB::table('marketing_consents')
                ->where('user_id', $user->id)
                ->where('status', '!=', MarketingConsent::STATUS_WITHDRAWN)
                ->update([
                    'status' => MarketingConsent::STATUS_WITHDRAWN,
                    'withdrawn_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($this->wantsHtml($request)) {
            return redirect('/login')->with(
                'status',
                'Tu cuenta se cerró. Tus universos siguen almacenados según la política de conservación; las contribuciones a obras ajenas mantienen su atribución.',
            );
        }

        return response()->json([
            'closed' => true,
            'export_available' => true,
            'export' => $export,
        ]);
    }
}
