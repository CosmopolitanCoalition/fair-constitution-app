<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Models\OperatorAccount;
use App\Models\PeerUpgradeConsent;
use App\Models\PeerUpgradeProposal;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * G-VER — the constitutional upgrade-agreement protocol. Structurally a sibling of
 * LocalAutonomyService: a version-diff is PROPOSED (signed + chained), CONSENTED to
 * through the applicable meter, and only RATIFIED when every required gate clears —
 * the LocalAutonomyService::finalize discipline (refuse-with-citation unless all
 * legs passed, then apply inside a transaction + chain the result).
 *
 * Only a `constitutional_bump` (a move in HOW the constitution counts) is gated:
 *   • the HARDENED admissibility filter — reject a bump that decreases proportional
 *     representation or weakens the supermajority floor (Art. VII). Reach-independent
 *     and UNGATEABLE: it reuses the PROTECTED ConstitutionalValidator, so no operator,
 *     no legislature supermajority, and no peer quorum can pass it.
 *   • the FREEZE — refuse while any live process exists in the subtree (Art. II §7,
 *     UpgradeFreezeService).
 *   • the CONSENT leg, by seatedness (the bootstrap-board pivot, Art. II §2):
 *       – NOT seated ⇒ Meter A, the R-08 operator-board attestation. Scaling consent
 *         (vetting-gated to ACTIVE operators): 1 ⇒ 1, 2 ⇒ unanimity, 3+ ⇒ 2/3 via the
 *         PROTECTED ConstitutionalValidator::supermajority.
 *       – seated ⇒ Meter B, the seated government's supermajority consent via the
 *         `peer_upgrade` MultiJurisdictionVote. A seated government SUPERSEDES the
 *         operator board — operators cannot consent on its behalf.
 *   • [Meter C — peer-mesh consent over co-affected subtrees — lands in G-VER 4.]
 *
 * schema_version / app_release bumps are wire-shape / provenance: no freeze, no
 * admissibility filter, no consent meters — they ratify as a recorded version
 * transition (the db295eb-style operational deploy cadence, unobstructed).
 *
 * Reuses the engine, never forks it: supermajority math is
 * ConstitutionalValidator::supermajority via MultiJurisdictionVoteService; signing
 * is InstanceIdentityService::sign; the freeze read-guard mirrors the mirror
 * write-guard. No new crypto, no new vote math.
 */
class PeerUpgradeAgreementService
{
    public function __construct(
        private readonly MultiJurisdictionVoteService $mjv,
        private readonly AuditService $audit,
        private readonly InstanceIdentityService $identity,
        private readonly UpgradeFreezeService $freeze,
        private readonly ConstitutionalVersionService $version,
    ) {}

    // -------------------------------------------------------------------------
    // propose
    // -------------------------------------------------------------------------

