<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * A constitutional acknowledgement of an audit-chain break (see the migration).
 * The authority (a government office, or the de-facto operator collective) grounds
 * a broken-but-real record forward; verifyChain honors it. Unacknowledged breaks
 * still fail — tamper-evidence is preserved.
 */
class AuditChainReconciliation extends Model
{
    use HasUuids, SoftDeletes;

    public const AUTHORITY_GOVERNMENT = 'government_office';

    public const AUTHORITY_OPERATOR_COLLECTIVE = 'operator_collective';

    protected $fillable = [
        'break_seq',
        'observed_prev_hash',
        'expected_prev_hash',
        'reason',
        'authority_kind',
        'acknowledged_by_user_id',
        'acknowledged_by_operator_id',
        'consent',
        'audit_seq',
        'acknowledged_at',
    ];

    protected $casts = [
        'break_seq' => 'integer',
        'audit_seq' => 'integer',
        'consent' => 'array',
        'acknowledged_at' => 'datetime',
    ];

    /**
     * The blessed-break map verifyChain consults: [break_seq => observed_prev_hash]
     * for every active acknowledgement. Guarded so verifyChain never breaks if the
     * table is absent (a pre-migration chain still verifies, just without grounding).
     *
     * @return array<int,string>
     */
    public static function blessedMap(): array
    {
        if (! Schema::hasTable('audit_chain_reconciliations')) {
            return [];
        }

        return static::query()
            ->whereNull('deleted_at')
            ->pluck('observed_prev_hash', 'break_seq')
            ->map(fn ($h) => (string) $h)
            ->all();
    }
}
