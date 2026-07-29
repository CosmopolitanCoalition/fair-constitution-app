<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A /support/report intake row (mockups-v3-wiring Phase 1; lifecycle ruling
 * §10 item 7). LOCAL operational data (not mesh-replicated, no append-only
 * trigger); `public_id` is the shareable reference the reporter is told.
 *
 * THE ROUTED SIX (ruling §10 item 7): every report is one of six subjects, each
 * routing to one place. The intake ROUTES a request — it removes nothing.
 * `abuse` routes OFF the tech-support queue to the moderation & legal floor;
 * content removal itself stays on the judicial F-SOC-003 carve-out path, never
 * this form. (Legacy conduct→abuse, legal→abuse/moderation, appeal→courts.)
 */
class SupportReport extends Model
{
    use HasPublicId, HasUuids, SoftDeletes;

    /* ---------------------------------------------------------- the routed six */
    public const CATEGORY_BUG = 'bug';

    public const CATEGORY_TRANSLATION = 'translation';

    public const CATEGORY_ACCESSIBILITY = 'accessibility';

    public const CATEGORY_CONTENT = 'content';

    public const CATEGORY_ABUSE = 'abuse';

    public const CATEGORY_IDEA = 'idea';

    public const CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_TRANSLATION,
        self::CATEGORY_ACCESSIBILITY,
        self::CATEGORY_CONTENT,
        self::CATEGORY_ABUSE,
        self::CATEGORY_IDEA,
    ];

    /* --------------------------------------------------------- routing targets */
    public const ROUTE_OPERATORS = 'operators';

    public const ROUTE_TRANSLATION = 'translation';

    public const ROUTE_MODERATION = 'moderation';

    public const ROUTE_BACKLOG = 'backlog';

    public const ROUTE_COURTS = 'courts';

    /** Where each subject routes (ruling §10 item 7 + the v3 support fixtures). */
    public const ROUTING = [
        self::CATEGORY_BUG => self::ROUTE_OPERATORS,
        self::CATEGORY_TRANSLATION => self::ROUTE_TRANSLATION,
        self::CATEGORY_ACCESSIBILITY => self::ROUTE_OPERATORS,
        self::CATEGORY_CONTENT => self::ROUTE_OPERATORS,
        self::CATEGORY_ABUSE => self::ROUTE_MODERATION,
        self::CATEGORY_IDEA => self::ROUTE_BACKLOG,
    ];

    /** The routing target for a category — operators is the safe default. */
    public static function routeFor(string $category): string
    {
        return self::ROUTING[$category] ?? self::ROUTE_OPERATORS;
    }

    /* ----------------------------------------------------------- the lifecycle */
    public const STATUS_OPEN = 'open'; // fresh / not yet triaged (the mockup's "new")

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_WONT_FIX = 'wont_fix';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_TRIAGED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_WONT_FIX,
    ];

    /** Statuses that count as still needing attention (the queue's "open" filter). */
    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_TRIAGED,
        self::STATUS_IN_PROGRESS,
    ];

    public const SEVERITIES = ['low', 'normal', 'high', 'critical'];

    protected $fillable = [
        'id',
        'public_id',
        'category',
        'subject',
        'body',
        'ref',
        'reporter_id',
        'status',
        'route_target',
        'severity',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** Abuse rides the moderation & legal floor — off the tech-support queue. */
    public function isOffQueue(): bool
    {
        return $this->route_target === self::ROUTE_MODERATION;
    }
}
