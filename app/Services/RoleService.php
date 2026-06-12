<?php

namespace App\Services;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WI-5 — real role derivation (replaces WI-2's StubRoleResolver via the
 * ConstitutionProvider binding). WI-B4 adds the Phase B election roles.
 *
 * Roles are DERIVED, never stored (Art. I): each code is a pure function
 * of authoritative facts, recomputed on demand. No grants table exists to
 * drift out of sync with the facts.
 *
 *   R-01 Individual    — authenticated account exists
 *   R-02 Verified      — any residency claim in status 'active'
 *   R-03 Associated    — any active residency confirmation (association row)
 *   R-04 Voter         — IDENTICAL to R-03 (Art. I — see derive())
 *   R-06 Candidate     — a STANDING candidacy (registered..finalist) in an
 *                        election that is still open (not final/cancelled)
 *   R-07 Endorsed      — R-06 + at least one active ORGANIZATION
 *                        endorsement on a standing candidacy
 *   R-08 Board member  — a SEATED election_board_members row on an ACTIVE
 *                        board; or the operator while an active BOOTSTRAP
 *                        board exists (the system-as-board posture — the
 *                        operator drives the synthetic board in dev)
 *   R-09 Legislator    — a current legislature_members row
 *                        (status elected|seated)
 *   R-23 Org agent     — organizations.agent_user_id points at the user on
 *                        an active org (the minimal Phase B substrate
 *                        gating F-ORG-002; full org module is Phase D)
 *
 * Guests carry no roles here; the Inertia layer maps "no user" to the
 * mockups' R-00 visitor code for display. The shared-prop exposure
 * (HandleInertiaRequests → auth.roles) flows through rolesFor(), so the
 * sidebar gates pick the new codes up automatically.
 *
 * Request-cached: bound as a container singleton, so within one request
 * (or one queued job) the fact queries run at most once per user. Writers
 * that change the facts (ResidencyService; the Phase B candidacy/
 * endorsement handlers) call flushUser() so a long-running worker never
 * serves a stale derivation.
 */
class RoleService implements ResolvesRoles
{
    /** ESM-06 statuses that count as a standing candidacy (R-06 source). */
    public const STANDING_CANDIDACY_STATUSES = ['registered', 'validated', 'in_pool', 'finalist'];

    /** ESM-03 statuses in which a candidacy's election is no longer open. */
    public const CLOSED_ELECTION_STATUSES = ['final', 'cancelled'];

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
            $this->hasStandingCandidacy($id),
            $this->hasOrgEndorsedCandidacy($id),
            $this->hasActiveBoardSeat($user),
            $this->hasCurrentLegislatureSeat($id),
            $this->hasOrgAgency($id),
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
     * Phase B facts (WI-B4): R-06 derives from the candidacy row alone —
     * the right to STAND was exercised at registration; a later
     * association change is the board's matter (F-ELB-002), never a silent
     * role revocation. R-07 requires R-06 (an endorsement of a dead
     * candidacy derives nothing). R-08/R-09 derive independently from the
     * seat rows.
     *
     * @return list<string>
     */
    public static function derive(
        bool $authenticated,
        bool $hasActiveClaim,
        bool $hasActiveAssociation,
        bool $hasStandingCandidacy = false,
        bool $hasOrgEndorsedCandidacy = false,
        bool $hasBoardSeat = false,
        bool $hasLegislatureSeat = false,
        bool $hasOrgAgency = false,
    ): array {
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

        if ($hasStandingCandidacy) {
            $roles[] = 'R-06';

            if ($hasOrgEndorsedCandidacy) {
                $roles[] = 'R-07'; // R-07 ⇒ R-06 by construction
            }
        }

        if ($hasBoardSeat) {
            $roles[] = 'R-08';
        }

        if ($hasLegislatureSeat) {
            $roles[] = 'R-09';
        }

        if ($hasOrgAgency) {
            $roles[] = 'R-23';
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

    /** R-06: a standing candidacy in an election that is still open. */
    private function hasStandingCandidacy(string $userId): bool
    {
        return DB::table('candidacies as c')
            ->join('elections as e', 'e.id', '=', 'c.election_id')
            ->where('c.user_id', $userId)
            ->whereIn('c.status', self::STANDING_CANDIDACY_STATUSES)
            ->whereNull('c.deleted_at')
            ->whereNotIn('e.status', self::CLOSED_ELECTION_STATUSES)
            ->whereNull('e.deleted_at')
            ->exists();
    }

    /** R-07: a standing candidacy holding an active org endorsement. */
    private function hasOrgEndorsedCandidacy(string $userId): bool
    {
        return DB::table('candidacies as c')
            ->join('endorsements as en', 'en.candidate_id', '=', 'c.id')
            ->where('c.user_id', $userId)
            ->whereIn('c.status', self::STANDING_CANDIDACY_STATUSES)
            ->whereNull('c.deleted_at')
            ->where('en.endorser_type', 'organization')
            ->where('en.is_active', true)
            ->whereNull('en.withdrawn_at')
            ->exists();
    }

    /**
     * R-08: a seated row on an active board — or the operator while an
     * active BOOTSTRAP board exists (its synthetic member row has user_id
     * NULL, so the operator is the human hand that drives it; design §C).
     */
    private function hasActiveBoardSeat(User $user): bool
    {
        $seated = DB::table('election_board_members as m')
            ->join('election_boards as b', 'b.id', '=', 'm.election_board_id')
            ->where('m.user_id', (string) $user->getKey())
            ->where('m.status', 'seated')
            ->whereNull('m.deleted_at')
            ->where('b.status', 'active')
            ->whereNull('b.deleted_at')
            ->exists();

        if ($seated) {
            return true;
        }

        return (bool) $user->is_operator && DB::table('election_boards')
            ->where('is_bootstrap', true)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
    }

    /** R-09: a current legislature seat (status elected|seated). */
    private function hasCurrentLegislatureSeat(string $userId): bool
    {
        return DB::table('legislature_members')
            ->where('user_id', $userId)
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->exists();
    }

    /** R-23: agent of an active organization (minimal Phase B substrate). */
    private function hasOrgAgency(string $userId): bool
    {
        return DB::table('organizations')
            ->where('agent_user_id', $userId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }
}