    /**
     * Open a signed, subtree-scoped upgrade proposal. For a constitutional_bump the
     * hardened admissibility filter runs FIRST (a regressive bump never even opens).
     *
     * @param  array<string,mixed>  $hardenedParams  proposed hardened policy snapshot
     *                              (voting_method, supermajority_numerator/denominator)
     */
    public function propose(
        string $kind,
        string $rootJurisdictionId,
        ?string $toConstitutionalVersion = null,
        ?string $toAppRelease = null,
        ?string $toSchemaVersion = null,
        array $hardenedParams = [],
    ): PeerUpgradeProposal {
        if (! in_array($kind, [
            PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP,
            PeerUpgradeProposal::KIND_SCHEMA_BUMP,
            PeerUpgradeProposal::KIND_APP_RELEASE,
        ], true)) {
            throw new RuntimeException("Unknown upgrade kind [{$kind}].");
        }

        $exists = DB::table('jurisdictions')->where('id', $rootJurisdictionId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            throw new RuntimeException("Unknown jurisdiction {$rootJurisdictionId}.");
        }

        $settings = InstanceSettings::current();
        $fromCv = $settings->constitutionalVersion();
        $fromApp = $settings->app_release ?? config('cga.app_release');
        $fromSchema = config('cga.schema_version', '1');

        $toCv = $fromCv;

        if ($kind === PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP) {
            $toCv = $toConstitutionalVersion ?? $this->version->derive();

            if ($toCv === $fromCv) {
                throw new ConstitutionalViolation(
                    'There is nothing to agree — the constitutional_version is unchanged.',
                    'Art. VII · as implemented',
                );
            }

            // HARDENED admissibility floor — reach-independent, ungateable (Art. VII).
            $this->assertAdmissible($hardenedParams);
        }

        $core = [
            'kind' => $kind,
            'from_constitutional_version' => $fromCv,
            'to_constitutional_version' => $toCv,
            'from_app_release' => $fromApp,
            'to_app_release' => $toAppRelease,
            'from_schema_version' => $fromSchema,
            'to_schema_version' => $toSchemaVersion,
            'affected_root_jurisdiction_id' => $rootJurisdictionId,
            'hardened_params' => $hardenedParams === [] ? null : $hardenedParams,
        ];

        return DB::transaction(function () use ($kind, $core, $rootJurisdictionId, $hardenedParams): PeerUpgradeProposal {
            $proposal = PeerUpgradeProposal::create(array_merge($core, [
                'hardened_params' => $hardenedParams === [] ? null : $hardenedParams,
                'proposed_by_server_id' => $this->identity->serverId(),
                'signature' => $this->identity->sign(AuditService::canonicalJson($core)),
                'status' => PeerUpgradeProposal::STATUS_OPEN,
            ]));

            $this->audit->append('federation', 'upgrade.proposed', [
                'proposal_id' => (string) $proposal->id,
                'kind' => $kind,
                'from_constitutional_version' => $core['from_constitutional_version'],
                'to_constitutional_version' => $core['to_constitutional_version'],
                'affected_root_jurisdiction_id' => $rootJurisdictionId,
                'consent_leg' => $this->applicableConsentLeg($rootJurisdictionId),
            ], 'G-VER', null, $rootJurisdictionId);

            return $proposal->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Meter A — the operator-board attestation (bootstrap standing)
    // -------------------------------------------------------------------------

    /** Record one vetted operator's attestation (Meter A; bootstrap-only standing). */
    public function recordOperatorConsent(
        PeerUpgradeProposal $proposal,
        OperatorAccount $operator,
        bool $consented,
        ?string $signature = null,
    ): PeerUpgradeConsent {
        $proposal = $proposal->refresh();

        if (! $proposal->isOpen()) {
            throw new ConstitutionalViolation('The upgrade proposal is not open.', 'Art. II §2 · as implemented');
        }

        // Vetting rail (anti-Sybil): only an ACTIVE operator counts as board.
        if (! $operator->isActive()) {
            throw new ConstitutionalViolation(
                'Only a vetted (active) operator may attest as the de-facto election board.',
                'Art. II §2',
            );
        }

        // A seated government SUPERSEDES the operator board — operators cannot
        // consent on its behalf (the bootstrap-note transition, Art. II §2).
        if ($this->applicableConsentLeg($proposal->affected_root_jurisdiction_id) !== 'operator') {
            throw new ConstitutionalViolation(
                'This jurisdiction has a seated government — its supermajority consent (Meter B) supersedes '
                .'the operator board; an operator cannot attest on its behalf.',
                'Art. II §2',
            );
        }

        return DB::transaction(function () use ($proposal, $operator, $consented, $signature): PeerUpgradeConsent {
            $consent = PeerUpgradeConsent::query()->firstOrNew([
                'proposal_id' => (string) $proposal->id,
                'operator_account_id' => (string) $operator->id,
                'meter' => PeerUpgradeConsent::METER_OPERATOR,
            ]);

            if ($consent->exists && $consent->result !== PeerUpgradeConsent::RESULT_PENDING) {
                throw new ConstitutionalViolation('This operator has already attested.', 'Art. II §2 · as implemented');
            }

            $consent->fill([
                'mesh_operator_id' => $operator->mesh_operator_id,
                'result' => $consented ? PeerUpgradeConsent::RESULT_YES : PeerUpgradeConsent::RESULT_NO,
                'signature' => $signature,
                'decided_at' => now(),
            ])->save();

            $this->audit->append('federation', 'upgrade.operator_consent', [
                'proposal_id' => (string) $proposal->id,
                'operator_account_id' => (string) $operator->id,
                'result' => $consent->result,
            ], 'G-VER', null, $proposal->affected_root_jurisdiction_id);

            return $consent;
        });
    }

    // -------------------------------------------------------------------------
    // Meter B — the seated-institution leg (supersedes A once a government seats)
    // -------------------------------------------------------------------------

    /**
     * Open the seated government's supermajority consent vote (Meter B). Constituents
     * = the root's direct child jurisdictions holding a legislature (Art. VII's
     * "Supermajority of Constituent Jurisdictions"); when there are none the root's
     * own seated legislature consents as one body ("...or a Supermajority of The
     * Legislature if there are no Constituent Jurisdictions" — its internal 2/3
     * chamber vote is the driver, the LocalAutonomyService UNANIMITY-of-1 pattern).
     */
    public function openSeatedLeg(PeerUpgradeProposal $proposal): MultiJurisdictionVote
    {
        $proposal = $proposal->refresh();

        if (! $proposal->isOpen()) {
            throw new ConstitutionalViolation('The upgrade proposal is not open.', 'Art. II §2 · as implemented');
        }

        if ($proposal->seated_process_id !== null) {
            return MultiJurisdictionVote::query()->findOrFail($proposal->seated_process_id); // idempotent
        }

        $legislature = $this->seatedLegislatureFor($proposal->affected_root_jurisdiction_id);

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'This jurisdiction has no seated government — the operator board stands in (Meter A); '
                .'there is no seated leg to open.',
                'Art. II §2',
            );
        }

        $constituents = ConstituentResolver::ids($legislature);
        $useConstituents = $constituents !== [];
        $ids = $useConstituents ? $constituents : [(string) $legislature->jurisdiction_id];
        $basis = $useConstituents
            ? MultiJurisdictionVote::BASIS_SUPERMAJORITY
            : MultiJurisdictionVote::BASIS_UNANIMITY;

        return DB::transaction(function () use ($proposal, $legislature, $ids, $basis): MultiJurisdictionVote {
            $mjv = $this->mjv->open(
                'peer_upgrade',
                $legislature,
                $ids,
                $basis,
                null,
                'peer_upgrade_proposals',
                (string) $proposal->id,
            );

            $proposal->forceFill(['seated_process_id' => (string) $mjv->id])->save();

            PeerUpgradeConsent::create([
                'proposal_id' => (string) $proposal->id,
                'meter' => PeerUpgradeConsent::METER_SEATED,
                'mjv_process_id' => (string) $mjv->id,
                'result' => PeerUpgradeConsent::RESULT_PENDING,
            ]);

            $this->audit->append('federation', 'upgrade.seated_leg_opened', [
                'proposal_id' => (string) $proposal->id,
                'mjv_process_id' => (string) $mjv->id,
                'basis' => $basis,
                'constituent_total' => count($ids),
            ], 'G-VER', null, $proposal->affected_root_jurisdiction_id);

            return $mjv;
        });
    }

    /** Record one constituent's decision on the seated leg (delegates to the MJV substrate). */
    public function recordSeatedConsent(
        PeerUpgradeProposal $proposal,
        string $jurisdictionId,
        bool $consented,
        ?string $legislatureId = null,
    ): void {
        $proposal = $proposal->refresh();

        if ($proposal->seated_process_id === null) {
            throw new ConstitutionalViolation('The seated leg has not been opened.', 'Art. II §2 · as implemented');
        }

        $mjv = MultiJurisdictionVote::query()->findOrFail($proposal->seated_process_id);
        $this->mjv->recordConsent($mjv, $jurisdictionId, $consented, null, $legislatureId);
    }

    // -------------------------------------------------------------------------
    // Meter C — peer-mesh agreement (federation safety)
    // -------------------------------------------------------------------------

    /**
     * Record one co-affected peer's mesh consent (Meter C). The peer must be a
     * trust-established peer authoritative for a jurisdiction in the affected subtree
     * — a peer with no stake in this subtree has no standing to consent. The peer's
     * decision rides the mesh as a signed S2S message in a live deployment; this is
     * the recording + evaluation surface (dev-stack testable; cross-instance delivery
     * reuses the existing Phase F S2S transport, like the rest of the mesh).
     */
    public function recordPeerConsent(
        PeerUpgradeProposal $proposal,
        string $peerServerId,
        bool $consented,
        ?string $signature = null,
    ): PeerUpgradeConsent {
        $proposal = $proposal->refresh();

        if (! $proposal->isOpen()) {
            throw new ConstitutionalViolation('The upgrade proposal is not open.', 'Art. II §2 · as implemented');
        }

        if (! in_array($peerServerId, $this->coAffectedPeerServerIds($proposal->affected_root_jurisdiction_id), true)) {
            throw new ConstitutionalViolation(
                'Only a trust-established peer authoritative for a co-affected subtree records mesh consent (Meter C).',
                'Art. VII',
            );
        }

        return DB::transaction(function () use ($proposal, $peerServerId, $consented, $signature): PeerUpgradeConsent {
            $consent = PeerUpgradeConsent::query()->firstOrNew([
                'proposal_id' => (string) $proposal->id,
                'peer_server_id' => $peerServerId,
                'meter' => PeerUpgradeConsent::METER_PEER,
            ]);

            if ($consent->exists && $consent->result !== PeerUpgradeConsent::RESULT_PENDING) {
                throw new ConstitutionalViolation('This peer has already recorded its mesh consent.', 'Art. VII · as implemented');
            }

            $consent->fill([
                'result' => $consented ? PeerUpgradeConsent::RESULT_YES : PeerUpgradeConsent::RESULT_NO,
                'signature' => $signature,
                'decided_at' => now(),
            ])->save();

            $this->audit->append('federation', 'upgrade.peer_consent', [
                'proposal_id' => (string) $proposal->id,
                'peer_server_id' => $peerServerId,
                'result' => $consent->result,
            ], 'G-VER', null, $proposal->affected_root_jurisdiction_id);

            return $consent;
        });
    }

    // -------------------------------------------------------------------------
    // ratify — the LocalAutonomyService::finalize discipline
    // -------------------------------------------------------------------------

    /**
     * Apply the upgrade to the subtree, refusing (with citation) unless every
     * required gate cleared. For a constitutional_bump: the admissibility filter
     * (re-asserted), the freeze (Art. II §7), and the applicable consent leg.
     * schema/app_release bumps apply as a recorded provenance transition.
     */
    public function ratify(PeerUpgradeProposal $proposal): PeerUpgradeProposal
    {
        $proposal = $proposal->refresh();

        if (! $proposal->isOpen()) {
            throw new ConstitutionalViolation('The upgrade proposal is not open.', 'Art. II §2 · as implemented');
        }

        if ($proposal->kind === PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP) {
            // Defense in depth: the floor that no consent can buy past (Art. VII).
            $this->assertAdmissible($proposal->hardened_params ?? []);

            // The game-in-progress freeze — never re-rule a process in flight (Art. II §7).
            $this->freeze->assertThawed($proposal->affected_root_jurisdiction_id);

            // The applicable consent leg, by current seatedness (re-evaluated live —
            // a government that seated mid-process supersedes the operator board).
            $leg = $this->applicableConsentLeg($proposal->affected_root_jurisdiction_id);

            if ($leg === 'seated') {
                if (! $this->meterBPassed($proposal)) {
                    throw new ConstitutionalViolation(
                        'A constitutional-version upgrade to a jurisdiction with a seated government requires '
                        .'that government\'s supermajority consent (Meter B) — it has not been reached.',
                        'Art. VII',
                    );
                }
            } elseif (! $this->meterAPassed($proposal)) {
                throw new ConstitutionalViolation(
                    'A constitutional-version upgrade in bootstrap mode requires the operator board\'s '
                    .'attestation (Meter A) — the scaling-consent threshold has not been reached.',
                    'Art. II §2',
                );
            }

            // Meter C — peer-mesh agreement: every trust-established peer authoritative
            // for a co-affected subtree must consent before divergent instances resume
            // cross-counting (fail-closed). Auto-passes when no such peer exists — a
            // lone instance, or a subtree we are wholly authoritative for.
            if (! $this->meterCPassed($proposal)) {
                throw new ConstitutionalViolation(
                    'A constitutional-version upgrade affecting a subtree a peer is authoritative for requires '
                    .'that peer\'s mesh consent (Meter C) before the mesh resumes cross-counting — it has not '
                    .'been recorded for every co-affected peer.',
                    'Art. VII',
                );
            }
        }

        return DB::transaction(function () use ($proposal): PeerUpgradeProposal {
            $settings = InstanceSettings::current();

            if ($proposal->kind === PeerUpgradeProposal::KIND_CONSTITUTIONAL_BUMP) {
                $settings->pinConstitutionalVersion($proposal->to_constitutional_version);
            } elseif ($proposal->kind === PeerUpgradeProposal::KIND_APP_RELEASE) {
                $settings->forceFill(['app_release' => $proposal->to_app_release])->save();
            }
            // schema_bump: wire-shape only; recorded here, enforced by Meter C sync-refusal (G-VER 4).

            $proposal->forceFill([
                'status' => PeerUpgradeProposal::STATUS_RATIFIED,
                'ratified_at' => now(),
            ])->save();

            $this->audit->append('federation', 'upgrade.ratified', [
                'proposal_id' => (string) $proposal->id,
                'kind' => $proposal->kind,
                'to_constitutional_version' => $proposal->to_constitutional_version,
                'to_app_release' => $proposal->to_app_release,
                'affected_root_jurisdiction_id' => $proposal->affected_root_jurisdiction_id,
            ], 'G-VER', null, $proposal->affected_root_jurisdiction_id);

            return $proposal->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // meter evaluation + helpers
    // -------------------------------------------------------------------------

    /** Which consent leg applies right now: 'seated' if a seated government exists, else 'operator'. */
    public function applicableConsentLeg(string $rootJurisdictionId): string
    {
        return $this->seatedLegislatureFor($rootJurisdictionId) !== null ? 'seated' : 'operator';
    }

    /**
     * Meter A passed: yes-attestations from CURRENTLY-ACTIVE operators meet the
     * scaling threshold (1 ⇒ 1, 2 ⇒ unanimity, 3+ ⇒ 2/3). Re-checking active
     * membership here re-applies the vetting rail at evaluation time.
     */
    public function meterAPassed(PeerUpgradeProposal $proposal): bool
    {
        $boardIds = $this->activeOperatorBoard()->pluck('id')->map(fn ($i) => (string) $i)->all();
        $n = count($boardIds);

        if ($n === 0) {
            return false; // no board to consent
        }

        $required = match (true) {
            $n === 1 => 1,
            $n === 2 => 2, // unanimity of the pair
            default => ConstitutionalValidator::supermajority($n),
        };

        $yes = PeerUpgradeConsent::query()
            ->where('proposal_id', (string) $proposal->id)
            ->where('meter', PeerUpgradeConsent::METER_OPERATOR)
            ->where('result', PeerUpgradeConsent::RESULT_YES)
            ->whereIn('operator_account_id', $boardIds)
            ->count();

        return $yes >= $required;
    }

    /** Meter B passed: the seated peer_upgrade MJV reached its supermajority. */
    public function meterBPassed(PeerUpgradeProposal $proposal): bool
    {
        if ($proposal->seated_process_id === null) {
            return false;
        }

        $mjv = MultiJurisdictionVote::query()->find($proposal->seated_process_id);

        return $mjv !== null && $mjv->status === MultiJurisdictionVote::STATUS_PASSED;
    }

    /**
     * Meter C passed: every co-affected peer recorded a 'yes' (unanimity — each peer
     * is sovereign over its own subtree, so none may be overruled). Auto-passes when
     * there are no co-affected peers (nothing to consult).
     */
    public function meterCPassed(PeerUpgradeProposal $proposal): bool
    {
        $coAffected = $this->coAffectedPeerServerIds($proposal->affected_root_jurisdiction_id);

        if ($coAffected === []) {
            return true; // no peer holds a co-affected subtree — N/A
        }

        $consented = PeerUpgradeConsent::query()
            ->where('proposal_id', (string) $proposal->id)
            ->where('meter', PeerUpgradeConsent::METER_PEER)
            ->where('result', PeerUpgradeConsent::RESULT_YES)
            ->whereIn('peer_server_id', $coAffected)
            ->distinct()
            ->pluck('peer_server_id')
            ->map(fn ($i) => (string) $i)
            ->all();

        return count($consented) >= count($coAffected);
    }

    /**
     * Trust-established peers authoritative for any jurisdiction in the affected
     * subtree (root + descendants). These are the instances that would otherwise
     * cross-count under a divergent version — the Meter C electorate.
     *
     * @return list<string>
     */
    public function coAffectedPeerServerIds(string $rootJurisdictionId): array
    {
        $subtree = $this->descendantIds($rootJurisdictionId);

        if ($subtree === []) {
            return [];
        }

        $authServerIds = DB::table('jurisdictions')
            ->whereIn('id', $subtree)
            ->whereNotNull('authoritative_server_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('authoritative_server_id')
            ->map(fn ($i) => (string) $i)
            ->all();

        if ($authServerIds === []) {
            return [];
        }

        return FederationPeer::query()
            ->whereIn('server_id', $authServerIds)
            ->where('status', FederationPeer::STATUS_TRUST_ESTABLISHED)
            ->whereNull('deleted_at')
            ->pluck('server_id')
            ->map(fn ($i) => (string) $i)
            ->all();
    }

    /** Root + all descendants (recursive; soft-deletes excluded). */
    private function descendantIds(string $root): array
    {
        $rows = DB::select(
            'WITH RECURSIVE jh AS ('
            .'   SELECT id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL'
            .'   UNION ALL'
            .'   SELECT j.id FROM jurisdictions j JOIN jh ON j.parent_id = jh.id WHERE j.deleted_at IS NULL'
            .' ) SELECT id FROM jh',
            [$root]
        );

        return array_map(fn ($r) => (string) $r->id, $rows);
    }

    /** The seated legislature governing the affected root (null = bootstrap mode). */
    private function seatedLegislatureFor(string $rootJurisdictionId): ?Legislature
    {
        return Legislature::query()
            ->where('jurisdiction_id', $rootJurisdictionId)
            ->where('status', Legislature::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->first();
    }

    /** The vetted de-facto board: active local operator accounts. */
    private function activeOperatorBoard(): Collection
    {
        return OperatorAccount::query()
            ->where('status', OperatorAccount::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->get();
    }

    /**
     * The hardened admissibility floor (Art. VII), ungateable. REUSES the PROTECTED
     * ConstitutionalValidator: a proposed voting_method outside the proportionality
     * whitelist, or a supermajority fraction at/below 1/2, throws exactly as an
     * F-LEG-031 setting bill would — no operator/legislature/peer consent can buy
     * past it. An empty params snapshot (a pure code-version bump with no declared
     * policy change) is admissible; algorithm-level proportionality is pinned by the
     * constitutional test suite (executable constitutional law).
     *
     * @param  array<string,mixed>  $params
     */
    private function assertAdmissible(array $params): void
    {
        if ($params === []) {
            return;
        }

        $validator = app(ConstitutionalValidator::class);

        if (array_key_exists('voting_method', $params)) {
            $validator->checkSettingChange([
                'setting_key' => 'voting_method',
                'value' => $params['voting_method'],
            ]);
        }

        if (array_key_exists('supermajority_numerator', $params)
            || array_key_exists('supermajority_denominator', $params)) {
            $validator->checkSettingChange([
                'setting_key' => 'supermajority_numerator',
                'value' => (int) ($params['supermajority_numerator'] ?? 2),
                'supermajority_denominator' => (int) ($params['supermajority_denominator'] ?? 3),
            ]);
        }
    }
}
