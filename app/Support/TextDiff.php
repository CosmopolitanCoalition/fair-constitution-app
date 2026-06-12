<?php

namespace App\Support;

/**
 * TextDiff — SERVER-computed word-level diff segments for Ui/LawDiff
 * (PHASE_C_DESIGN_frontend.md §A.2). A presenter concern, never the
 * page's: the frontend renders the segments verbatim so what citizens
 * see is exactly the text whose sha256 the audit chain hashed
 * (law_versions.text_hash) — the diff is display sugar over two
 * complete, immutable versions.
 *
 * Algorithm: tokenize into words + attached whitespace, classic LCS over
 * the token lists, adjacent same-op tokens coalesced. Law texts are
 * small; the quadratic LCS is bounded by TOKEN_CAP with an honest
 * whole-text del/ins fallback (never a wrong diff, only a coarse one).
 */
final class TextDiff
{
    /** LCS guard — beyond this the diff degrades to whole-text del/ins. */
    private const TOKEN_CAP = 4000;

    /**
     * @return list<array{op: 'eq'|'del'|'ins', text: string}>
     */
    public static function segments(string $old, string $new): array
    {
        if ($old === $new) {
            return $old === '' ? [] : [['op' => 'eq', 'text' => $old]];
        }

        $a = self::tokenize($old);
        $b = self::tokenize($new);

        if (count($a) > self::TOKEN_CAP || count($b) > self::TOKEN_CAP) {
            return array_values(array_filter([
                $old !== '' ? ['op' => 'del', 'text' => $old] : null,
                $new !== '' ? ['op' => 'ins', 'text' => $new] : null,
            ]));
        }

        return self::coalesce(self::walk($a, $b));
    }

    /** Words with their trailing whitespace attached (round-trips exactly). */
    private static function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }

    /**
     * LCS table + backtrack → raw per-token ops.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{op: string, text: string}>
     */
    private static function walk(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // One row at a time keeps memory at O(m); we still need the full
        // table for backtracking, so store packed rows of ints.
        $table = [array_fill(0, $m + 1, 0)];

        for ($i = 1; $i <= $n; $i++) {
            $row  = [0];
            $prev = $table[$i - 1];

            for ($j = 1; $j <= $m; $j++) {
                $row[$j] = $a[$i - 1] === $b[$j - 1]
                    ? $prev[$j - 1] + 1
                    : max($prev[$j], $row[$j - 1]);
            }

            $table[$i] = $row;
        }

        $ops = [];
        $i = $n;
        $j = $m;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $ops[] = ['op' => 'eq', 'text' => $a[--$i]];
                $j--;
            } elseif ($j > 0 && ($i === 0 || $table[$i][$j - 1] >= $table[$i - 1][$j])) {
                $ops[] = ['op' => 'ins', 'text' => $b[--$j]];
            } else {
                $ops[] = ['op' => 'del', 'text' => $a[--$i]];
            }
        }

        return array_reverse($ops);
    }

    /**
     * Merge runs of the same op into single segments.
     *
     * @param  list<array{op: string, text: string}>  $ops
     * @return list<array{op: string, text: string}>
     */
    private static function coalesce(array $ops): array
    {
        $out = [];

        foreach ($ops as $op) {
            $last = count($out) - 1;

            if ($last >= 0 && $out[$last]['op'] === $op['op']) {
                $out[$last]['text'] .= $op['text'];
            } else {
                $out[] = $op;
            }
        }

        return $out;
    }
}
