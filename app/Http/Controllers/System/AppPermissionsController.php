<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Notifications\AppNotificationService;
use App\Services\Notifications\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AppPermissionsController extends Controller
{
    public function show(Request $request, WebPushService $push)
    {
        $user = $request->user();
        $places = $user ? DB::table('residency_confirmations as r')->join('jurisdictions as j', 'j.id', '=', 'r.jurisdiction_id')
            ->where('r.user_id', $user->id)->where('r.is_active', true)->whereNull('j.deleted_at')
            ->orderBy('r.depth')->get(['j.id', 'j.name'])->unique('id')->values() : [];

        return Inertia::render('System/AppPermissions', [
            'signedIn' => $user !== null,
            'pushKey' => $user ? $push->keys()['publicKey'] : null,
            'places' => $places,
            'devices' => $user ? DB::table('web_push_subscriptions')->where('user_id', $user->id)
                ->get(['id', 'endpoint_hash', 'invitations', 'messages', 'clocks', 'clock_jurisdiction_id', 'remind_minutes']) : [],
        ]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', fn ($a, $v, $fail) => WebPushService::validEndpoint($v) ?: $fail('Unsupported push provider.')],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{87}=?$/D'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}(==)?$/D'],
        ]);
        // Validate an actual uncompressed P-256 public key, not merely a base64-shaped string.
        $key = base64_decode(strtr($data['keys']['p256dh'], '-_', '+/'), true);
        abort_unless($key !== false && strlen($key) === 65 && $key[0] === "\x04", 422);
        $hash = hash('sha256', $data['endpoint']);

        return DB::transaction(function () use ($request, $data, $hash) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('web_push_subscriptions')->where('endpoint_hash', $hash)->first();
            abort_if($existing && $existing->user_id !== $request->user()->id, 409, 'This browser subscription belongs to another account. Disable it and enable again.');
            abort_if(! $existing && DB::table('web_push_subscriptions')->where('user_id', $request->user()->id)->count() >= 10, 422, 'Remove an old device before adding another.');
            $id = $existing?->id ?? (string) Str::uuid();
            DB::table('web_push_subscriptions')->updateOrInsert(['id' => $id], [
                'user_id' => $request->user()->id, 'endpoint_hash' => $hash,
                'subscription' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
                'session_hash' => hash('sha256', $request->session()->getId()),
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);

            return response()->json(['id' => $id]);
        });
    }

    public function preferences(Request $request, string $id)
    {
        $data = $request->validate([
            'invitations' => ['required', 'boolean'], 'messages' => ['required', 'boolean'], 'clocks' => ['required', 'boolean'],
            'clock_jurisdiction_id' => ['nullable', 'uuid'], 'remind_minutes' => ['required', Rule::in([60, 1440])],
        ]);
        if ($data['clocks']) {
            abort_unless(DB::table('residency_confirmations')->where('user_id', $request->user()->id)->where('is_active', true)
                ->where('jurisdiction_id', $data['clock_jurisdiction_id'] ?? null)->exists(), 422, 'Choose one of your confirmed places.');
        } else {
            $data['clock_jurisdiction_id'] = null;
        }
        abort_unless(DB::table('web_push_subscriptions')->where('id', $id)->where('user_id', $request->user()->id)
            ->update($data + ['updated_at' => now()]), 404);

        return response()->json(['saved' => true]);
    }

    public function remove(Request $request, string $id)
    {
        DB::table('web_push_subscriptions')->where('id', $id)->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    public function test(Request $request, string $id, AppNotificationService $notifications)
    {
        $device = DB::table('web_push_subscriptions')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($device, 404);
        $notifications->enqueue($device, 'test', 'test:'.Str::uuid(), []);

        return response()->json(['queued' => true]);
    }
}
