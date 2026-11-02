<?php

namespace App\Http\Controllers\Messenger;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Services\Messenger\MessengerService;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * JSON for the floating messenger and call dock (signed-in session only). The state document keeps
 * what is open and where the person stopped reading, so a closed window or another device returns
 * to the same place. Messages themselves stay in Matrix; this stores no message text.
 */
class MessengerController extends Controller
{
    private const KEY = '/^(private|commons):[0-9a-f-]{36}$/i';

    public function __construct(private readonly MessengerService $messenger, private readonly RoleService $roles) {}

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->load($request->user()->getKey()));
    }

    public function saveState(Request $request): JsonResponse
    {
        $d = $request->validate([
            'open' => 'array|max:8', 'open.*' => ['string', 'regex:'.self::KEY],
            'active' => ['nullable', 'string', 'regex:'.self::KEY],
            'following' => 'array|max:10', 'following.*' => ['string', 'regex:'.self::KEY],
            'reads' => 'array|max:60', 'reads.*' => 'integer|min:0',
            'call' => 'nullable|array', 'call.descriptor' => 'nullable|array', 'call.beat' => 'nullable|integer|min:0',
            'call.descriptor.mode' => 'nullable|in:commons,private,url', 'call.descriptor.room' => 'nullable|string|max:255',
            'call.descriptor.jurisdictionId' => 'nullable|string|max:64', 'call.descriptor.pseudonym' => 'nullable|string|max:255',
            'call.descriptor.subjectUserId' => 'nullable|string|max:64', 'call.descriptor.title' => 'nullable|string|max:200',
            'call.descriptor.href' => 'nullable|string|max:500', 'call.descriptor.tokenUrl' => 'nullable|string|max:500',
            'call.descriptor.variant' => 'nullable|string|max:20',
            'call.descriptor.chatKey' => ['nullable', 'string', 'regex:'.self::KEY],
            'call.descriptor.mic' => 'nullable|boolean', 'call.descriptor.cam' => 'nullable|boolean', 'call.descriptor.screen' => 'nullable|boolean',
        ]);
        $uid = (string) $request->user()->getKey();
        DB::transaction(function () use ($uid, $d) {
            DB::table('dock_states')->where('user_id', $uid)->lockForUpdate()->first();
            $cur = $this->load($uid);
            $state = $cur['state'];
            foreach (['open', 'active', 'following', 'call'] as $field) {
                if (array_key_exists($field, $d)) {
                    $state[$field] = $d[$field];
                }
            }
            // A read mark only ever moves forward, so a stale tab cannot un-read a message.
            foreach ($d['reads'] ?? [] as $key => $ms) {
                if (preg_match(self::KEY, (string) $key)) {
                    $state['reads'][$key] = max((int) ($state['reads'][$key] ?? 0), (int) $ms);
                }
            }
            $state['reads'] = array_slice($state['reads'] ?? [], -60, null, true);
            $state['baseline_ms'] = $cur['baseline_ms'];
            DB::table('dock_states')->updateOrInsert(['user_id' => $uid], ['state' => json_encode($state), 'updated_at' => now()]);
        });
        Cache::forget("messenger.list.$uid");

        return response()->json($this->load($uid));
    }

    public function conversations(Request $request): JsonResponse
    {
        $doc = $this->load($request->user()->getKey());
        $s = $doc['state'];

        return response()->json($this->messenger->list($request->user(), $s['following'] ?? [], $s['reads'] ?? [], $doc['baseline_ms']));
    }

    public function messages(Request $request, string $key): JsonResponse
    {
        $c = $this->messenger->resolve($request->user(), $key);
        abort_unless($c, 404);

        return response()->json(['conversation' => ['key' => $key, 'title' => $c['title'], 'kind' => $c['kind'], 'href' => $c['href']]]
            + $this->messenger->thread($request->user(), $c));
    }

    public function send(Request $request, string $key): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:20000']])['body'];
        $c = $this->messenger->resolve($request->user(), $key);
        abort_unless($c, 404);
        try {
            $this->messenger->send($request->user(), $c, $body);
        } catch (ConstitutionalViolation $e) {
            abort(403, $e->getMessage());
        } catch (\Throwable $e) {
            abort(503, 'Posting could not be confirmed. Your draft has been kept; try again.');
        }

        return response()->json(['sent' => true], 201);
    }

    /** Public squares the person can follow: the places they live in that have a live square. */
    public function publicOptions(Request $request): JsonResponse
    {
        $out = [];
        foreach (array_slice($this->roles->associationsFor($request->user()), 0, 25) as $a) {
            $c = $this->messenger->resolve($request->user(), 'commons:'.$a['id']);
            if ($c !== null) {
                $out[] = ['key' => 'commons:'.$a['id'], 'title' => $c['title']];
            }
        }

        return response()->json(['data' => $out]);
    }

    private function load(string $uid): array
    {
        $row = DB::table('dock_states')->where('user_id', $uid)->first();
        $state = $row ? (json_decode($row->state, true) ?: []) : [];
        $state += ['open' => [], 'active' => null, 'following' => [], 'reads' => [], 'call' => null, 'baseline_ms' => null];
        if (! $state['baseline_ms']) {
            $state['baseline_ms'] = MessengerService::nowMs();
            DB::table('dock_states')->updateOrInsert(['user_id' => $uid], ['state' => json_encode($state), 'updated_at' => now()]);
        }
        $baseline = (int) $state['baseline_ms'];
        unset($state['baseline_ms']);

        return ['state' => $state, 'baseline_ms' => $baseline];
    }
}
