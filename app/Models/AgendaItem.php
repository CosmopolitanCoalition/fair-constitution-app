<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single agenda item on a committee meeting or legislature session
 * (agendable_type / agendable_id — the two agenda-JSONB hosts). Promotes the
 * flat agenda blob into a durable per-item row carrying its own order, kind,
 * optional governance-object link, locked flag, and disposition (Wave 5 ⑤).
 *
 * The .agenda JSONB on the host stays the input surface; these rows are the
 * per-item state the room reads and advance() walks: pending → in_progress →
 * done. A LOCKED item is the engine-composed head (emergency powers /
 * constitutional matters, Art. II §2/§7) — never reordered or disposed by the
 * chair.
 */
class AgendaItem extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE        = 'done';
    public const STATUS_DEFERRED    = 'deferred';

    public const HOST_COMMITTEE_MEETING    = 'committee_meetings';
    public const HOST_LEGISLATURE_SESSION  = 'legislature_sessions';

    protected $fillable = [
        'id',
        'agendable_type',
        'agendable_id',
        'position',
        'kind',
        'title',
        'ref_type',
        'ref_id',
        'locked',
        'status',
        'disposition',
        'taken_up_at',
        'disposed_at',
    ];

    protected $casts = [
        'position'    => 'integer',
        'locked'      => 'boolean',
        'taken_up_at' => 'datetime',
        'disposed_at' => 'datetime',
    ];
}
