<?php

namespace App\Services\Economy;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Economy\Currency;
use App\Models\Jurisdiction;

/**
 * PROTECTED — Phase L slice L-2. The only writer of `currencies`.
 *
 * Art. V §5 (verbatim): "The most encompassing Jurisdiction of A Fair
 * Constitution reserves the power to produce and regulate currency, determine
 * its worth, and define standards for measurements necessary for regulating
 * industry and commerce."
 *
 * That reservation is HARDENED here: define() rejects any issuer that is not
 * the root jurisdiction, pre-commit, with the citation. It is not a setting,
 * not a permission, and not something a sub-jurisdiction can be granted — a
 * province minting its own money is the one thing this clause forbids
 * outright, and no legislative act can authorise it.
 *
 * What IS amendable: the currency's monetary levers (issuance rate, inflation
 * target) live in constitutional_settings behind the dual door (slice L-1).
 * What is NOT amendable through F-LEG-031: the currency's identity — name,
 * code, symbol — which changes through F-LEG-040, root-only, because free
 * text cannot satisfy the SETTING_BOUNDS contract and because renaming the
 * money is a constitutive act rather than a policy dial.
 */
class CurrencyService
{
    /**
     * Define the instance's currency. Root jurisdiction only.
     *
     * @param  string|null  $actId  the enacting F-LEG-040 act; NULL only for
     *                              the founding currency written at setup,
     *                              which predates any legislature.
     */
    public function define(
        Jurisdiction $issuer,
        string $name,
        string $code,
        string $symbol,
        string $unitKind = Currency::KIND_ABSTRACT,
        int $precision = 2,
        ?string $worthBasis = null,
        ?array $subdivisions = null,
        ?string $actId = null,
    ): Currency {
        $this->assertRoot($issuer);

        return Currency::create([
            'jurisdiction_id'   => $issuer->id,
            'name'              => $name,
            'code'              => $code,
            'symbol'            => $symbol,
            'unit_kind'         => $unitKind,
            'precision'         => $precision,
            'worth_basis'       => $worthBasis,
            'subdivisions'      => $subdivisions,
            'created_by_act_id' => $actId,
        ]);
    }

    /**
     * Art. V §5 — currency is reserved to the most encompassing jurisdiction.
     * A jurisdiction with a parent is, by definition, not the most
     * encompassing one.
     */
    public function assertRoot(Jurisdiction $issuer): void
    {
        if ($issuer->parent_id !== null) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Currency is reserved to the most encompassing Jurisdiction; [%s] has a parent and may not issue one.',
                    $issuer->slug ?? $issuer->id
                ),
                'Art. V §5'
            );
        }
    }
}
