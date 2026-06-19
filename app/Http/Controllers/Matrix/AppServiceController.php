<?php

namespace App\Http\Controllers\Matrix;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Phase K-3 — the inbound AS-API (Synapse → the CGA appservice). Synapse pushes room events here as
 * transactions and queries whether namespaced users/aliases exist. Authenticated by the hs_token
 * (VerifyMatrixAppService). Transactions are idempotent on txnId. In K3-D this acks + dedupes;
 * event REACTIONS (detecting a "file testimony" affordance, etc.) are layered in later slices.
 */
class AppServiceController extends Controller
{
    /** PUT /_matrix/app/v1/transactions/{txnId} — accept + dedupe so Synapse stops retrying. */
    public function transactions(Request $request, string $txnId): JsonResponse
    {
        $key = 'matrix:as:txn:'.$txnId;

        if (! Cache::has($key)) {
            // Event handling (testimony filing, etc.) arrives in K3-H+. Dedupe-and-ack for now.
            Cache::put($key, true, now()->addDay());
        }

        return response()->json([]);
    }

    /** GET /_matrix/app/v1/users/{userId} — does this namespaced user exist? (@u-* + the sender are ours.) */
    public function users(string $userId): JsonResponse
    {
        $localpart = Str::before(Str::after($userId, '@'), ':');

        if (Str::startsWith($localpart, 'u-') || $localpart === config('matrix.appservice.sender_localpart')) {
            return response()->json([]);
        }

        return response()->json(['errcode' => 'M_NOT_FOUND'], 404);
    }

    /** GET /_matrix/app/v1/rooms/{alias} — aliases are created explicitly by the reconciler, none on demand. */
    public function rooms(string $alias): JsonResponse
    {
        return response()->json(['errcode' => 'M_NOT_FOUND'], 404);
    }
}
