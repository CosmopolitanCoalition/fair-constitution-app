<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase K-3 (K3-I) — the immutable legal-compliance trail (M-5). One row per physical-law removal of
 * illegal content (CSAM, a specific court order, a true threat), authorized by an OPERATOR (key-
 * possession, operator plane, NOT a constitutional office). APPEND-ONLY — no soft-deletes (it is the
 * physical-law evidence record). legal_basis is a CLOSED enum (a viewpoint basis is unrepresentable);
 * matched_list_source records WHICH hash list matched, NEVER the hash/locator (republishable harm).
 * referral_record_id points at the disclosure referral to the seated constitutional actors (M-5 flips
 * to BOTH — the operator keeps the physical action + must hand the case to the in-game justice).
 */
class LegalComplianceRemoval extends Model
{
    use HasUuids;

    public const BASIS_CSAM_HASHMATCH      = 'csam_hashmatch';        // mechanical; the only ACTION_PURGE basis
    public const BASIS_COURT_ORDER         = 'court_order_specific';  // a cited specific external order
    public const BASIS_TRUE_THREAT         = 'true_threat';           // narrowly construed, cited basis

    // The physical-removal honesty states (K3-N P1) — never report 'done' while the bytes remain.
    public const PHYSICAL_DEFERRED = 'deferred'; // redaction landed; the byte-DELETE is not yet done
    public const PHYSICAL_DONE     = 'done';     // homeserver physical action completed
    public const PHYSICAL_FAILED   = 'failed';   // homeserver unreachable / admin DELETE errored

    protected $fillable = [
        'id',
        'matrix_event_id',
        'matrix_room_id',
        'operator_account_id',
        'legal_basis',
        'action',
        'physical_removal_status',
        'statutory_citation',
        'matched_list_source',
        'public_records_id',
        'jurisdiction_id',
        'is_seated_at_time',
        'referral_record_id',
    ];

    protected $casts = [
        'is_seated_at_time' => 'boolean',
    ];
}
