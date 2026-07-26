<?php

namespace App\Services;

use App\Domain\Achievements\AchievementCatalog as Catalog;
use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * K-2 — the single writer of the earned achievement ledger.
 *
 * THE API SHAPE IS THE POINT. There is no general `award($user, $key)`, and
 * that is deliberate: a single method taking "a user" is exactly how the
 * ACH-CAN-005 defect happened. That entry keyed to F-ELB-002 — the form a
 * BOARD MEMBER files to validate a candidacy — so awarding to "the user who
 * did it" decorated the validator instead of the candidate.
 *
 * So the earner mode is part of the call, and a mismatch is refused:
 *
 *   awardSelf($filer, $key)      the filer earns          (catalogue: self)
 *   awardSubject($subject, $key) someone ELSE earns       (catalogue: subject)
 *   awardState($holder, $key)    a fact table names them  (catalogue: state)
 *
 * Call the wrong one and you get an InvalidArgumentException, not a wrong
 * medal. The catalogue's `earner` field and the call site have to agree, which
 * makes the defect class unrepresentable rather than merely discouraged.
 *
 * ONE WRITER. JourneyService delegates its arc-completion award here rather
 * than inserting its own row — an append-only ledger with two writers is two
 * places to get idempotency and sealing right.
 *
 * RAILS THIS SERVICE IS BOUND BY (see K2_ACHIEVEMENT_LIBRARY.md):
 * - CI-1: awarding NEVER grants a capability. This class imports no role, no
 *   clock and no power service, and nothing here returns anything a caller
 *   could gate on beyond "did a row get written".
 * - PI-2: electoral participation is read from `ballot_envelopes`, NEVER from
 *   `ballots`. This file must never reference the ballots table, its payload,
 *   its salt or its hash — pinned by AchievementEnvelopeOnlyTest.
 * - PI-6: no score. Nothing here counts, sums, ranks or percentages a person's
 *   achievements. `earnedBy()` returns a list, never a total.
 * - The ledger is append-only at the DB level (achievements_immutable), so an
 *   award is final. Awarding twice is a no-op, never an update.
 */
class AchievementService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /** The filer earns their own act. Refuses catalogue entries that say otherwise. */
    public function awardSelf(User $filer, string $awardKey): bool
    {
        return $this->awardAs($filer, $awardKey, Catalog::EARNER_SELF);
    }

    /**
     * A DIFFERENT person earns than the one who filed — the candidate whose
     * candidacy a board member validated, the member whose bill the chamber
     * enacted, the resident whose petition crossed its threshold.
     */
    public function awardSubject(User $subject, string $awardKey): bool
    {
        return $this->awardAs($subject, $awardKey, Catalog::EARNER_SUBJECT);
    }

    /** Nobody "filed" it — a seating, membership or confirmation row names the holder. */
    public function awardState(User $holder, string $awardKey): bool
    {
        return $this->awardAs($holder, $awardKey, Catalog::EARNER_STATE);
    }

    /**
     * Already on the ledger? Cheap, and safe to call before doing work — the
     * award path is idempotent anyway, this just avoids the transaction.
     */
    public function hasEarned(User $user, string $awardKey): bool
    {
        return Achievement::query()
            ->where('user_id', (string) $user->id)
            ->where('award_key', $awardKey)
            ->exists();
    }

    /**
     * What this person has earned, newest first.
     *
     * A LIST, NEVER A TALLY (PI-6). No count, no percentage, no rank, no
     * "N of M". Callers that want to show progress must show WHICH, not HOW
     * MANY — a completion figure is a per-person composite score wearing a
     * different hat, and the absence of one is the rail.
     *
     * @return list<array{award_key: string, title: string, earned_at: ?string}>
     */
    public function earnedBy(User $user): array
    {
        return Achievement::query()
            ->where('user_id', (string) $user->id)
            ->orderByDesc('earned_at')
            ->get(['award_key', 'title', 'earned_at'])
            ->map(fn (Achievement $a) => [
                'award_key' => $a->award_key,
                'title'     => $a->title,
                'earned_at' => $a->earned_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The one write path.
     *
     * @param  string  $expectedEarner  the mode the CALLER believes applies —
     *                                  must match the catalogue, or we refuse
     * @return bool  true if a row was written, false if it was already there
     */
    private function awardAs(User $earner, string $awardKey, string $expectedEarner): bool
    {
        // Unknown keys throw here rather than writing an unclassifiable row
        // into an append-only table that can never be corrected.
        $award = Catalog::get($awardKey);

        if ($award['earner'] !== $expectedEarner) {
            throw new InvalidArgumentException(
                "Achievement [{$awardKey}] is earned by '{$award['earner']}', but was awarded as "
                ."'{$expectedEarner}'. The actor on a form is not always the earner — check the "
                .'catalogue before changing this call.'
            );
        }

        if ($this->hasEarned($earner, $awardKey)) {
            return false;
        }

        return DB::transaction(function () use ($earner, $awardKey, $award): bool {
            // Seal FIRST, then write the row carrying the seal's seq: the
            // public_records posture — no row without its chain entry.
            $entry = $this->audit->append(
                module: 'journeys',
                event: 'achievement/earned',
                payload: ['award_key' => $awardKey, 'title' => $award['title_key']],
                ref: null,
                actorId: (string) $earner->id,
            );

            // insertOrIgnore rides the partial-unique (user_id, award_key):
            // a concurrent duplicate inserts nothing rather than aborting the
            // transaction. The append-only trigger forbids any upsert, so this
            // can only ever create.
            $written = DB::table('achievements')->insertOrIgnore([
                'user_id'    => (string) $earner->id,
                'award_key'  => $awardKey,
                // The i18n KEY, never English words. This table is write-once
                // and it federates, so a title stored today can never be
                // rewritten — an English string here would be English forever,
                // in every language.
                'title'      => $award['title_key'],
                'audit_seq'  => $entry->seq,
                'earned_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $written > 0;
        });
    }
}
