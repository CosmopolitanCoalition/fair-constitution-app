<?php

namespace App\Services\Elections;

use App\Domain\Ballots\BallotBox;
use App\Models\ElectionRace;
use Illuminate\Support\Carbon;

/**
 * The ranked-ballot "if the window closed now" FIRST-PREFERENCE projection
 * (W4 ⑥; spec docs/plans/scaling/RANKEDBALLOT_LIVEAGGREGATE_SPEC.md).
 *
 * It is NOT a full STV run — first preferences + Droop only. The card copy
 * (RankedBallot.vue) promises "surpluses and eliminations transfer at the
 * close"; running the real count here would show transfers the copy says are
 * NOT shown, and would mislead voters mid-window.
 *
 * SECRECY (Art. II): computeForRace() is the ONLY new caller of
 * BallotBox::decryptForCount() — a legitimate OUT-OF-BAND caller, invoked from
 * the daily RankedStandingsRollupJob, NEVER a controller or anything on an HTTP
 * request stack (BallotBox docblock; BallotSecrecyTest). Only aggregate counts
 * and candidacy ids ever leave the decrypt — no voter linkage. tally() is pure
 * and DB-free so it is pinned without a schema.
 */
class RankedProjectionService
{
    public function __construct(private readonly BallotBox $ballots) {}

    /**
     * Decrypt a race's cast ballots OUT OF BAND and project first preferences.
     * Returns null when the race has no valid ballots yet (the card renders
     * nothing). The shape is candidacy-id keyed — names are resolved at READ
     * time (StvRoundPresenter::candidateRefs), never stored (they would stale).
     *
     * @return array{as_of: string, valid: int, quota: int, first_prefs: array<string, int>}|null
     */
    public function computeForRace(ElectionRace $race): ?array
    {
        $tally = self::tally($this->ballots->decryptForCount($race), (int) $race->seats);

        if ($tally['valid'] < 1) {
            return null;
        }

        return ['as_of' => Carbon::now()->toDateString()] + $tally;
    }

    /**
     * PURE projection — first continuing preference per ballot, Droop quota on
     * the valid total. Each ballot is an ordered list of candidacy ids (most
     * preferred first, canonicalized by BallotBox); an empty ballot has no
     * first preference and leaves the denominator (engine totalValid semantics).
     *
     * @param  iterable<int, list<string>>  $ballots
     * @return array{valid: int, quota: int, first_prefs: array<string, int>}
     */
    public static function tally(iterable $ballots, int $seats): array
    {
        $seats = max(1, $seats);
        $firstPrefs = [];
        $valid = 0;

        foreach ($ballots as $ranking) {
            $first = $ranking[0] ?? null;

            if ($first === null || $first === '') {
                continue; // no first preference — invalid, excluded from the denominator
            }

            $firstPrefs[$first] = ($firstPrefs[$first] ?? 0) + 1;
            $valid++;
        }

        arsort($firstPrefs); // descending by count; the reader maps ids → names in this order

        // Droop — the same quota the engine would apply if the window closed now.
        $quota = $valid > 0 ? intdiv($valid, $seats + 1) + 1 : 0;

        return ['valid' => $valid, 'quota' => $quota, 'first_prefs' => $firstPrefs];
    }
}
