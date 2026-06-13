<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Article IV §4 — one docketed filing (motion / evidence / brief / order /
 * ruling). APPEND-ONLY: the docket is immutable ("nothing argued in open
 * court is ever sealed retroactively"). Judge rulings APPEND a follow-up row,
 * never edit the original; corrections append.
 *
 * Append-only at every layer (the public_records / cgc_ip_register posture):
 *  - the table has a BEFORE UPDATE/DELETE trigger that raises;
 *  - THIS MODEL throws on any update/delete path (below) — the only writer is
 *    CaseFilingService::docket().
 *
 * PK is the publication-order `seq` (bigint identity); `id` is the
 * cross-instance uuid (DB default). No updated_at, no deleted_at.
 */
class CaseFiling extends Model
{
    protected $table = 'case_filings';

    protected $primaryKey = 'seq';

    public $incrementing = true;

    protected $keyType = 'int';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const KIND_CASE_FILING = 'case_filing';

    public const KIND_MOTION = 'motion';

    public const KIND_EVIDENCE = 'evidence';

    public const KIND_BRIEF = 'brief';

    public const KIND_ORDER = 'order';

    public const KIND_PANEL_ASSIGNMENT = 'panel_assignment';

    public const KIND_JURY_ORDER = 'jury_order';

    public const KIND_OPINION = 'opinion';

    public const KIND_SENTENCE = 'sentence';

    public const KIND_WARRANT = 'warrant';

    public const KIND_RULING = 'ruling';

    public const RULING_GRANTED = 'granted';

    public const RULING_DENIED = 'denied';

    public const RULING_ADMITTED = 'admitted';

    public const RULING_EXCLUDED = 'excluded';

    protected $fillable = [
        'case_id',
        'filing_form',
        'filing_kind',
        'filed_by_user_id',
        'filed_by_role',
        'advocate_id',
        'title',
        'body',
        'ruling',
        'ruling_reason',
        'accepted_at_state',
        'record_id',
        'audit_seq',
    ];

    protected $casts = [
        'audit_seq' => 'integer',
    ];

    public function case()
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function advocate()
    {
        return $this->belongsTo(Advocate::class, 'advocate_id');
    }

    /** The docket is append-only — no update path exists (Art. IV §4). */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        throw new RuntimeException(
            'case_filings is an append-only docket — a docketed filing is never edited (Art. IV §4).'
        );
    }

    /** The docket is append-only — no delete path exists (Art. IV §4). */
    public function delete()
    {
        throw new RuntimeException(
            'case_filings is an append-only docket — a docketed filing is never deleted (Art. IV §4).'
        );
    }
}
