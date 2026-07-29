<?php

namespace App\Services\Dev;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ResidencyClaim;
use App\Models\User;
use App\Services\ResidencyService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * D4 — "assume a resident/role of a place", the find/relocate half.
 * (Design note: docs/plans/playtest/DEMO_MODE_D4_D5_NOTES.md.)
 *
 * Pick a place and a role; this finds the person. The BECOME half stays
 * with the caller (the HTTP door logs the session in; the CLI door prints
 * who to become) — a service must never touch a session.
 *
 * ══ THE TWO RULES ══
 *
 *   IT NEVER CREATES USERS. Creation belongs to the seeders, under
 *   GuardsSyntheticData. A walkthrough that silently mints people is a
 *   walkthrough of nothing.
 *
 *   IT NEVER SEATS ANYONE. Seated roles (board, legislator, speaker,
 *   judge, advocate) can only be FOUND — a seat exists because an
 *   election or appointment put someone in it, and this service will
 *   not counterfeit that. "Nobody holds that role there" is an ANSWER:
 *   it tells the playtester which journey to walk first.
 *
 * The one write it may perform: DEV RELOCATION, for residency-derived
 * roles only, when the place has no resident to offer — the same
 * declare → simulated pings → verify pipeline the dev residency grant
 * runs, through the REAL engine, every step on the audit chain. It picks
 * the least-entangled `@demo.invalid` account (no seat, no candidacy, no
 * board, no bench, no bar), because moving a person out from under their
 * own journey would sabotage someone else's walkthrough.
 */
class AssumeService
{
    /** Roles this composition can answer for, and how. */
    public const FINDABLE_ROLES = ['R-03', 'R-04', 'R-06', 'R-08', 'R-09', 'R-10', 'R-19', 'R-20', 'R-21'];

