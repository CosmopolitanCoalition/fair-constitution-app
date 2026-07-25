<?php

namespace App\Models\Economy;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Jurisdiction;

/**
 * The unit of account (Phase L, slice L-2).
 *
 * Art. V §5 — "The most encompassing Jurisdiction of A Fair Constitution
 * reserves the power to produce and regulate currency, determine its worth,
 * and define standards for measurements necessary for regulating industry and
 * commerce." A currency whose issuer is not the ROOT jurisdiction is
 * unconstitutional; CurrencyService rejects it pre-commit and
 * LedgerIntegrityTest pins that.
 *
 * The app is currency-AGNOSTIC by design: unit_kind spans abstract, fiat,
 * commodity, social_credit and external_peg, and the engine takes no position
 * on which is correct. That is a legislature's question, not a schema's.
 */
class Currency extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_ABSTRACT      = 'abstract';
    public const KIND_FIAT          = 'fiat';
    public const KIND_COMMODITY     = 'commodity';
    public const KIND_SOCIAL_CREDIT = 'social_credit';
    public const KIND_EXTERNAL_PEG  = 'external_peg';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'name',
        'code',
        'symbol',
        'precision',
        'unit_kind',
        'worth_basis',
        'subdivisions',
        'created_by_act_id',
    ];

    protected $casts = [
        'precision'    => 'integer',
        'subdivisions' => 'array',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}
