<?php

namespace App\Services;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WI-5 — real role derivation (replaces WI-2's StubRoleResolver via the
 * ConstitutionProvider binding).
 *
 * Roles are DERIVED, never stored (Art. I): each code is a pure function
 * of authoritative facts, recomputed on demand. No grants table exists to
 * drift out of sync with the facts.
 *
 *   R-01 Individual    — authenticated account exists
 *   R-02 Verified      — any residency claim in status 'active'
 *   R-03 Associated    — any active residency confirmation (association row)
 *   R-04 Voter         — IDENTICAL to R-03 (Art. I — see derive())
 *
 * Guests carry no roles here; the Inertia layer maps "no user" to the
 * mockups' R-00 visitor code for display.
 *
 * Request-cached: bound as a container singleton, so within one request
 * (or one queued job) the fact queries run at most once per user. Writers
 * that change the facts (ResidencyService) call flushUser() so a
 * long-running worker never serves a stale derivation.
 */
class RoleService implements ResolvesRoles
{
    /** @var array<string, list<string>> per-user derivation cache */
    private array $cache = [];

    public function rolesFor(?User $user): array
    {
        if ($user === null) {
            return []; // system actor — bypasses role gates at the engine
        }

        $id = (string) $user->getKey();

        return $this->cache[$id] ??= self::derive(
            true,
            $this->hasActiveClaim($id),
            $this->hasActiveAssociation($id),
        );
    }

    /**
     * The pure derivation function — kept static and DB-free so the
     * constitutional test suite can pin it exhaustively
     * (tests/Constitutional/RightsAutomaticTest.php).
     *
     * CONSTITUTIONAL PIN — Art. I: R-04 (Voter: voting AND candidacy) is
     * derived as R-04 ⇔ R-03 (Associated). Jurisdictional association is
     * the ONLY gate on voting and candidacy; no additional condition may
     * ever appear between the two. Any edit that lets R-03 and R-04
     * diverge is a constitutional violation and will fail the pinned test.
     *
     * @return list<string>
     */
    public static function derive(bool $authenticated, bool $hasActiveClaim, bool $hasActiveAssociation): array
    {
        if (! $authenticated) {
            return [];
        }

        $roles = ['R-01'];

        if ($hasActiveClaim) {
            $roles[] = 'R-02';
        }

        if ($hasActiveAssociation) {
            $roles[] = 'R-03';
            $roles[] = 'R-04'; // R-04 ⇔ R-03, Art. I — never add a condition here
        }

        return $roles;
    }

    /**
     * Association chips for shared props / the Civic pages: one entry per
     * active confirmation, root-first (Earth → … → declared boundary).
     *
     * @return list<array{id: string, name: string, slug: string|null, adm_level: int, depth: int|null, confirmed_at: string|null}>
     */
    public function associationsFor(User $user): array
    {
        return DB::table('residency_confirmations as rc')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $user->getKey())
            ->where('rc.is_active', true)
            ->whereNull('j.deleted_at')
            ->orderBy('j.adm_level')
            ->orderBy('j.name')
            ->get(['j.id', 'j.name', 'j.slug', 'j.adm_level', 'rc.depth', 'rc.confirmed_at'])
            ->map(fn ($row) => [
                'id'           => (string) $row->id,
                'name'         => $row->name,
                'slug'         => $row->slug,
                'adm_level'    => (int) $row->adm_level,
                'depth'        => $row->depth !== null ? (int) $row->depth : null,
                'confirmed_at' => $row->confirmed_at,
            ])
            ->all();
    }

    /** Drop the cached derivation for one user (called by fact writers). */
    public function flushUser(string $userId): void
    {
        unset($this->cache[$userId]);
    }

    /** Drop the whole cache (tests, long-lived workers between jobs). */
    public function flush(): void
    {
        $this->cache = [];
    }

    // -------------------------------------------------------------------------
    // Fact queries
    // -------------------------------------------------------------------------

    private function hasActiveClaim(string $userId): bool
    {
        return DB::table('residency_claims')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
    }

    private function hasActiveAssociation(string $userId): bool
    {
        return DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }
}
