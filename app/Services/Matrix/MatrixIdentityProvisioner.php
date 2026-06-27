<?php

namespace App\Services\Matrix;

use App\Models\MatrixIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5 / K-3 (K3-C.4) — provisions a user's pseudonymous Matrix identity on first login (the first time
 * the OIDC provider mints an id_token for them). The localpart is the SAME `u-<handle>` pseudonym a sent
 * message uses (MatrixPostingGateService::localpartFor — single source of truth). The row carries ONLY the
 * pseudonym + the full @localpart:domain — NEVER a name/email/residency (the schema forbids those columns;
 * de-anon is the M-1 judicial carve-out). Idempotent: one live identity per user (unique on user_id).
 */
class MatrixIdentityProvisioner
{
    public function __construct(private readonly MatrixPostingGateService $posting) {}

    public function ensureFor(User $user): MatrixIdentity
    {
        if (($existing = $this->find($user)) !== null) {
            return $existing;
        }

        // The derived localpart, then a deterministic per-user discriminator for the (astronomically rare,
        // post-128-bit-widening) case where the derived one is already taken by ANOTHER user. Each insert is
        // guarded by the unique(matrix_localpart) index; a clash is handled gracefully — NEVER a raw 500
        // that burns the just-consumed auth code and permanently denies the user their Matrix identity.
        $candidates = [
            $this->posting->deriveLocalpart($user),
            'u-'.substr(hash('sha256', 'cga-localpart-disc:'.$user->getKey()), 0, 32),
        ];

        foreach ($candidates as $localpart) {
            try {
                // Each attempt runs in its OWN savepoint (nested transaction): a unique violation aborts
                // only the savepoint, not a surrounding transaction — so the retry below can still query +
                // insert. (Postgres aborts the whole transaction on a constraint error otherwise.)
                return DB::transaction(fn () => MatrixIdentity::create([
                    'user_id' => (string) $user->getKey(),
                    'matrix_localpart' => $localpart,
                    'matrix_user_id' => '@'.$localpart.':'.config('matrix.server_name'),
                ]));
            } catch (QueryException $e) {
                // Two shapes of unique violation: (a) a concurrent first-login won the unique(user_id) race
                // for THIS user → return its row; (b) the localpart is held by ANOTHER user → try the next
                // (discriminated) candidate. Distinguish by whether a row for our user_id now exists.
                if (($row = $this->find($user)) !== null) {
                    return $row;
                }
                // else: localpart clash — fall through to the next candidate.
            }
        }

        // Both the derived AND the discriminated localpart are held by other users AND no row exists for us —
        // a double 128-bit collision, effectively impossible. Fail with a clear error, not an opaque DB throw.
        throw new RuntimeException('Could not provision a unique Matrix localpart for the user.');
    }

    private function find(User $user): ?MatrixIdentity
    {
        return MatrixIdentity::query()
            ->where('user_id', (string) $user->getKey())
            ->whereNull('deleted_at')
            ->first();
    }
}
