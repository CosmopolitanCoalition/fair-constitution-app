<?php

namespace App\Services\Demo;

/**
 * A deterministic, INSTANCE-LOCAL pseudo-random source: h₀ = sha256(seed),
 * hᵢ₊₁ = sha256(hᵢ), consuming 8 hex digits (32 bits) per draw.
 *
 * WHY NOT mt_srand(). `mt_srand()` seeds a PROCESS-GLOBAL generator. That is
 * fine inside a single test, which is where
 * tests/Support/SyntheticBallotGenerator uses it — but a populate worker claims
 * thousands of races in one long-lived process, alongside Laravel internals and
 * anything else that draws randomness. A global seed there is both fragile (any
 * other consumer perturbs the stream) and hostile (re-seeding globally to make
 * a race reproducible would silently de-randomize everything else in the
 * process). This object owns its own state, so two races expanded concurrently
 * in one worker cannot interfere, and the same seed always yields the same
 * electorate regardless of what else the process did first.
 *
 * Determinism is a load-bearing property of the simulated world, not a
 * convenience: it is what makes `sim:revert` a bounded DELETE plus a
 * re-derivation rather than a restore-from-backup, and it is what lets anyone
 * holding the published seed reproduce a published count.
 *
 * NOT CRYPTOGRAPHIC. This picks preference orderings for imaginary people.
 * Never use it for salts, keys, tokens, or ballot secrecy — that is
 * `BallotCrypto`'s job, and it uses `random_bytes()`.
 */
final class HashChainRandom
{
    private string $state;

    public function __construct(string $seed)
    {
        $this->state = hash('sha256', $seed);
    }

    /** Next 32-bit draw. */
    public function next(): int
    {
        $this->state = hash('sha256', $this->state);

        return (int) hexdec(substr($this->state, 0, 8));
    }

    /**
     * Uniform-ish integer in [$min, $max]. Modulo bias is negligible here: the
     * ranges are tiny (candidate counts, weights up to a few thousand) against
     * a 2^32 draw, and nothing about picking a fictional voter's preferences is
     * security-sensitive.
     */
    public function between(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + ($this->next() % ($max - $min + 1));
    }
}
