<?php

namespace App\Services\Federation;

use App\Models\ReadWriteRequest;
use App\Services\AuditService;
use Illuminate\Support\Collection;

/**
 * Phase G (G3c) — the read-write request INTAKE (host side). A mirror submits a
 * petition to become a read-write peer for a jurisdiction subtree; the host
 * operator sees it and either denies it or routes it to the governed grant. The
 * intake is OFF the mirror-admission path (a mirror stays authoritative for
 * nothing until a grant flips authority).
 *
 * SCOPE: this service is the request + status ledger. The GRANT itself — the
 * Art. V §7 dual-supermajority vote (LocalAutonomyService, G6) or the de-facto
 * operator-board consent (G-VER), then AuthorityFlipService + the sealed
 * operational bundle — is finalized in G-VER and rides the EXISTING flip
 * machinery (FederationFlipExportCommand already performs the flip today).
 */
class ReadWriteRequestService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Record (idempotently) a read-write petition from a mirror. Returns the open
     * petition if one already exists for this (applicant, jurisdiction).
     */
    public function submit(
        string $applicantServerId,
        ?string $applicantPublicKey,
        string $rootJurisdictionId,
        ?string $note = null,
    ): ReadWriteRequest {
        $existing = ReadWriteRequest::query()
            ->where('applicant_server_id', $applicantServerId)
            ->where('root_jurisdiction_id', $rootJurisdictionId)
            ->whereIn('status', [ReadWriteRequest::STATUS_SUBMITTED, ReadWriteRequest::STATUS_VOTE_OPENED])
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $request = ReadWriteRequest::create([
            'applicant_server_id'  => $applicantServerId,
            'applicant_public_key' => $applicantPublicKey,
            'root_jurisdiction_id' => $rootJurisdictionId,
            'status'               => ReadWriteRequest::STATUS_SUBMITTED,
            'note'                 => $note,
            'submitted_at'         => now(),
        ]);

        // A government receiving a read-write petition is a public-record act (Art. II §2).
        $this->audit->append('federation', 'rw_request.submitted', [
            'request_id'           => $request->id,
            'applicant_server_id'  => $applicantServerId,
            'root_jurisdiction_id' => $rootJurisdictionId,
        ], 'WF-JUR-07', null, $rootJurisdictionId);

        return $request;
    }

    /** @return Collection<int,ReadWriteRequest> */
    public function pending(): Collection
    {
        return ReadWriteRequest::query()
            ->where('status', ReadWriteRequest::STATUS_SUBMITTED)
            ->orderBy('submitted_at')
            ->get();
    }

    /** Deny a petition (the one resolution G3c owns; grant is the governed G-VER path). */
    public function deny(ReadWriteRequest $request): void
    {
        if (! $request->isPending()) {
            return;
        }

        $request->status = ReadWriteRequest::STATUS_DENIED;
        $request->resolved_at = now();
        $request->save();

        $this->audit->append('federation', 'rw_request.denied', [
            'request_id'           => $request->id,
            'root_jurisdiction_id' => $request->root_jurisdiction_id,
        ], 'WF-JUR-07', null, $request->root_jurisdiction_id);
    }
}
