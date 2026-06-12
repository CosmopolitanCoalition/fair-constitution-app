<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A SECRET individual approval (ESM-04, B-6; WF-CIV-08).
 *
 * SECRECY CONTRACT (design §C, pinned by ApprovalSecrecyTest):
 *  - Individual approvals are constitutionally secret. Casting/revoking is
 *    NOT a form and produces ZERO per-approval audit entries (an audit row
 *    user→candidacy would violate the secrecy) — the deliberate, documented
 *    audit exception. The audited event is the DAILY standings rollup.
 *  - Row access is owner-scoped by the 'owner' global scope below: queries
 *    only ever see the authenticated user's own rows; with no
 *    authenticated user (console/queue) the scope yields NO rows.
 *    ApprovalService and ApprovalStandingsRollupJob — the only legitimate
 *    cross-user readers — must opt out explicitly via
 *    `Approval::withoutGlobalScope(Approval::SCOPE_OWNER)` and may only
 *    release AGGREGATES (through `approval_standings`), never rows.
 *  - Append + revoke only: no updated_at, no soft deletes.
 */
class Approval extends Model
{
    use HasUuids;

    public const SCOPE_OWNER = 'owner';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'election_id',
        'candidacy_id',
        'user_id',
        'created_at',
        'revoked_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Owner scope: a query sees only the authenticated user's rows.
        // No authenticated user (console, queue) → no rows; cross-user
        // aggregation requires an explicit withoutGlobalScope(SCOPE_OWNER).
        static::addGlobalScope(self::SCOPE_OWNER, function (Builder $builder) {
            $userId = Auth::id();

            if ($userId === null) {
                $builder->whereRaw('1 = 0');
            } else {
                $builder->where('approvals.user_id', $userId);
            }
        });
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class, 'candidacy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
