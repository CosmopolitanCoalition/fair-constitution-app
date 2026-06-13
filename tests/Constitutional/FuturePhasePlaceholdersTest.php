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

    // test_emergency_powers_ceiling_and_civic_process_protection — replaced
    // by the real EmergencyCeilingTest (Phase C / C-E1): closed cause enum
    // (disaster/invasion only), ≤ min(90, resolved max) for declarations
    // AND each renewal, renewal-of-expired rejected, CLK-03 auto-expiry
    // flips active → expired with audit + record, protected-form
    // invariance (EMERGENCY_PROTECTED_FORMS), no deferral API anywhere
    // (architecture assertion). Art. II §7.

    // test_referendum_act_same_term_shield — replaced by the real
    // ReferendumShieldTest (Phase C / C-R1): CLK-19 validator gate
    // (referendum.shield) rejects F-LEG-034 against population-
    // supermajority acts while the shield election is pending; the
    // law_versions writer refuses legislative_amendment on shielded laws;
    // majority-passed acts modify same-term at chamber supermajority;
    // certification of the shield election releases the shield; population
    // thresholds resolve through the PROTECTED quorum()/supermajority()
    // over the CIVIC population. Art. II §6.

    // test_worker_representation_thresholds_and_scaling — replaced by the
    // real WorkerRepresentationTest (Phase D / D-O4): the Art. III §6
    // formula pinned at its endpoints (99→0, 100→1, parity→owner seats,
    // monotone, parity-capped), the frozen mockup cases verbatim, the
    // single-implementation source pin, the invalid-board act/cure
    // posture, the joint-chair majority-of-all-seated gate, and the
    // CLK-13 100th-worker auto-trigger chain (the Phase D exit
    // criterion). Art. III §6.

    // test_cgc_intellectual_property_is_public_domain_forever — replaced
    // by the real CgcIpPublicDomainTest (Phase D / D-O6): append-only
    // schema (trigger + privilege revocation + single-value status
    // CHECK), raw UPDATE/DELETE raise, the dedicate-only write surface
    // (model throws; source-scanned), sale payloads carrying ip_/reclaim
    // keys rejected pre-vote, ip_is_public_domain never flips false on a
    // CGC, and the identical-regulation is_cgc branch pin. Art. III §5.

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
