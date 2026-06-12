<?php

namespace App\Domain\Counting;

/**
 * Complete record of one count. Serializes to the `tabulations` +
 * `tabulation_rounds` storage shape and (via toStvData) to the mockup
 * STV_DATA display shape.
 *
 * recordHash() is the certified artifact (F-ELB-004): sha256 over the
 * canonical JSON of exactly {engine_version, seats, quota, total_valid,
 * rounds} — RFC-8785-style recursively key-sorted objects, the same
 * canonicalization AuditService uses for the audit chain. Same input →
 * byte-identical hash on any machine (design §B.3/§B.4).
 */
final readonly class CountResult
{
    /**
     * @param  string  $kind  'stv' | 'rcv'
     * @param  int  $quota  STV: Droop quota (votes); RCV: display majority floor(total/2)+1
     * @param  list<RoundResult>  $rounds
     * @param  list<array{candidacy_id: string, round: int, seat_no: int}>  $elected  election order
     * @param  array<string, int>  $finalTallies  µv standings after the last round (id-ascending)
     */
    public function __construct(
        public string $engineVersion,
        public string $kind,
        public int $seats,
        public int $quota,
        public int $totalValid,
        public array $rounds,
        public array $elected,
        public int $seatsUnfilled,
        public int $exhaustedMicro,
        public int $truncationResidueMicro,
        public array $finalTallies,
    ) {
    }

    public function toArray(): array
    {
        return [
            'engine_version' => $this->engineVersion,
            'kind' => $this->kind,
            'seats' => $this->seats,
            'quota' => $this->quota,
            'total_valid' => $this->totalValid,
            'rounds' => array_map(fn (RoundResult $r) => $r->toArray(), $this->rounds),
            'elected' => $this->elected,
            'seats_unfilled' => $this->seatsUnfilled,
            'exhausted_micro' => $this->exhaustedMicro,
            'truncation_residue_micro' => $this->truncationResidueMicro,
            'final_tallies' => $this->finalTallies,
            'record_hash' => $this->recordHash(),
        ];
    }

    /**
     * sha256 of the canonical JSON of the five certified keys (§B.3).
     */
    public function recordHash(): string
    {
        $certified = [
            'engine_version' => $this->engineVersion,
            'seats' => $this->seats,
            'quota' => $this->quota,
            'total_valid' => $this->totalValid,
            'rounds' => array_map(fn (RoundResult $r) => $r->toArray(), $this->rounds),
        ];

        return hash('sha256', self::canonicalJson($certified));
    }

    /**
     * Canonical JSON — byte-compatible with AuditService::canonicalJson()
     * (recursively key-sorted objects, lists kept in order, unescaped
     * slashes/unicode). Duplicated here rather than imported so the pure
     * domain layer keeps zero dependencies on service classes.
     */
    public static function canonicalJson(array $payload): string
    {
        $normalized = json_decode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true
        ) ?? [];

        return json_encode(self::ksortRecursive($normalized), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $sorted = array_map(self::ksortRecursive(...), $value);

        if (! $isList) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }

    /**
     * Mockup STV_DATA display shape (results.html window.STV_DATA):
     * µv → whole votes with round() at the edge, transfer value to 3 dp,
     * `to`/tallies sorted descending by votes for display. Presentation
     * only — never hashed, never stored.
     *
     * @param  array<string, string>  $names  candidacy_id → display name
     */
    public function toStvData(array $names): array
    {
        $name = fn (string $id): string => $names[$id] ?? $id;
        $votes = fn (int $micro): int => (int) round($micro / Micro::SCALE);

        $display = [];

        foreach ($this->rounds as $round) {
            $t = $round->transfer;
            $from = $round->candidacyId !== null ? $name($round->candidacyId) : '';

            if ($t === null) {
                $action = $round->action === 'elect'
                    ? "{$from} elected — fills a remaining seat (no quota transfer)"
                    : "{$from} eliminated";
                $transfer = null;
            } elseif ($t['kind'] === 'surplus') {
                $surplus = $votes(array_sum(array_map(fn ($p) => $p[1], $t['to'])) + $t['exhausted_micro'] + $t['truncation_residue_micro']);
                $value = number_format($t['value_micro'] / Micro::SCALE, 3);
                $action = "{$from} elected — surplus " . number_format($surplus) . " transfers at value {$value} (Gregory)";
                $transfer = $t;
            } else {
                $moved = $votes(array_sum(array_map(fn ($p) => $p[1], $t['to'])) + $t['exhausted_micro']);
                $action = "{$from} eliminated — " . number_format($moved) . ' votes transfer at current value';
                $transfer = $t;
            }

            $entry = ['n' => $round->roundNo, 'action' => $action];

            if ($transfer !== null) {
                $to = array_map(fn ($p) => [$name($p[0]), $votes($p[1])], $transfer['to']);
                usort($to, fn ($a, $b) => $b[1] <=> $a[1]);
                $entry['transfer'] = [
                    'from' => $from,
                    'kind' => $transfer['kind'],
                    'to' => $to,
                    'exhausted' => $votes($transfer['exhausted_micro']),
                ];
            }

            $tallies = [];
            foreach ($round->tallies['candidates'] as $id => $micro) {
                $tallies[] = [$name($id), $votes($micro)];
            }
            usort($tallies, fn ($a, $b) => $b[1] <=> $a[1]);

            $entry['tallies'] = $tallies;
            $entry['electedSoFar'] = array_map($name, $round->tallies['elected_so_far']);

            $display[] = $entry;
        }

        return [
            'total' => $this->totalValid,
            'quota' => $this->quota,
            'seats' => $this->seats,
            'rounds' => count($this->rounds),
            'elected' => array_map(
                fn (array $e) => ['name' => $name($e['candidacy_id']), 'round' => $e['round']],
                $this->elected
            ),
            'display' => $display,
        ];
    }
}
