<?php

namespace App\Services\Demo\Stages;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\Election;
use App\Services\ElectionLifecycleService;
use Illuminate\Support\Facades\DB;

/**
 * The SEATING stage: certify the count and seat the winners.
 *
 * THIS IS WHERE THE BATCHING STOPS, DELIBERATELY.
 *
 * Everything upstream of here is mechanically derived — a cohort, a roster, a
 * candidacy, a tabulation — and is written in bulk with one summary audit entry
 * per batch. Certifying an election and seating a member are not that. They are
 * governance-constitutive: the act by which a government comes to exist. The
 * pinned engine keeps them per-act everywhere else in the codebase, and the
 * operator's D3 ruling drew the line in the same place. So this stage files
 * **one F-ELB-004 per election, through the real ConstitutionalEngine**, and
 * takes the serial audit cost on the chin.
 *
 * The whole seating block downstream — winners into `legislature_members`,
 * terms opened, the chamber moved from forming to active, CLK-10 armed, the
 * next election queued — is `CertificationService`, untouched. Nothing here
 * re-implements any of it.
 *
 * THE ACTOR IS NULL ON PURPOSE. `BoardProvenance::resolveMemberOnBoard()`
 * resolves a null actor to the bootstrap board's synthetic member row (the one
 * with `user_id IS NULL` that `ActivationService` seats when a jurisdiction
 * activates). That is the system-filing path the engine already provides — not
 * a bypass, and not a fake person invented to hold a pen.
 */
final class SeatingStage
{
    private function __construct() {}

    /**
     * @return array{certified: bool, seated: int, skipped: ?string}
     */
    public static function run(string $electionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $election = Election::query()->find($electionId);

        if ($election === null) {
            return ['certified' => false, 'seated' => 0, 'skipped' => 'election not found'];
        }

        if ($election->status === Election::STATUS_CERTIFIED) {
            // Idempotent: a re-handed item must not re-certify.
            return ['certified' => false, 'seated' => 0, 'skipped' => 'already certified'];
        }

        $races = DB::table('election_races')->where('election_id', $election->id)->count();

        if ($races === 0) {
            return ['certified' => false, 'seated' => 0, 'skipped' => 'no races — chamber cannot seat'];
        }

        // Every race must carry a complete count. `certifiedTabulation()` throws
        // otherwise, and refusing here gives a readable reason instead of an
        // exception trace.
        $uncounted = DB::table('election_races as r')
            ->where('r.election_id', $election->id)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tabulations as t')
                    ->whereColumn('t.race_id', 'r.id')
                    ->where('t.status', 'complete')
                    ->whereNotNull('t.record_hash');
            })
            ->count();

        if ($uncounted > 0) {
            return [
                'certified' => false,
                'seated' => 0,
                'skipped' => "{$uncounted} of {$races} races not yet counted",
            ];
        }

        $lifecycle = app(ElectionLifecycleService::class);

        // Walk the real phase machine to the certifiable state. F-ELB-004
        // refuses anything that is not `tabulating` or `audit_rerun`, and that
        // guard is the point — the demo does not get to skip the lifecycle.
        $election = self::advanceToTabulating($election, $lifecycle);

        if ($election->status !== Election::STATUS_TABULATING) {
            return [
                'certified' => false,
                'seated' => 0,
                'skipped' => "election is {$election->status}, not certifiable",
            ];
        }

        // ONE per-act filing, through the real engine, with its own chain entry.
        app(ConstitutionalEngine::class)->file('F-ELB-004', null, [
            'election_id' => (string) $election->id,
        ]);

        $seated = (int) DB::table('legislature_members')
            ->where('election_id', $election->id)
            ->whereIn('status', ['elected', 'seated'])
            ->whereNull('deleted_at')
            ->count();

        return ['certified' => true, 'seated' => $seated, 'skipped' => null];
    }

    /**
     * Move an election forward to `tabulating`, one lawful transition at a time.
     *
     * The demo skips no phase — it just does not wait out the clocks between
     * them. Each call is the same method the corresponding clock job invokes.
     */
    private static function advanceToTabulating(Election $election, ElectionLifecycleService $lifecycle): Election
    {
        $guard = 0;

        while ($election->status !== Election::STATUS_TABULATING && $guard++ < 8) {
            $beat && $beat();
            $election = match ($election->status) {
                Election::STATUS_SCHEDULED => $lifecycle->openApproval($election),
                Election::STATUS_APPROVAL_OPEN => $lifecycle->applyFinalistCutoff($election),
                Election::STATUS_FINALIST_CUTOFF => $lifecycle->openRanked($election),
                Election::STATUS_RANKED_OPEN => $lifecycle->closeRanked($election),
                Election::STATUS_VOTING_CLOSED => $lifecycle->markTabulating($election),
                default => $election,
            };

            if ($guard > 1 && $election->wasChanged() === false && $election->status !== Election::STATUS_TABULATING) {
                break; // a status the walk cannot advance — report it honestly
            }
        }

        return $election->refresh();
    }
}
