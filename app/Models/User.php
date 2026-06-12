<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * ESM-01 Individual — account-side states only (`status`: registered |
 * identity_verified | deceased | closed). Residency states are derived
 * from residency claims (WI-5), and roles are always derived, never
 * stored (Art. I).
 *
 * Rows are created through ConstitutionalEngine::file('F-IND-001', …) so
 * every registration is audit-chained; SetupController::createFounder is
 * the one pre-engine exception (the founder account bootstraps the wizard).
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
        'status',
        'identity_verified_at',
        'identity_verified_via',
        'terms_accepted_at',
        'languages',
        'timezone',
        'locale',
        'comm_prefs',
        'home_server_id',
        'is_operator',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'identity_verified_at' => 'datetime',
            'terms_accepted_at'    => 'datetime',
            'languages'            => 'array',
            'comm_prefs'           => 'array',
            'is_operator'          => 'boolean',
            'password'             => 'hashed',
        ];
    }
}
