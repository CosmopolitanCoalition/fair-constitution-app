<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Phase I — REACH (enrolment), the canonical denominator.
 *
 *     reach = verified residents ÷ population estimate
 *
 * ⚑ NOT THE ART. VI §3 LEGITIMACY VERDICT. Art. VI §3 is a wartime allegiance
 * test bound to restoration (`App\Services\Jurisdictions\RestorationService`,
 * conditions countermanded | captured | destroyed, judicially confirmed).
 * This class computes a headcount ratio. Conflating them would open a road to
 * "this place has low reach, therefore its government is restorable", which is
 * exactly what the charter forbids. The class name is historical; the meaning
 * is enrolment.
 *
 * ── The rails (CI-1) ─────────────────────────────────────────────────────
 * A GAUGE, NEVER A LEVER. Reach changes no vote, no seat, no right, ever.
 * No per-person score. No leaderboard of people — only places are measured.
 * Nothing in this class may be consulted on a rights path.
 *
 * ── CI-6: only the AUTHORITATIVE instance writes ─────────────────────────
 * Authority is PER JURISDICTION (`jurisdictions.authoritative_server_id IS
 * NULL`) and has nothing to do with cluster leadership. A mirror runs its own
 * scheduler and wins its own leader probe, so a scheduler-leader gate alone
 * would have every mirror writing snapshots for places it does not own. The
 * filter below is the real CI-6 rail; the job's onOneServer + LeaderProbe is a
 * separate, orthogonal concern ("once per cluster, on a writable node").
 *
 * ── k-anonymity is NOT per-cell ──────────────────────────────────────────
 * The residency ancestor sweep writes one confirmation per enclosing
 * jurisdiction, so for a tree partition `verified(parent) = Σ verified(children)`
 * EXACTLY. Suppressing a small child while publishing its parent and siblings
 * therefore reveals that child by subtraction — arithmetic, not a residual
 * risk, and it fires across most of the tree. Cell suppression alone is
 * provably insufficient; see suppressComplementary().
 */
class LegitimacyService
{
    /** Below this many verified residents, counts are never published. */
    public const K_ANON_FLOOR = 5;

    /** Reach is reported in millionths. */
    public const MICRO = 1_000_000;

    public const STATE_UNMEASURABLE = 'unmeasurable';
    public const STATE_ACTIVATING   = 'activating';
    public const STATE_MEASURED     = 'measured';
    public const STATE_CAPPED       = 'capped';

    /** Rows pulled per level pass. */
    private const CHUNK = 5000;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * The reach of one place, as the UI must render it.
     *
     * Four honest states, in the order the mockup evaluates them. The order
     * matters: "no population estimate" is checked FIRST, so an unmeasured
     * place is never rendered as 0% — that would read as "nobody lives here"
     * when the truth is "we do not know how many do".
     *
     * @return array{state:string, verified:?int, population:?int, ratio_micro:?int}
     */
    public static function reachRatio(?int $verified, ?int $population, bool $suppressed = false): array
    {
        if ($population === null || $population < 1) {
            return ['state' => self::STATE_UNMEASURABLE, 'verified' => null, 'population' => null, 'ratio_micro' => null];
        }

        if ($suppressed || $verified === null || $verified < self::K_ANON_FLOOR) {
            // No number and no curve. Publishing either could point at a person.
            return ['state' => self::STATE_ACTIVATING, 'verified' => null, 'population' => $population, 'ratio_micro' => null];
        }

        $micro = (int) round(($verified / $population) * self::MICRO);

        // More verified residents than the estimate admits: the estimate lags
        // the place. Report it honestly rather than clamping silently — the
        // bar shows 100% and the raw figure is disclosed.
        if ($micro > self::MICRO) {
            return ['state' => self::STATE_CAPPED, 'verified' => $verified, 'population' => $population, 'ratio_micro' => $micro];
        }

        return ['state' => self::STATE_MEASURED, 'verified' => $verified, 'population' => $population, 'ratio_micro' => $micro];
    }

    /**
     * COMPLEMENTARY (secondary) SUPPRESSION.
     *
     * Given a parent's children and which of them are sensitive (a real but
     * sub-k count), decide whether the PARENT may publish its own count.
     *
     * A child is sensitive when its count is in [1, k). A child with zero
     * residents is not sensitive — "nobody lives here" points at nobody.
     *
     * The parent may publish only when there is nothing to recover:
     *   - no sensitive children at all; or
     *   - at least TWO sensitive children whose counts sum to >= k, so the
     *     residual cannot be pinned on any single place.
     * Exactly one sensitive child is the fatal case: parent − Σ(published
     * siblings) yields it precisely.
     *
     * @param  list<int>  $childCounts counts of every child that has a snapshot
     */
    public static function mayPublishParent(array $childCounts, int $k = self::K_ANON_FLOOR): bool
    {
        $sensitive = array_values(array_filter(
            $childCounts,
            static fn (int $c): bool => $c >= 1 && $c < $k,
        ));

        if ($sensitive === []) {
            return true;
        }

        return count($sensitive) >= 2 && array_sum($sensitive) >= $k;
    }

