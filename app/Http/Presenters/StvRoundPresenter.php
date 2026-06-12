<?php

namespace App\Http\Presenters;

use App\Models\Candidacy;
use App\Models\RaceResult;
use App\Models\Tabulation;
use App\Models\TabulationRound;
use Illuminate\Support\Facades\DB;

/**
 * FE-B6 — the §C round-by-round contract (PHASE_B_DESIGN_frontend.md §C).
 *
 * Builds the production STV_DATA shape from `tabulations` +
 * `tabulation_rounds` + `race_results`. The mockup keys rounds by display
 * name; production keys by candidacy_id and carries `name` denormalized so
 * Electoral/StvRound never needs a lookup table.
 *
 * Conversions (storage → display):
 *  - microvotes (Micro::SCALE = 1e6) → JSON numbers with ≤ 3 decimal
 *    places (Gregory fractions); StvBar rounds for display and carries the
 *    exact value in the title; the CSV endpoint streams FULL precision.
 *  - action strings are server-rendered in the EXACT mockup grammar:
 *      elimination → "{name} eliminated — {votes} votes transfer at current value"
 *      surplus     → "{name} elected — surplus {votes} transfers at value {0.011} (Gregory)"
 *      shortcut    → "{name} elected — fills a remaining seat (no quota transfer)"
 *
 * Key-round collapse (the §C contract, generalizing the mockup's
 * hand-picked tallies): a round carries `tallies` iff n ≤ 3 (opening
 * field), action = elect (every election round), or n = rounds (final).
 * Everything else is a "mid round" — heading + transfer only; `tallies` is
 * OMITTED (not null), and `electedSoFar` is present iff tallies is.
 *
 * Countback reuse (§C): countbackBars() emits the final-state bar list of
 * a kind='countback' tabulation — same presenter, `display` omitted, with
 * the struck candidacy carried as a removed bar (votes null).
 */
class StvRoundPresenter
{
    /** Bar-axis convention: quota × 1.35 (mockup SCALE), server-fixed. */
    public const SCALE_FACTOR = 1.35;

    /** Microvotes per whole vote (mirrors App\Domain\Counting\Micro::SCALE). */
    private const MICRO = 1_000_000;

    /**
     * The full §C payload for one complete tabulation.
     */
    public function present(Tabulation $tabulation): array
    {
        $rounds = $tabulation->rounds()->get();
        $names = $this->candidateRefs((string) $tabulation->race_id);

        $roundsTotal = $rounds->count() > 0 ? (int) $rounds->max('round_no') : 0;

        $display = [];

        foreach ($rounds as $round) {
            $display[] = $this->displayEntry($round, $names, $roundsTotal);
        }

        return [
            'tabulation' => [
                'id'             => (string) $tabulation->id,
                'kind'           => $tabulation->kind,
                'engine_version' => $tabulation->engine_version,
                'status'         => $tabulation->status,
                'completed_at'   => $tabulation->completed_at?->toIso8601String(),
                'record_hash'    => $tabulation->record_hash,
            ],
            'total'   => (int) $tabulation->total_valid,
            'quota'   => (int) $tabulation->quota,
            'seats'   => (int) $tabulation->seats,
            'rounds'  => $roundsTotal,
            'scale'   => max(1, (int) round((int) $tabulation->quota * self::SCALE_FACTOR)),
            'elected' => $this->elected($tabulation, $names),
            'display' => $display,
        ];
    }

