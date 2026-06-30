<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-3 — an appservice-created Matrix room or Space, bound to a CGA entity. The
 * (entity_type, entity_id, space_type) partial-unique is the topology reconciler's idempotency
 * key; a closed-object room is tombstoned (tombstoned_at), never deleted. room_version is stored
 * from the LIVE m.room_versions capability — never hardcoded (public rooms require v12).
 */
class MatrixRoom extends Model
{
    use HasUuids, SoftDeletes;

    public const ROOM_SPACE        = 'm.space';
    public const ROOM_COMMONS      = 'commons';      // #square / #halls / per-object public rooms
    public const ROOM_ORG_PUBLIC   = 'org_public';
    public const ROOM_ORG_PRIVATE  = 'org_private';
    public const ROOM_INSTITUTION  = 'institution';
    public const ROOM_USER_PRIVATE = 'user_private'; // an ad-hoc user-owned private room/call (off the civic plane)

    public const ENTITY_JURISDICTION        = 'jurisdiction';
    public const ENTITY_ORGANIZATION        = 'organization';
    public const ENTITY_LEGISLATURE         = 'legislature';
    public const ENTITY_EXECUTIVE           = 'executive';
    public const ENTITY_JUDICIARY           = 'judiciary';
    public const ENTITY_BOARD               = 'board';
    public const ENTITY_BILL                = 'bill';
    public const ENTITY_REFERENDUM_QUESTION = 'referendum_question';
    public const ENTITY_PETITION            = 'petition';
    public const ENTITY_COMMITTEE_MEETING   = 'committee_meeting';
    public const ENTITY_CANDIDACY           = 'candidacy';
    public const ENTITY_SOCIAL_SPACE        = 'social_space'; // a user-owned private room binds to its SocialSpace id

    public const SPACE_PUBLIC_SQUARE = 'public_square';
    public const SPACE_HALLS         = 'halls';

    protected $fillable = [
        'id',
        'matrix_room_id',
        'matrix_alias',
        'room_type',
        'room_version',
        'entity_type',
        'entity_id',
        'space_type',
        'is_public',
        'is_encrypted',
        'is_seated',
        'is_activated',
        'tombstoned_at',
    ];

    protected $casts = [
        'is_public'     => 'boolean',
        'is_encrypted'  => 'boolean',
        'is_seated'     => 'boolean',
        'is_activated'  => 'boolean',
        'tombstoned_at' => 'datetime',
    ];
}
