<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — the laws an opinion cites/interprets ("opinions are linked
 * to every law they interpret"). `law_version_no` pins the opinion to the
 * law's text AS IT STOOD. Commentary only — never an edit (the Art. IV §5
 * remedy is the F-JDG-006 sibling path).
 */
class OpinionLawLink extends Model
{
    use HasUuids, SoftDeletes;

    public const RELATION_CITES = 'cites';

    public const RELATION_INTERPRETS = 'interprets';

    public const RELATION_DISTINGUISHES = 'distinguishes';

    public const RELATION_APPLIES = 'applies';

    protected $fillable = [
        'id',
        'opinion_id',
        'law_id',
        'law_version_no',
        'relation',
        'note',
    ];

    protected $casts = [
        'law_version_no' => 'integer',
    ];

    public function opinion(): BelongsTo
    {
        return $this->belongsTo(Opinion::class, 'opinion_id');
    }

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }
}
