<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Self-managed org document package (D-O5). The `key` may never collide
 * with a constitutional form ID (engine rule — internal forms live above
 * the constitutional floor).
 */
class OrgDocumentPackage extends Model
{
    use HasUuids, SoftDeletes;

    public const KINDS = ['charter', 'bylaws', 'hr_policy', 'compensation_policy', 'custom_form', 'other'];

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'id',
        'organization_id',
        'key',
        'name',
        'kind',
        'status',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OrgDocumentPackageVersion::class, 'package_id');
    }
}
