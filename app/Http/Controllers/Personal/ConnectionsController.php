<?php

namespace App\Http\Controllers\Personal;

use App\Http\Controllers\Controller;
use App\Support\ApiKeys;
use App\Support\SecretVault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The signed-in owner's connections page: API keys to give to outside automation, the vault of
 * external credentials, and the queue of mail drafts waiting for the owner's yes.
 * Everything is scoped to the signed-in user. Keys are shown once. A secret is revealed only
 * after the owner types their password again. Sending approval exists only here (session auth).
 */
class ConnectionsController extends Controller
{
    public function show(Request $request)
    {
        $id = $request->user()->id;

        return Inertia::render('Personal/Connections', [
            'scopes' => ApiKeys::SCOPES,
            'keys' => DB::table('api_keys')->where('user_id', $id)->orderByDesc('created_at')
                ->get(['id', 'name', 'key_prefix', 'scopes', 'last_used_at', 'expires_at', 'revoked_at', 'created_at'])
                ->map(fn ($k) => (array) $k + ['scopes' => json_decode($k->scopes, true)]),
            'secrets' => DB::table('user_secrets')->where('user_id', $id)->orderBy('name')->get(['id', 'name', 'provider', 'hint', 'updated_at']),
            'pending' => DB::table('mail_drafts')->where('user_id', $id)->where('status', 'pending')->orderBy('created_at')
                ->get(['id', 'account', 'to_addresses', 'subject', 'body', 'created_at']),
        ]);
    }

    public function issueKey(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|max:80', 'scopes' => 'required|array|min:1', 'scopes.*' => 'in:'.implode(',', ApiKeys::SCOPES),
            'days' => 'nullable|integer|min:1|max:730',
        ]);
        abort_if(DB::table('api_keys')->where('user_id', $request->user()->id)->whereNull('revoked_at')->count() >= 25, 422, 'Revoke an old key first.');
        $issued = ApiKeys::issue($request->user()->id, $d['name'], $d['scopes'], isset($d['days']) ? now()->addDays($d['days']) : null);

        // The key is returned here and nowhere else.
        return response()->json($issued, 201);
    }

    public function revokeKey(Request $request, string $id)
    {
        abort_unless(DB::table('api_keys')->where('id', $id)->where('user_id', $request->user()->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]), 404);

        return response()->noContent();
    }

    public function storeSecret(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:80', 'provider' => 'required|string|max:40', 'value' => 'required|string|max:20000']);
        $uid = $request->user()->id;
        abort_if(DB::table('user_secrets')->where('user_id', $uid)->count() >= 100 && ! DB::table('user_secrets')->where('user_id', $uid)->where('name', $d['name'])->exists(), 422, 'Too many stored secrets.');
        DB::table('user_secrets')->updateOrInsert(['user_id' => $uid, 'name' => $d['name']], [
            'provider' => $d['provider'], 'ciphertext' => SecretVault::seal($uid, $d['value']), 'hint' => SecretVault::hint($d['value']),
            'updated_at' => now(),
        ] + ['id' => (string) Str::uuid(), 'created_at' => now()]);

        return response()->noContent(201);
    }

    /** Shows the plain value once, after the owner types their password again. */
    public function revealSecret(Request $request, string $id)
    {
        $request->validate(['password' => 'required|string']);
        abort_unless(Hash::check($request->input('password'), $request->user()->password), 422, 'Password is not correct.');
        $row = DB::table('user_secrets')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        $plain = SecretVault::open($request->user()->id, $row->ciphertext);
        abort_if($plain === null, 500, 'This secret cannot be opened.');
        DB::table('user_secrets')->where('id', $row->id)->update(['last_revealed_at' => now()]);

        return response()->json(['value' => $plain]);
    }

    public function deleteSecret(Request $request, string $id)
    {
        DB::table('user_secrets')->where('id', $id)->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    public function approveDraft(Request $request, string $id)
    {
        abort_unless(DB::table('mail_drafts')->where('id', $id)->where('user_id', $request->user()->id)->where('status', 'pending')
            ->update(['status' => 'approved', 'approved_at' => now(), 'updated_at' => now()]), 409, 'That draft is not waiting for approval.');

        return response()->noContent();
    }

    public function rejectDraft(Request $request, string $id)
    {
        abort_unless(DB::table('mail_drafts')->where('id', $id)->where('user_id', $request->user()->id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'updated_at' => now()]), 409, 'That draft is not waiting for approval.');

        return response()->noContent();
    }
}
