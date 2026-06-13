<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proposal store for the chamber-ops act-creating votes (F-LEG-009/012/
 * 013/032/033): carries the act payload (name/seats/nominees/law text)
 * between vote OPEN and vote ADOPTION across many member cast filings —
 * chamber_votes (sibling scope) has no payload column, and the institution
 * row itself is created only on adoption (a failed vote leaves a
 * `rejected` proposal, never a half-born institution).
 *
 * `vote_id` soft-refs chamber_votes; `result_type`/`result_id` point at
 * whatever adoption created (committees / election_boards / admin_offices
 * / laws row).
 */
class ChamberVoteProposal extends Model
{
    use HasUuids;

    public const KIND_COMMITTEE_CREATION = 'committee_creation';

    public const KIND_ELECTION_BOARD_CREATION = 'election_board_creation';

    public const KIND_ADMIN_OFFICE_CREATION = 'admin_office_creation';

    public const KIND_RULES_OF_ORDER = 'rules_of_order';

    public const KIND_ETHICS_CODE = 'ethics_code';

    // Votes-laws batch 2 (C-8/C-9): referendum delegation (F-LEG-023),
    // referendum-act modification (F-LEG-034), emergency invocation /
    // renewal (F-LEG-024/025) — same posture: the institution row
    // (question / law version / power) is created only on adoption.
    public const KIND_REFERENDUM_DELEGATION = 'referendum_delegation';

    public const KIND_REFERENDUM_ACT_MODIFICATION = 'referendum_act_modification';

    public const KIND_EMERGENCY_INVOCATION = 'emergency_invocation';

    public const KIND_EMERGENCY_RENEWAL = 'emergency_renewal';

    // Phase D executive scope (PHASE_D_DESIGN_executive §B/§C — resolved
    // by ExecutiveActService, the ChamberActService sibling): delegation
    // (F-LEG-014), conversion to elected office (F-LEG-015), department
    // creation (F-LEG-016). Same posture — the institution mutation
    // happens only on adoption.
    public const KIND_EXEC_DELEGATION = 'exec_delegation';

    public const KIND_EXEC_CONVERSION = 'exec_conversion';

    public const KIND_DEPARTMENT_CREATION = 'department_creation';

    // Phase D organizations scope (PHASE_D_DESIGN_organizations §D.2):
    // CGC chartering (F-LEG-019), monopoly acquisition (F-LEG-026), CGC
    // reorganization/sale (F-LEG-027). All ride `procedural_motion`
    // (registry gap — unstated thresholds are an ordinary majority of all
    // serving, MANIFEST §8 owner ruling); the proposal kind distinguishes
    // the act.
    public const KIND_CGC_CREATION = 'cgc_creation';

    public const KIND_MONOPOLY_ACQUISITION = 'monopoly_acquisition';

    public const KIND_CGC_REORG_SALE = 'cgc_reorg_sale';

    // Phase E judiciary scope (PHASE_E_DESIGN_judiciary §B — resolved by
    // JudiciaryActService, the ExecutiveActService sibling): appointed
    // creation (F-LEG-017), conversion to elected (F-LEG-018), dissolution
    // (F-LEG-022 against a whole court). Same posture — the institution
    // mutation happens only on adoption.
    public const KIND_JUDICIARY_CREATION = 'judiciary_creation';

    public const KIND_JUDICIARY_CONVERSION = 'judiciary_conversion';

    public const KIND_JUDICIARY_DISSOLUTION = 'judiciary_dissolution';

    // Phase E challenge & law scope (PHASE_E_DESIGN_challenge_law §B.5 —
    // resolved by JudiciaryOverrideService, delegated from
    // ChamberActService::applyProposalAdoption): the legislature's
    // supermajority override of a constitutional finding (F-LEG-035, Path 2),
    // cast within the CLK-11 judicial veto window. Reuses the
    // `chamber_vote_proposal` votable type — one new proposal kind, minimal
    // blast radius (the exec_delegation precedent).
    public const KIND_JUDICIARY_OVERRIDE = 'judiciary_override';

    public const STATUS_OPEN = 'open';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'id',
        'legislature_id',
        'proposal_kind',
        'vote_id',
        'payload',
        'proposed_by_member_id',
        'status',
        'decided_at',
        'result_type',
        'result_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'proposed_by_member_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
