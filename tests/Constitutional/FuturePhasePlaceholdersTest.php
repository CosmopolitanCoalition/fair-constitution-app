<?php

namespace Tests\Constitutional;

use PHPUnit\Framework\TestCase;

/**
 * Named placeholders for the hardened mechanics of Phases B–E.
 *
 * The constitutional suite IS the roadmap: each skipped test names a
 * mechanic that must be pinned before its phase ships. Implementing a
 * phase means replacing the corresponding skip with real assertions —
 * CI gates on this suite, so a phase cannot merge with its mechanic
 * still skipped-out once the implementation lands (reviewers: convert
 * the skip in the same PR as the engine code it pins).
 */
class FuturePhasePlaceholdersTest extends TestCase
{
    // test_ballot_envelope_never_links_to_ballot — replaced by the real
    // BallotSecrecyTest (WI-B2): schema unlinkability, receipt verification
    // against the published hash list, double-vote rejection, audit-chain
    // content discipline. Art. II §2 (ballot secrecy).

    // test_elections_cannot_be_skipped_or_delayed_by_officials — replaced
    // by the real ElectionClockTest (WI-B5): elections fire from the clock
    // registry (handler map pinned), no public API can move a timer's
    // fires_at, ESM-03 has no backward/skip edges, out-of-window special
    // dates are rejected with citation. Art. II §2/§5.

    // test_peg_quorum_uses_all_serving_members — replaced by the real
    // PegQuorumTest (Phase C / C-V2): lane thresholds delegate to the
    // PROTECTED functions, vacancy invariance (8 serving + 1 vacancy →
    // 5/6), absent ≡ no, abstain never counts toward yes, the Speaker
    // stays in the denominator and casts only via F-SPK-004, outcome
    // never computable from `present`. Art. II §2.

    // test_bicameral_dual_agreement_per_kind — replaced by the real
    // BicameralDualAgreementTest (Phase C / C-V2): exactly two kind lanes
    // with independent per-kind quorum + threshold over each lane's OWN
    // serving, failing one kind fails the act (with the failing kind
    // named), identical math at committee and floor (q-ledger #q7).
    // Art. V §3.

    public function test_emergency_powers_ceiling_and_civic_process_protection(): void
    {
        $this->markTestSkipped(
            'Phase C — emergency powers: supermajority only, disaster/invasion causes only, '
            . '≤ 90 days including each renewal, auto-expire, cannot disrupt any civic process '
            . '(engine-level, not UI). Art. II §7.'
        );
    }

    public function test_referendum_act_same_term_shield(): void
    {
        $this->markTestSkipped(
            'Phase C — CLK-19: acts passed by population supermajority cannot be modified or '
            . 'repealed by the legislature in the same term; convert to ordinary law after the '
            . 'next general election. Art. II §6.'
        );
    }

    public function test_worker_representation_thresholds_and_scaling(): void
    {
        $this->markTestSkipped(
            'Phase D — first worker-elected governor at 100 employees, linear scaling to '
            . 'parity at 2,000; joint chair elected by the full board; applies identically to '
            . 'departments, CGCs, and private enterprises. Art. III §6.'
        );
    }

    public function test_cgc_intellectual_property_is_public_domain_forever(): void
    {
        $this->markTestSkipped(
            'Phase D — CGC IP register entries are irreversible: no write path may privatize '
            . 'or delete a public-domain dedication. Art. III §5.'
        );
    }

    public function test_judicial_panels_odd_severity_scaled(): void
    {
        $this->markTestSkipped(
            'Phase E — panels ≥ 3 judges, always odd, severity-scaled; full court for major '
            . 'constitutional questions; minimum 5 judges per race. Art. IV §4.'
        );
    }

    public function test_art_iv_s5_three_path_resolution(): void
    {
        $this->markTestSkipped(
            'Phase E — challenge → finding + remedy + window; legislature amends OR overrides '
            . 'by supermajority within the judicial veto window; else the judiciary applies its '
            . 'remedy directly to the law text as a new version. Art. IV §5.'
        );
    }

    // test_term_lockstep_across_branches — replaced by the real
    // TermLockstepTest (WI-B5/B6): the shared `terms` substrate is live —
    // lockstep windows derive from one schedule, replacement terms inherit
    // the ORIGINAL expiry exactly, and no code path can mutate a lockstep
    // ends_on (write-once at creation, source-scanned). Executive and
    // judicial offices join the SAME table in Phases D/E; the lockstep
    // guarantee they inherit is pinned now. Art. III §3; Art. IV §3.
}
