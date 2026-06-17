<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G3c) — a read-write petition: a mirror asking to become a read-write
 * peer for a jurisdiction subtree. NOT an adoption (a mirror stays authoritative
 * for nothing); this is the GOVERNED front door. Granting is decided by the
 * jurisdiction's standing government (Art. V §7 via LocalAutonomyService, G6) or
 * the de-facto operator board (G-VER), then executed by the existing
 * authority-flip + operational-bundle machinery.
 */
class ReadWriteRequest extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VOTE_OPENED = 'vote_opened';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_DENIED = 'denied';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'applicant_server_id',
        'applicant_public_key',
        'root_jurisdiction_id',
        'status',
        'autonomy_process_id',
        'note',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'resolved_at'  => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_VOTE_OPENED], true);
    }
}
