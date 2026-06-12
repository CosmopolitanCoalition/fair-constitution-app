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

    public function test_elections_cannot_be_skipped_or_delayed_by_officials(): void
    {
        $this->markTestSkipped(
            'Phase B — CLK-01/CLK-02 election triggers fire from the clock registry with no '
            . 'discretionary suppression path. Hardened per the architecture plan.'
        );
    }

    public function test_peg_quorum_uses_all_serving_members(): void
    {
        $this->markTestSkipped(
            'Phase C — chamber votes: quorum and pass thresholds computed over ALL serving '
            . 'members (vacancies stay in the denominator), never members present. Art. II §2.'
        );
    }

    public function test_bicameral_dual_agreement_per_kind(): void
    {
        $this->markTestSkipped(
            'Phase C — type A and type B seat kinds must independently satisfy their own '
            . 'peg quorum + majority/supermajority, at committee AND floor (q-ledger #q7). Art. V §3.'
        );
    }

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

    public function test_term_lockstep_across_branches(): void
    {
        $this->markTestSkipped(
            'Phase E — legislative, elected-executive, and elected-judicial terms expire '
            . 'together; harmonization at the encompassing level. Art. III §3; Art. IV §3.'
        );
    }
}
