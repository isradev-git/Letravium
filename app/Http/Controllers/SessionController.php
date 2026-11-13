<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        $current = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_activity' => $row->last_activity,
                'current' => $row->id === $current,
            ]);

        if ($request->expectsJson()) {
            return response()->json(['sessions' => $sessions]);
        }

        return view('account.sessions', ['sessions' => $sessions]);
    }

    public function destroy(Request $request, string $id)
    {
        $deleted = DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if ($deleted === 0) {
            abort(404);
        }

        if ($id === $request->session()->getId()) {
            $request->session()->invalidate();
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'revoked']);
        }

        return redirect('/account/sessions')->with('status', 'Sesión revocada.');
    }
}
