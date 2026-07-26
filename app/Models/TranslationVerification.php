<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person's verdict on one translated string.
 *
 * The machine catalogs under resources/js/i18n are regenerable at any time.
 * These rows are not: each one is a person who reads the language saying
 * whether a machine got it right. Everything else in the translation plane can
 * be rebuilt from source; this cannot.
 */
class TranslationVerification extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /** A person either accepted the machine's wording, replaced it, or rejected it. */
    public const VERDICT_APPROVED = 'approved';
    public const VERDICT_EDITED   = 'edited';
    public const VERDICT_REJECTED = 'rejected';

    public const VERDICTS = [self::VERDICT_APPROVED, self::VERDICT_EDITED, self::VERDICT_REJECTED];

    /**
     * How many people must agree before a string counts as verified.
     *
     * Three, from mockups/v3/translation (fixtures-translation.js: `needed: 3`).
     * Not one: a single reader's opinion settling the language of a
     * constitution is the same shape of mistake the rest of this system spends
     * so much effort avoiding.
     */
    public const QUORUM = 3;

    protected $fillable = [
        'locale', 'namespace', 'message_key', 'source_hash',
        'verdict', 'machine_text', 'verified_text', 'note',
        'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Verdicts that count toward quorum. A rejection is a verdict, not agreement. */
    public function scopeCounting($query)
    {
        return $query->whereIn('verdict', [self::VERDICT_APPROVED, self::VERDICT_EDITED]);
    }

    /**
     * Verdicts still bound to the English they were made against.
     *
     * A source string that changed since the verdict was recorded takes its
     * approval with it: nobody read the new wording, and carrying the old
     * approval forward would misreport who checked what.
     */
    public function scopeForSource($query, string $sourceHash)
    {
        return $query->where('source_hash', $sourceHash);
    }
}
