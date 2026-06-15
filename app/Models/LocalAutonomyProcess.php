<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An earned-autonomy promotion (Phase G, G6). A jurisdiction with a SEATED
 * government seeks authoritative read/write for its own subtree — GRANTED by the
 * current authoritative (parent) government via a dual ratification:
 *   • a SUPERMAJORITY of the PROMOTING jurisdiction's civic population, AND
 *   • the parent's `local_autonomy` MultiJurisdictionVote (the granting leg).
 * The authority flip happens only when BOTH pass — never unilateral.
 */
class LocalAutonomyProcess extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id',
        'promoting_jurisdiction_id',
        'promoting_legislature_id',
        'parent_jurisdiction_id',
        'gaining_server_id',
        'gaining_cluster_id',
        'parent_process_id',
        'promoting_supermajority_met',
        'status',
        'resulting_authoritative_server_id',
        'subtree_size',
    ];

    protected $casts = [
        'promoting_supermajority_met' => 'boolean',
        'subtree_size' => 'integer',
    ];

    public function parentProcess(): BelongsTo
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'parent_process_id');
    }
}
