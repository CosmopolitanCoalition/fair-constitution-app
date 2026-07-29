<?php

namespace Tests\Constitutional;

use App\Services\Elections\RankedProjectionService;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — the ranked-ballot liveAggregate (W4 ⑥). The projection
 * is a first-preference + Droop snapshot produced OUT OF BAND (the daily
 * RankedStandingsRollupJob), never in an HTTP request: an in-request decrypt
 * weakens the secret ballot (Art. II). Two guards:
 *   1. tally() is a PURE first-preference / Droop projection (no full STV run,
 *      no transfers — the card promises those "transfer at the close").
 *   2. NO controller calls BallotBox::decryptForCount / decryptReferendumForCount
 *      — the decrypt boundary stays off the request stack (mirrors
 *      BallotSecrecyTest's rogue-caller source-scan).
 *
 * DB-free by design (like ActivationMathTest). If an edit breaks these, the
 * edit is the violation — fix the edit, not the test.
 */
class RankedLiveAggregateTest extends TestCase
{
    public function test_tally_projects_first_preferences_with_a_droop_quota(): void
    {
        // 6 valid ballots, 2 seats → Droop = floor(6/(2+1)) + 1 = 3.
        $ballots = [
            ['a', 'b'],
            ['a', 'c'],
            ['a'],
            ['b', 'a'],
            ['b'],
            ['c', 'a', 'b'],
        ];

        $tally = RankedProjectionService::tally($ballots, 2);

        $this->assertSame(6, $tally['valid']);
        $this->assertSame(3, $tally['quota']);
        // First preferences ONLY — later preferences are not counted (no transfers).
        $this->assertSame(3, $tally['first_prefs']['a']);
        $this->assertSame(2, $tally['first_prefs']['b']);
        $this->assertSame(1, $tally['first_prefs']['c']);
        // Sorted descending by count — the leader is first.
        $this->assertSame('a', array_key_first($tally['first_prefs']));
    }

    public function test_empty_ballots_leave_the_denominator(): void
    {
        $tally = RankedProjectionService::tally([['a'], [], ['b']], 1);

        $this->assertSame(2, $tally['valid']);  // the empty ballot is invalid
        $this->assertSame(2, $tally['quota']);  // floor(2/(1+1)) + 1
        $this->assertArrayNotHasKey('', $tally['first_prefs']);
    }

    public function test_no_ballots_yields_zero_quota(): void
    {
        $tally = RankedProjectionService::tally([], 5);

        $this->assertSame(0, $tally['valid']);
        $this->assertSame(0, $tally['quota']);
        $this->assertSame([], $tally['first_prefs']);
    }

    /**
     * THE SECRECY BOUNDARY: decryptForCount() is the only path to cleartext
     * rankings and must never sit on an HTTP request stack (Art. II). No
     * controller may call it — the ballot page reads the daily cache only.
     */
    public function test_no_controller_decrypts_ballots(): void
    {
        $controllers = dirname(__DIR__, 2).'/app/Http/Controllers';
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllers, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // Match a CALL (open paren), not the word in prose — a docblock may
            // legitimately name the boundary it stays clear of.
            if (str_contains($src, 'decryptForCount(') || str_contains($src, 'decryptReferendumForCount(')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders,
            'a controller must never decrypt ballots — the liveAggregate reads the out-of-band cache only (Art. II)');
    }
}
