<?php

namespace App\Domain\Forms\Contracts;

/**
 * Narrow seam between the chamber vote engine (votes-laws scope) and the
 * committees substrate (chamber-ops scope) — PHASE_C_DESIGN_votes_laws
 * §B "Committee reuse". ChamberVoteService consults this contract for
 * committee-body votes so it carries no hard dependency on the sibling
 * committee tables landing first (NoopCommitteeRoster is bound until
 * they do — the Phase B NoopBallotBoxDelegate pattern).
 *
 * Lanes mirror the chamber: per-kind ('type_a'/'type_b') iff the parent
 * chamber is bicameral (q-ledger #q7 applies at committee stage), else
 * one 'all' lane.
 */
interface CommitteeRoster
{
    /**
     * Lane composition of one committee's SEATED members.
     *
     * @return array{legislature_id: ?string, jurisdiction_id: ?string, lanes: array<string, int>}
     *         lanes: lane => serving count; empty lanes array = committee
     *         unknown / substrate not wired.
     */
    public function laneCounts(string $committeeId): array;

    /** Whether the member currently holds a seat on the committee. */
    public function isMember(string $committeeId, string $memberId): bool;

    /** The member's lane on this committee ('all' | 'type_a' | 'type_b'), null when not seated. */
    public function laneOf(string $committeeId, string $memberId): ?string;
}
