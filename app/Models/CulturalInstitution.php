<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recognized Cultural Institution of State (F-LEG-028, Art. V §2). Honour on
 * the public record — NO legislative/executive/judicial powers.
 */
class CulturalInstitution extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_RECOGNIZED = 'recognized';

    public const STATUS_DISSOLVED = 'dissolved';

    protected $fillable = [
        'id', 'jurisdiction_id', 'legislature_id', 'name', 'description',
        'recognition_vote_id', 'status', 'record_id',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}
