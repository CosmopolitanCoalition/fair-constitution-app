<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of an org document package — versions append,
 * never edit (no updated_at, no soft deletes).
 */
class OrgDocumentPackageVersion extends Model
{
    use HasUuids;

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'package_id',
        'version_no',
        'content',
        'created_by_user_id',
    ];

    protected $casts = [
        'version_no' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(OrgDocumentPackage::class, 'package_id');
    }
}