    /** The residency-derived subset — the only roles relocation can mint. */
    public const RELOCATABLE_ROLES = ['R-03', 'R-04'];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
        private readonly RoleService $roles,
    ) {}

    /**
     * Resolve a jurisdiction by uuid or slug. Returns null when nothing
     * matches — the caller owns the honest 404.
     */
    public function resolvePlace(string $idOrSlug): ?object
    {
        return DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($idOrSlug) {
                if (\Illuminate\Support\Str::isUuid($idOrSlug)) {
                    $q->where('id', $idOrSlug);
                } else {
                    $q->where('slug', $idOrSlug);
                }
            })
            ->first(['id', 'name', 'slug', 'adm_level']);
    }

    /**
     * Find a user holding $role in $jurisdictionId — or, for the
     * residency-derived roles only, relocate one there.
     *
     * @return array{user: User, how: 'found'|'relocated'}
     *
     * @throws RuntimeException with a plain, honest sentence when nobody
     *                          fits and nobody may be made to fit.
     */
    public function findOrRelocate(string $jurisdictionId, string $role): array
    {
        if (! in_array($role, self::FINDABLE_ROLES, true)) {
            throw new RuntimeException(
                "Role {$role} is not assumable here. Assumable: ".implode(', ', self::FINDABLE_ROLES).'.'
            );
        }

        $userId = $this->finderQuery($jurisdictionId, $role)?->user_id;

        if ($userId !== null) {
            return ['user' => User::query()->findOrFail($userId), 'how' => 'found'];
        }

        if (! in_array($role, self::RELOCATABLE_ROLES, true)) {
            throw new RuntimeException(
                "Nobody holds {$role} in that place, and a seat cannot be manufactured — "
                .'an election, appointment or registration puts people in seats, never this control. '
                .'Walk that journey first (or run the matching demo seeder), then assume.'
            );
        }

        $candidate = $this->leastEntangledSynthetic();

        if ($candidate === null) {
            throw new RuntimeException(
                'No unentangled synthetic account exists to relocate — this control never CREATES '
                .'users. Seed some (elections:demo mints impersonatable voters) and try again.'
            );
        }

        $this->relocate($candidate, $jurisdictionId);

        return ['user' => $candidate, 'how' => 'relocated'];
    }

    /**
     * One finder per role, each a jurisdiction-scoped pivot of the SAME
     * fact query RoleService derives that role from — the finder and the
     * derivation can only drift if someone edits one and not the other,
     * which the behavioural test pins against.
     */
    private function finderQuery(string $jurisdictionId, string $role): ?object
    {
        $first = fn ($q) => $q->orderBy('u.email')->first(['u.id as user_id']);

        return match ($role) {
            // Resident / Voter — R-04 ⇔ R-03 (Art. I), so one finder serves both.
            'R-03', 'R-04' => $first(DB::table('residency_confirmations as rc')
                ->join('users as u', 'u.id', '=', 'rc.user_id')
                ->where('rc.jurisdiction_id', $jurisdictionId)
                ->where('rc.is_active', true)),

            // Standing candidacy in a still-open election of this place.
            'R-06' => $first(DB::table('candidacies as c')
                ->join('elections as e', 'e.id', '=', 'c.election_id')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->where('e.jurisdiction_id', $jurisdictionId)
                ->whereIn('c.status', RoleService::STANDING_CANDIDACY_STATUSES)
                ->whereNull('c.deleted_at')
                ->whereNotIn('e.status', RoleService::CLOSED_ELECTION_STATUSES)
                ->whereNull('e.deleted_at')),

            // Seated member of this place's ACTIVE election board.
            'R-08' => $first(DB::table('election_board_members as m')
                ->join('election_boards as b', 'b.id', '=', 'm.election_board_id')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('b.jurisdiction_id', $jurisdictionId)
                ->where('b.status', 'active')
                ->whereNull('b.deleted_at')
                ->where('m.status', 'seated')
                ->whereNull('m.deleted_at')),

            // Current member of this place's legislature.
            'R-09' => $first(DB::table('legislature_members as m')
                ->join('legislatures as l', 'l.id', '=', 'm.legislature_id')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('l.jurisdiction_id', $jurisdictionId)
                ->whereNull('l.deleted_at')
                ->whereIn('m.status', ['elected', 'seated'])
                ->whereNull('m.deleted_at')
                ->whereNotNull('m.user_id')),

            // The speaker — the legislature pointer, never is_speaker.
            'R-10' => $first(DB::table('legislatures as l')
                ->join('legislature_members as m', 'm.id', '=', 'l.speaker_id')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('l.jurisdiction_id', $jurisdictionId)
                ->whereNull('l.deleted_at')
                ->whereIn('m.status', ['elected', 'seated'])
                ->whereNull('m.deleted_at')),

            // A seated judge on this place's court of the matching type.
            'R-19', 'R-20' => $first(DB::table('judicial_seats as s')
                ->join('judiciaries as j', 'j.id', '=', 's.judiciary_id')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->where('j.jurisdiction_id', $jurisdictionId)
                ->where('j.type', $role === 'R-19' ? 'appointed' : 'elected')
                ->where('j.status', '!=', 'dissolved')
                ->whereNull('j.deleted_at')
                ->where('s.status', 'seated')
                ->whereNull('s.deleted_at')),

            // A registered advocate at this place's bar.
            'R-21' => $first(DB::table('advocates as a')
                ->join('judiciaries as j', 'j.id', '=', 'a.judiciary_id')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('j.jurisdiction_id', $jurisdictionId)
                ->where('a.status', 'registered')
                ->whereNull('a.deleted_at')
                ->where('j.status', '!=', 'dissolved')
                ->whereNull('j.deleted_at')),

            default => null,
        };
    }

    /**
     * The least-entangled synthetic account: `@demo.invalid` namespace (the
     * reserved synthetic namespace — never a real person, even on a dev
     * box), holding no seat, no standing candidacy, no board seat, no
     * bench, no bar. Deterministic pick so repeated calls behave the same.
     */
    private function leastEntangledSynthetic(): ?User
    {
        return User::query()
            ->where('email', 'like', '%@demo.invalid')
            ->whereNotExists(fn ($q) => $q->from('legislature_members as m')
                ->whereColumn('m.user_id', 'users.id')
                ->whereIn('m.status', ['elected', 'seated'])
                ->whereNull('m.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('candidacies as c')
                ->whereColumn('c.user_id', 'users.id')
                ->whereIn('c.status', RoleService::STANDING_CANDIDACY_STATUSES)
                ->whereNull('c.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('election_board_members as bm')
                ->whereColumn('bm.user_id', 'users.id')
                ->where('bm.status', 'seated')
                ->whereNull('bm.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('judicial_seats as js')
                ->whereColumn('js.user_id', 'users.id')
                ->where('js.status', 'seated')
                ->whereNull('js.deleted_at'))
            ->whereNotExists(fn ($q) => $q->from('advocates as a')
                ->whereColumn('a.user_id', 'users.id')
                ->where('a.status', 'registered')
                ->whereNull('a.deleted_at'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * The dev relocation, verbatim in shape from the dev residency grant
     * (ResidencyGrantController §2 — the WF-CIV-03 shortcut that exists
     * only on dev routes): deactivate the prior confirmations, supersede
     * the prior claim, then run the REAL pipeline at the new place —
     * F-IND-003 declare, one real F-IND-005 ping per threshold day,
     * F-IND-006 verify. Every step files through the engine and lands on
     * the audit chain.
     */
    private function relocate(User $user, string $jurisdictionId): void
    {
        $active = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->first();

        if ($active !== null && (string) $active->jurisdiction_id === $jurisdictionId) {
            return; // already a resident exactly there
        }

        if ($active !== null) {
            DB::transaction(function () use ($active, $user) {
                DB::table('residency_confirmations')
                    ->where('user_id', (string) $user->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);

                $active->forceFill([
                    'status' => ResidencyClaim::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ])->save();
            });

            $this->roles->flushUser((string) $user->id);
        }

        $this->engine->file('F-IND-003', $user, [
            'jurisdiction_id' => $jurisdictionId,
            'ping_consent' => true,
        ]);

        $claim = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ResidencyClaim::MONITORING_STATUSES)
            ->firstOrFail();

        $this->residency->simulatePings($user, $this->residency->thresholdDays($claim));

        $claim->refresh();
        $this->residency->verify($claim);
    }
}
