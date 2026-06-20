<?php

namespace App\Http\Controllers\Matrix;

use App\Http\Controllers\Controller;
use App\Models\MatrixRoom;
use App\Services\Matrix\Translation\TranslationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase K-3 (K3-K) — the server-side translation endpoint. It exists so the PRIVACY RAIL is enforced
 * server-side regardless of the client: the request is routed through TranslationGate, which refuses to
 * send a PRIVATE room's content to a CLOUD provider (and fails closed for an unknown room). Public-room
 * messages are server-translatable. No state is stored (zero-migration slice) — it is a pure transform.
 */
class MatrixTranslationController extends Controller
{
    public function __invoke(Request $request, TranslationGate $gate): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'string', 'max:255'],
            'text'    => ['required', 'string', 'max:8000'],
            'target'  => ['nullable', 'string', 'max:16'],
        ]);

        $room = MatrixRoom::query()->where('matrix_room_id', $data['room_id'])->first();
        $target = $data['target'] ?? (string) config('matrix.translation.default_target', 'en');

        $result = $gate->translate($room, $data['text'], $target);

        if (! $result->admitted) {
            // 422 — the rail refused (a private room may not be cloud-translated). No source text echoed.
            return response()->json(['admitted' => false, 'reason' => $result->reason], 422);
        }

        return response()->json([
            'admitted'   => true,
            'translated' => $result->translated,
            'provider'   => $result->provider,
        ]);
    }
}
