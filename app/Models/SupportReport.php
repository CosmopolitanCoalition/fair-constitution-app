<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A /support/report intake row (mockups-v3-wiring Phase 1) — a bug, a
 * question, or something that needs review. LOCAL operational data (not
 * mesh-replicated, no append-only trigger); `public_id` is the shareable
 * reference the reporter is told. Conduct/legal categories only ROUTE the
 * request — content removal stays on the judicial F-SOC-003 carve-out path.
 */
class SupportReport extends Model
{
    use HasPublicId, HasUuids, SoftDeletes;

    public const CATEGORY_BUG = 'bug';

    public const CATEGORY_QUESTION = 'question';

    public const CATEGORY_CONDUCT = 'conduct';

    public const CATEGORY_LEGAL = 'legal';

    public const CATEGORY_APPEAL = 'appeal';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_QUESTION,
        self::CATEGORY_CONDUCT,
        self::CATEGORY_LEGAL,
        self::CATEGORY_APPEAL,
        self::CATEGORY_OTHER,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_TRIAGED,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'id',
        'public_id',
        'category',
        'body',
        'ref',
        'reporter_id',
        'status',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