    /**
     * Write one day's snapshots for every jurisdiction THIS instance is
     * authoritative for.
     *
     * Walks bottom-up by adm_level so a child's suppression decision is always
     * settled before its parent is judged — complementary suppression only
     * works in that order.
     *
     * Scale note: this is proportional to PLAYERS, not to jurisdictions. The
     * numerator is a grouped read over residency_confirmations (users ×
     * ancestry depth), so a planet of 955,130 places with a thousand residents
     * costs a thousand-ish rows a night, not a million. Places with no
     * residents are unmeasurable and get no row at all.
     *
     * @return array{written:int, suppressed:int, unmeasurable:int}
     */
    public function snapshotAll(?string $asOfDate = null): array
    {
        $date = $asOfDate ?? now()->toDateString();

        $written = 0;
        $suppressed = 0;
        $unmeasurable = 0;

        // Deepest first. A parent is only judged once its children are known.
        $levels = DB::select('
            SELECT DISTINCT j.adm_level
              FROM residency_confirmations rc
              JOIN jurisdictions j ON j.id = rc.jurisdiction_id AND j.deleted_at IS NULL
             WHERE rc.is_active
             ORDER BY j.adm_level DESC
        ');

        foreach ($levels as $level) {
            $after = '00000000-0000-0000-0000-000000000000';

            while (true) {
                $rows = DB::select('
                    SELECT j.id,
                           j.parent_id,
                           j.population,
                           j.population_year,
                           j.population_assigned_via,
                           count(rc.id) AS verified
                      FROM jurisdictions j
                      JOIN residency_confirmations rc
                        ON rc.jurisdiction_id = j.id AND rc.is_active
                     WHERE j.deleted_at IS NULL
                       AND j.adm_level = ?
                       AND j.id > ?
                       AND j.authoritative_server_id IS NULL   -- CI-6: authority, not leadership
                     GROUP BY j.id, j.parent_id, j.population, j.population_year, j.population_assigned_via
                     ORDER BY j.id
                     LIMIT ?
                ', [$level->adm_level, $after, self::CHUNK]);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $after = $row->id;

                    $verified = (int) $row->verified;
                    $population = $row->population !== null ? (int) $row->population : null;

                    // Children's counts decide whether this place may publish
                    // its own. Read LIVE from residency rather than back out of
                    // the snapshot table: a suppressed child's magnitude must
                    // never be stored anywhere — persisting it "just for the
                    // parent's check" would put the sub-k count in the database
                    // and federate it, defeating the suppression it serves.
                    $childCounts = array_map(
                        static fn ($c): int => (int) $c->verified,
                        DB::select('
                            SELECT count(rc.id) AS verified
                              FROM jurisdictions c
                              JOIN residency_confirmations rc
                                ON rc.jurisdiction_id = c.id AND rc.is_active
                             WHERE c.parent_id = ? AND c.deleted_at IS NULL
                             GROUP BY c.id
                        ', [$row->id]),
                    );

                    $blockedByChildren = ! self::mayPublishParent($childCounts);

                    $reach = self::reachRatio($verified, $population, $blockedByChildren);

                    if ($reach['state'] === self::STATE_UNMEASURABLE) {
                        $unmeasurable++;
                    }
                    if ($reach['state'] === self::STATE_ACTIVATING) {
                        $suppressed++;
                    }

                    $isSuppressed = $reach['state'] === self::STATE_ACTIVATING;

                    DB::statement('
                        INSERT INTO legitimacy_snapshots
                            (jurisdiction_id, as_of_date, verified_residents, population_estimate,
                             ratio_micro, state, suppressed, population_provenance, population_year,
                             source_server_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
                        ON CONFLICT (jurisdiction_id, as_of_date) DO UPDATE
                           SET verified_residents = EXCLUDED.verified_residents,
                               population_estimate = EXCLUDED.population_estimate,
                               ratio_micro = EXCLUDED.ratio_micro,
                               state = EXCLUDED.state,
                               suppressed = EXCLUDED.suppressed
                    ', [
                        $row->id,
                        $date,
                        $reach['verified'],
                        $population,
                        $reach['ratio_micro'],
                        $reach['state'],
                        $isSuppressed,
                        $row->population_assigned_via,
                        $row->population_year,
                    ]);

                    $written++;
                }

                if (count($rows) < self::CHUNK) {
                    break;
                }
            }
        }

        $this->audit->append(
            module: 'jurisdictions',
            event: 'reach.snapshot_run',
            payload: [
                'as_of_date'   => $date,
                'written'      => $written,
                'suppressed'   => $suppressed,
                'unmeasurable' => $unmeasurable,
                'k_anon_floor' => self::K_ANON_FLOOR,
            ],
            ref: 'CI-6',
        );

        return ['written' => $written, 'suppressed' => $suppressed, 'unmeasurable' => $unmeasurable];
    }
}
