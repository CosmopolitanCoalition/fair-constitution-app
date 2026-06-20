<?php

namespace App\Console\Commands;

use App\Models\InstanceCapability;
use App\Models\OperatorAccount;
use App\Models\PeerUpgradeProposal;
use App\Services\Federation\CapabilityProber;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\MeshRoleGrantService;
use App\Services\PeerUpgradeAgreementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * mesh:role — the operator's driver for the qualify → request → approve → join lifecycle (Mesh Roles &
 * Channels of Trust ★12). A box's "role" is the SET of capability channels it has established; each is
 * qualified, requested, approved, and joined independently.
 *
 *   mesh:role list                         — our channels + their state
 *   mesh:role qualify <capability> [--scope=]   — run the prober (capable-before-request)
 *   mesh:role request <capability> [--scope=]   — self-assert (free) or open a governed role-grant request
 *   mesh:role approve --proposal=<id> [--operator=]  — record Meter A consent + ratify (bootstrap path)
 *   mesh:role revoke  <capability>         — drop one of our channels (always unilateral)
 *
 * A seated government (Meter B) approves through the MultiJurisdictionVote, not this CLI — `approve` covers
 * the bootstrap operator-board path. Refuses to advertise an un-approved governed channel by construction.
 */
class MeshRoleCommand extends Command
{
    protected $signature = 'mesh:role {action : list|qualify|request|approve|revoke}
        {capability? : the channel slug (qualify/request/revoke)}
        {--scope= : scope jurisdiction id (default: the root jurisdiction)}
        {--proposal= : role-grant proposal id (approve)}
        {--operator= : operator username or id to attest as (approve; default: first active)}';

    protected $description = 'Drive the mesh capability lifecycle: qualify / request / approve / list / revoke';

    public function handle(
        CapabilityService $caps,
        CapabilityProber $prober,
        MeshRoleGrantService $grants,
        PeerUpgradeAgreementService $agreement,
        InstanceIdentityService $identity,
    ): int {
        $identity->ensureIdentity();

        return match ((string) $this->argument('action')) {
            'list' => $this->list($caps),
            'qualify' => $this->qualify($prober),
            'request' => $this->request($caps, $grants),
            'approve' => $this->approve($grants, $agreement),
            'revoke' => $this->revoke($grants),
            default => $this->bail('Unknown action — use list|qualify|request|approve|revoke.'),
        };
    }

    private function list(CapabilityService $caps): int
    {
        $rows = $caps->selfCapabilities();
        if ($rows === []) {
            $this->comment('No capability channels established on this box yet.');

            return self::SUCCESS;
        }
        foreach ($rows as $r) {
            $kind = InstanceCapability::isGoverned($r['capability']) ? 'governed' : 'self-asserted';
            $grant = $r['granted_by_server_id'] ? ' (granted by '.substr((string) $r['granted_by_server_id'], 0, 8).'…)' : '';
            $this->info(sprintf('%-20s %-13s prio=%d%s', $r['capability'], $kind, $r['priority'], $grant));
        }

        return self::SUCCESS;
    }

    private function qualify(CapabilityProber $prober): int
    {
        $capability = $this->requireCapability();
        if ($capability === null) {
            return self::FAILURE;
        }
        $result = $prober->probe($capability, $this->scope());
        if ($result['ok']) {
            $this->info("[QUALIFIED] {$capability} — {$result['detail']}");

            return self::SUCCESS;
        }
        $this->error("[NOT QUALIFIED] {$capability} — {$result['detail']}");

        return self::FAILURE;
    }

    private function request(CapabilityService $caps, MeshRoleGrantService $grants): int
    {
        $capability = $this->requireCapability();
        if ($capability === null) {
            return self::FAILURE;
        }

        try {
            if (! InstanceCapability::isGoverned($capability)) {
                $caps->registerSelf($capability);
                $this->info("[ESTABLISHED] {$capability} (self-asserted — no consent needed).");

                return self::SUCCESS;
            }
            $proposal = $grants->request($capability, $this->scope());
            $this->info("[REQUESTED] {$capability} — proposal {$proposal->id}.");
            $this->line('Approve it: mesh:role approve --proposal='.$proposal->id);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function approve(MeshRoleGrantService $grants, PeerUpgradeAgreementService $agreement): int
    {
        $proposalId = (string) $this->option('proposal');
        $proposal = $proposalId !== '' ? PeerUpgradeProposal::query()->find($proposalId) : null;
        if ($proposal === null || $proposal->kind !== PeerUpgradeProposal::KIND_ROLE_GRANT) {
            $this->error('Pass --proposal=<id> of an open role-grant request.');

            return self::FAILURE;
        }

        try {
            // Bootstrap path: record the operator board's attestation (Meter A), then ratify.
            if ($agreement->applicableConsentLeg($proposal->affected_root_jurisdiction_id) === 'operator') {
                $operator = $this->resolveOperator();
                if ($operator === null) {
                    $this->error('No active operator to attest as (Meter A). Pass --operator=<username|id>.');

                    return self::FAILURE;
                }
                $agreement->recordOperatorConsent($proposal, $operator, true);
            } else {
                $this->comment('This scope has a seated government — approval is the MultiJurisdictionVote (Meter B), not this CLI.');
            }

            $ratified = $grants->ratify($proposal);
            $this->info("[GRANTED] {$ratified->capability} — channel enabled, grant minted ({$ratified->status}).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function revoke(MeshRoleGrantService $grants): int
    {
        $capability = $this->requireCapability();
        if ($capability === null) {
            return self::FAILURE;
        }
        $dropped = $grants->revoke($capability, 'operator-revoked via mesh:role');
        $this->info($dropped ? "[DROPPED] {$capability}." : "No enabled channel {$capability} to drop.");

        return self::SUCCESS;
    }

    private function requireCapability(): ?string
    {
        $capability = (string) ($this->argument('capability') ?? '');
        if ($capability === '' || ! in_array($capability, InstanceCapability::CHANNELS, true)) {
            $this->error('Pass a known capability: '.implode(', ', InstanceCapability::CHANNELS));

            return null;
        }

        return $capability;
    }

    private function scope(): string
    {
        $scope = (string) $this->option('scope');
        if ($scope !== '') {
            return $scope;
        }

        return (string) DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');
    }

    private function resolveOperator(): ?OperatorAccount
    {
        $needle = (string) $this->option('operator');
        $q = OperatorAccount::query()->where('status', OperatorAccount::STATUS_ACTIVE)->whereNull('deleted_at');

        if ($needle !== '') {
            return $q->where(fn ($w) => $w->where('username', $needle)->orWhere('id', $needle))->first();
        }

        return $q->orderBy('created_at')->first();
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
