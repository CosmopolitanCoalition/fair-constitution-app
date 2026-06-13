<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One IRREVERSIBLE public-domain dedication (Art. III §5 — hard
 * constraint: CGC intellectual property is always public domain, never
 * privatized).
 *
 * Append-only at every layer (pinned by tests/Constitutional/
 * CgcIpPublicDomainTest):
 *  - the table has a BEFORE UPDATE/DELETE trigger that raises;
 *  - UPDATE/DELETE privileges are revoked from the app role;
 *  - status carries a single-value CHECK ('public_domain') — privatize
 *    is unrepresentable;
 *  - THIS MODEL throws on any update/delete path (below) and
 *    CgcIpRegisterService::dedicate() is the only writer (source-scanned).
 *
 * PK is the dedication-order `seq` (bigint identity, DB-assigned); `id`
 * is the cross-instance uuid (DB default). No updated_at, no deleted_at.
 */
class CgcIpRegisterEntry extends Model
{
    protected $table = 'cgc_ip_register';

    protected $primaryKey = 'seq';

    public $incrementing = true;

    protected $keyType = 'int';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const KINDS = [
        'software', 'patentable_invention', 'copyrightable_work',
        'design', 'data', 'process', 'other',
    ];

    public const STATUS_PUBLIC_DOMAIN = 'public_domain';

    protected $fillable = [
        'organization_id',
        'asset',
        'kind',
        'description',
        'status',
        'dedicated_via_form',
        'dedicated_by_user_id',
        'published_record_id',
        'audit_seq',
        'published_at',
    ];

    protected $casts = [
        'audit_seq' => 'integer',
        'published_at' => 'datetime',
    ];

    /** Dedications are irreversible — no update path exists. Art. III §5. */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        throw new RuntimeException(
            'cgc_ip_register entries are irreversible — no write path may modify a public-domain dedication (Art. III §5).'
        );
    }

    /** Dedications are irreversible — no delete path exists. Art. III §5. */
    public function delete()
    {
        throw new RuntimeException(
            'cgc_ip_register entries are irreversible — no write path may delete a public-domain dedication (Art. III §5).'
        );
    }

    /** forceDelete is the same irreversible wall — it raises directly (it
     *  must not even textually call ->delete(): the register's write-surface
     *  pin source-scans the allowed files for any ->delete( token). */
    public function forceDelete()
    {
        throw new RuntimeException(
            'cgc_ip_register entries are irreversible — no write path may delete a public-domain dedication (Art. III §5).'
        );
    }
}