    /**
     * VacancyCountback re-run panel (§C "Countback reuse"): the final-state
     * bars of a countback tabulation — struck member (votes '—'), every
     * continuing candidate at their final-round tally, exhausted row last.
     *
     * @return list<array{candidacy_id: string|null, name: string, votes: int|float|null,
     *                    removed: bool, elected: bool, exhausted: bool, write_in: bool}>
     */
    public function countbackBars(Tabulation $tabulation, ?string $winnerCandidacyId): array
    {
        $names = $this->candidateRefs((string) $tabulation->race_id);

        $last = $tabulation->rounds()->orderByDesc('round_no')->first();

        $bars = [];

        // The struck candidacy never enters the re-run universe — render it
        // first as the removed bar (mockup: eliminated styling, votes '—').
        $struckId = $tabulation->excluded_candidacy_id !== null
            ? (string) $tabulation->excluded_candidacy_id
            : null;

        if ($struckId !== null) {
            $ref = $names[$struckId] ?? ['name' => $struckId, 'write_in' => false];

            $bars[] = [
                'candidacy_id' => $struckId,
                'name'         => $ref['name'],
                'votes'        => null,
                'removed'      => true,
                'elected'      => false,
                'exhausted'    => false,
                'write_in'     => (bool) $ref['write_in'],
            ];
        }

        if ($last === null) {
            return $bars;
        }

        $tallies = $last->tallies['candidates'] ?? [];

        $rows = [];
        foreach ($tallies as $candidacyId => $micro) {
            $ref = $names[$candidacyId] ?? ['name' => (string) $candidacyId, 'write_in' => false];

            $rows[] = [
                'candidacy_id' => (string) $candidacyId,
                'name'         => $ref['name'],
                'votes'        => self::votes((int) $micro),
                'removed'      => false,
                'elected'      => $winnerCandidacyId !== null && (string) $candidacyId === $winnerCandidacyId,
                'exhausted'    => false,
                'write_in'     => (bool) $ref['write_in'],
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['votes'] <=> $a['votes']);

        $exhaustedMicro = (int) ($last->tallies['exhausted_micro'] ?? 0)
            + (int) ($last->transfer['exhausted_micro'] ?? 0);

        if ($exhaustedMicro > 0) {
            $rows[] = [
                'candidacy_id' => null,
                'name'         => 'Exhausted ballots',
                'votes'        => self::votes($exhaustedMicro),
                'removed'      => false,
                'elected'      => false,
                'exhausted'    => true,
                'write_in'     => false,
            ];
        }

        return array_merge($bars, $rows);
    }

    // -------------------------------------------------------------------------
    // Per-round display entries
    // -------------------------------------------------------------------------

    /** @param array<string, array{name: string, write_in: bool}> $names */
    private function displayEntry(TabulationRound $round, array $names, int $roundsTotal): array
    {
        $n = (int) $round->round_no;
        $transfer = $round->transfer;

        $entry = [
            'n'      => $n,
            'action' => $this->actionString($round, $names),
        ];

        if (is_array($transfer) && ($transfer['to'] ?? []) !== []) {
            $to = array_map(
                fn (array $pair) => [$this->ref((string) $pair[0], $names), self::votes((int) $pair[1])],
                $transfer['to'],
            );
            usort($to, fn (array $a, array $b) => $b[1] <=> $a[1]);

            $entry['transfer'] = [
                'from'      => $this->ref((string) $round->candidacy_id, $names),
                'kind'      => $transfer['kind'],
                'value'     => $transfer['kind'] === 'surplus' && isset($transfer['value_micro'])
                    ? round(((int) $transfer['value_micro']) / self::MICRO, 3)
                    : null,
                'to'        => $to,
                'exhausted' => self::votes((int) ($transfer['exhausted_micro'] ?? 0)),
            ];
        }

        // Key-round collapse rule (§C): n ≤ 3, every elect round, the final.
        $isKeyRound = $n <= 3
            || $round->action === TabulationRound::ACTION_ELECT
            || $n === $roundsTotal;

        if ($isKeyRound) {
            $tallies = [];
            foreach (($round->tallies['candidates'] ?? []) as $candidacyId => $micro) {
                $tallies[] = [$this->ref((string) $candidacyId, $names), self::votes((int) $micro)];
            }
            usort($tallies, fn (array $a, array $b) => $b[1] <=> $a[1]);

            $entry['tallies'] = $tallies;
            $entry['electedSoFar'] = array_values(array_map(
                fn ($id) => (string) $id,
                $round->tallies['elected_so_far'] ?? [],
            ));
        }

        return $entry;
    }

    /**
     * EXACT mockup action grammar (§C; CountResult::toStvData wording).
     *
     * @param array<string, array{name: string, write_in: bool}> $names
     */
    private function actionString(TabulationRound $round, array $names): string
    {
        $from = $round->candidacy_id !== null
            ? ($names[(string) $round->candidacy_id]['name'] ?? (string) $round->candidacy_id)
            : '';

        $transfer = $round->transfer;

        if (! is_array($transfer)) {
            return $round->action === TabulationRound::ACTION_ELECT
                ? "{$from} elected — fills a remaining seat (no quota transfer)"
                : "{$from} eliminated";
        }

        $toMicro = array_sum(array_map(fn (array $pair) => (int) $pair[1], $transfer['to'] ?? []));
        $exhaustedMicro = (int) ($transfer['exhausted_micro'] ?? 0);

        if (($transfer['kind'] ?? null) === 'surplus') {
            $surplusMicro = $toMicro + $exhaustedMicro + (int) ($transfer['truncation_residue_micro'] ?? 0);
            $surplus = number_format(round($surplusMicro / self::MICRO));
            $value = number_format(((int) ($transfer['value_micro'] ?? 0)) / self::MICRO, 3);

            return "{$from} elected — surplus {$surplus} transfers at value {$value} (Gregory)";
        }

        $moved = number_format(round(($toMicro + $exhaustedMicro) / self::MICRO));

        return "{$from} eliminated — {$moved} votes transfer at current value";
    }

    /** @param array<string, array{name: string, write_in: bool}> $names */
    private function elected(Tabulation $tabulation, array $names): array
    {
        return RaceResult::query()
            ->where('tabulation_id', (string) $tabulation->id)
            ->whereNotNull('seat_no')
            ->orderBy('seat_no')
            ->get(['candidacy_id', 'round_elected', 'seat_no'])
            ->map(fn (RaceResult $result) => [
                'candidacy_id' => (string) $result->candidacy_id,
                'name'         => $names[(string) $result->candidacy_id]['name'] ?? (string) $result->candidacy_id,
                'round'        => (int) $result->round_elected,
                'seat_no'      => (int) $result->seat_no,
                'write_in'     => (bool) ($names[(string) $result->candidacy_id]['write_in'] ?? false),
            ])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Candidate references
    // -------------------------------------------------------------------------

    /** @param array<string, array{name: string, write_in: bool}> $names */
    private function ref(string $candidacyId, array $names): array
    {
        return [
            'candidacy_id' => $candidacyId,
            'name'         => $names[$candidacyId]['name'] ?? $candidacyId,
            'write_in'     => (bool) ($names[$candidacyId]['write_in'] ?? false),
        ];
    }

    /**
     * candidacy_id → { name, write_in } for one race.
     *
     * write_in = NOT inside the finalist set locked at the cutoff. The
     * authoritative source is the 'race.finalists_locked' chain entry
     * (candidacy statuses are overwritten to elected/defeated at
     * certification, so status alone cannot answer this after the fact);
     * pre-certification status is the fallback for races that never had a
     * cutoff entry (defensive).
     *
     * @return array<string, array{name: string, write_in: bool}>
     */
    public function candidateRefs(string $raceId): array
    {
        $finalists = $this->finalistIds($raceId);

        $rows = Candidacy::query()
            ->where('race_id', $raceId)
            ->with('user:id,name,display_name')
            ->get(['id', 'user_id', 'status']);

        $refs = [];

        foreach ($rows as $candidacy) {
            $id = (string) $candidacy->id;

            $isFinalist = $finalists !== null
                ? in_array($id, $finalists, true)
                : $candidacy->status === Candidacy::STATUS_FINALIST;

            $refs[$id] = [
                'name'     => $candidacy->user?->display_name ?: ($candidacy->user?->name ?? 'Unknown candidate'),
                'write_in' => ! $isFinalist,
            ];
        }

        return $refs;
    }

    /**
     * The finalist set sealed by the CLK-21 cutoff entry, or null when no
     * cutoff was ever recorded for this race.
     *
     * @return list<string>|null
     */
    private function finalistIds(string $raceId): ?array
    {
        $row = DB::table('audit_log')
            ->where('module', 'elections')
            ->where('event', 'race.finalists_locked')
            ->where('payload->race_id', $raceId)
            ->orderByDesc('seq')
            ->first(['payload']);

        if ($row === null) {
            return null;
        }

        $payload = json_decode((string) $row->payload, true);

        $finalists = $payload['finalists'] ?? null;

        return is_array($finalists)
            ? array_values(array_map(fn ($id) => (string) $id, $finalists))
            : null;
    }

    /** µv → whole-ish vote number with ≤ 3 decimal places (§C). */
    public static function votes(int $micro): int|float
    {
        $value = round($micro / self::MICRO, 3);

        return $value === floor($value) ? (int) $value : $value;
    }
}
