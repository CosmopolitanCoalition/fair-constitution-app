<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;

/**
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║ PROTECTED FILE — CONSTITUTIONAL REVIEW REQUIRED BEFORE MODIFICATION   ║
 * ║                                                                       ║
 * ║ This service is part of the hardened layer (CLAUDE.md "Protected     ║
 * ║ Files"). It encodes rules that no UI, admin panel, or legislative    ║
 * ║ act may change. Changes require constitutional review and must keep  ║
 * ║ the constitutional test suite green.                                 ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * Hardened-rule checks invoked by the ConstitutionalEngine before any
 * handler runs. Phase A rules:
 *
 *  - settings.bounds      — hardened min/max (or whitelist) per amendable
 *                           key. Bounds sourced from ConstitutionalDefaults
 *                           (HARD_FLOOR/HARD_CEILING), the
 *                           constitutional_settings migration defaults, and
 *                           the CLAUDE.md hard-constraints table.
 *  - seats.range          — 5–9 seats per district/voter pool (Art. II §2).
 *  - supermajority        — ceil(serving × 2/3), never below majority + 1
 *                           where majority = floor(serving/2) + 1 (Art. VII).
 *                           Denominator is ALL serving members, never those
 *                           present.
 *  - rights.automatic     — the residency forms may NEVER carry eligibility
 *                           conditions beyond jurisdictional association
 *                           (Art. I — voting and candidacy are absolute
 *                           rights of residency).
 *
 * Phase B rules (WI-B4, PHASE_B_DESIGN_schema_lifecycle §C):
 *
 *  - elections.race_structure — chamber races 5–9 seats, `single` exactly
 *                           1, at-large never above the legislature max
 *                           (Art. II §2, §8 — subdivision mandatory above
 *                           the max).
 *  - rights.automatic     — extended to candidacy: F-IND-011 and F-ELB-002
 *                           join the guard; rejection knows the single
 *                           ground 'no_residency_association' (Art. I).
 */
class ConstitutionalValidator
{
    /**
     * rights.automatic guard — forms that establish or exercise the
     * automatic rights chain (R-02 → R-03 → R-04). Filings of these forms
     * may never carry eligibility conditions beyond jurisdictional
     * association. Art. I. The constitutional test suite pins this list.
     *
     * Phase B (WI-B4, PHASE_B_DESIGN_schema_lifecycle §C) extends the
     * guard to CANDIDACY — registration and board validation are
     * rights-automatic exactly like the residency forms: association is
     * the only gate, rejection knows a single ground.
     */
    public const RIGHTS_AUTOMATIC_FORMS = [
        'F-IND-003', // Residency Declaration
        'F-IND-005', // GPS Residency Ping
        'F-IND-006', // Residency Verification Confirmation
        'F-IND-011', // Candidacy Registration (Phase B)
        'F-ELB-002', // Candidate Validation (Phase B)
    ];

    /**
     * Payload keys that would smuggle an eligibility condition into a
     * rights-automatic form. (Phase B adds the candidacy-shaped riders;
     * F-ELB-002's `rejection_reason` is deliberately NOT here — it is
     * value-checked instead: the single permissible ground.)
     */
    private const FORBIDDEN_ELIGIBILITY_KEYS = [
        'eligibility',
        'eligibility_conditions',
        'fee',
        'payment_required',
        'precondition',
        'preconditions',
        'qualification',
        'qualifications',
        'requires_identity_verification',
        'ground',
        'grounds',
        'disqualification',
        'criminal_record',
    ];

    /**
     * settings.bounds — hardened bounds per amendable key (F-LEG-031).
     *
     * 'min'/'max' = inclusive numeric bounds; 'allowed' = value whitelist.
     * Citations are the constitutional basis recorded with rejections.
     *
     * Sources: ConstitutionalDefaults::HARD_FLOOR/HARD_CEILING, the
     * create_constitutional_settings migration defaults, CLAUDE.md
     * hard-constraints + amendable-settings tables. Bounds without an
     * explicit constitutional number are Phase A sanity rails, marked
     * 'as implemented'; cross-field rules (min ≤ max, civil/judicial
     * lockstep) land with the bill machinery in Phase C.
     */
    public const SETTING_BOUNDS = [
        'election_interval_months'          => ['min' => 1, 'max' => 60, 'citation' => 'Art. II §2'],
        // Proportionality ratchet: replaceable only by a MORE proportional
        // method. Whitelist grows solely via constitutional review.
        'voting_method'                     => ['allowed' => ['stv_droop'], 'citation' => 'Art. II §2'],
        'special_election_min_days'         => ['min' => 1, 'max' => 180, 'citation' => 'Art. II §5'],
        'special_election_max_days'         => ['min' => 1, 'max' => 180, 'citation' => 'Art. II §5'],
        'legislature_min_seats'             => [
            'min'      => ConstitutionalDefaults::HARD_FLOOR,
            'max'      => ConstitutionalDefaults::HARD_CEILING,
            'citation' => 'Art. II §2',
        ],
        'legislature_max_seats'             => [
            'min'      => ConstitutionalDefaults::HARD_FLOOR,
            'max'      => ConstitutionalDefaults::HARD_CEILING,
            'citation' => 'Art. II §2',
        ],
        'legislature_sizing_law'            => ['allowed' => ['cube_root'], 'citation' => 'Art. II §2 · as implemented'],
        // Fraction must stay in (1/2, 1]; the supermajority() clamp
        // additionally guarantees the result never drops below majority+1.
        'supermajority_numerator'           => ['min' => 1, 'max' => 255, 'citation' => 'Art. VII'],
        'supermajority_denominator'         => ['min' => 2, 'max' => 255, 'citation' => 'Art. VII'],
        'max_days_between_meetings'         => ['min' => 1, 'max' => 90, 'citation' => 'Art. II §2'],
        'emergency_powers_max_days'         => ['min' => 1, 'max' => 90, 'citation' => 'Art. II §7'],
        'civil_appointment_years'           => ['min' => 1, 'max' => 10, 'citation' => 'Art. II §9'],
        'judicial_appointment_years'        => ['min' => 1, 'max' => 10, 'citation' => 'Art. IV §1'],
        'judiciary_min_judges_per_race'     => ['min' => 5, 'max' => 99, 'citation' => 'Art. IV §1'],
        // Flipping to elected requires supermajority + constituent
        // supermajority — that PROCESS gate lands in Phase C; the bounds
        // check here only constrains the value domain.
        'judiciary_is_elected'              => ['allowed' => [true, false], 'citation' => 'Art. IV §1'],
        // Raising the first-seat threshold above 100 would weaken worker
        // representation below the constitutional floor; lowering is fine.
        'worker_rep_min_employees'          => ['min' => 1, 'max' => 100, 'citation' => 'Art. III §6'],
        'worker_rep_parity_employees'       => ['min' => 1, 'max' => 2000, 'citation' => 'Art. III §6'],
        'residency_confirmation_days'       => ['min' => 1, 'max' => 365, 'citation' => 'Art. I · as implemented'],
        'initiative_petition_threshold_pct' => ['min' => 0.01, 'max' => 100, 'citation' => 'Art. II §6'],
        // Phase B (WI-B4) — the open-ballot phase settings (B-12):
        // CLK-21 finalist count X = multiplier × seats.
        'finalist_multiplier'               => ['min' => 1, 'max' => 10, 'citation' => 'Art. II §2 · as implemented'],
        'ranked_window_days'                => ['min' => 1, 'max' => 60, 'citation' => 'Art. II §2 · as implemented'],
        'approval_min_days'                 => ['min' => 1, 'max' => 365, 'citation' => 'Art. II §2 · as implemented'],
    ];

    /**
     * Engine entry point: hardened checks for a canonical form filing.
     * Throws ConstitutionalViolation (with citation) on breach; returns
     * silently when the filing is constitutionally permissible.
     */
    public function check(string $canonicalFormId, array $payload): void
    {
        $this->guardAutomaticRights($canonicalFormId, $payload);

        match ($canonicalFormId) {
            'F-LEG-031' => $this->checkSettingChange($payload),
            'F-ELB-001' => $this->checkSchedulingOrderRaces($payload),
            'F-ELB-002' => $this->checkValidationGround($payload),
            default     => null,
        };
    }

    // -------------------------------------------------------------------------
    // settings.bounds
    // -------------------------------------------------------------------------

    /**
     * F-LEG-031 Amendable Setting Change — validate key + proposed value
     * against the hardened bounds registry. Out-of-range or non-amendable
     * keys are rejected pre-commit with citation.
     */
    public function checkSettingChange(array $payload): void
    {
        $key   = $payload['setting_key'] ?? null;
        $value = $payload['value'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new ConstitutionalViolation(
                'Amendable setting change requires a setting_key.',
                'Art. VII'
            );
        }

        if (! array_key_exists($key, self::SETTING_BOUNDS)) {
            throw new ConstitutionalViolation(
                "[{$key}] is not an amendable constitutional setting.",
                'Art. VII'
            );
        }

        $bounds   = self::SETTING_BOUNDS[$key];
        $citation = $bounds['citation'];

        if (isset($bounds['allowed'])) {
            if (! in_array($value, $bounds['allowed'], true)) {
                throw new ConstitutionalViolation(
                    sprintf(
                        '%s value %s is not permitted; allowed: %s.',
                        $key,
                        json_encode($value),
                        json_encode($bounds['allowed'])
                    ),
                    $citation
                );
            }

            return;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new ConstitutionalViolation(
                "{$key} requires a numeric value, got " . gettype($value) . '.',
                $citation
            );
        }

        if ($value < $bounds['min'] || $value > $bounds['max']) {
            throw new ConstitutionalViolation(
                sprintf(
                    '%s = %s is outside the hardened range [%s, %s].',
                    $key,
                    $value,
                    $bounds['min'],
                    $bounds['max']
                ),
                $citation
            );
        }

        // Supermajority fraction must stay strictly above 1/2 and at most 1
        // (Art. VII). Companion value defaults to the constitutional 2/3
        // when the filing changes only one side of the fraction.
        if ($key === 'supermajority_numerator' || $key === 'supermajority_denominator') {
            $numerator   = $key === 'supermajority_numerator' ? (int) $value : (int) ($payload['supermajority_numerator'] ?? 2);
            $denominator = $key === 'supermajority_denominator' ? (int) $value : (int) ($payload['supermajority_denominator'] ?? 3);

            if ($denominator < 1 || $numerator * 2 <= $denominator || $numerator > $denominator) {
                throw new ConstitutionalViolation(
                    sprintf(
                        'Supermajority fraction %d/%d must lie in (1/2, 1] — it can never produce a threshold below majority + 1.',
                        $numerator,
                        $denominator
                    ),
                    'Art. VII'
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // seats.range
    // -------------------------------------------------------------------------

    /**
     * 5–9 seats per district / voter pool (Art. II §2). Throws when the
     * count falls outside the hardened band.
     */
    public function assertSeatsInRange(int $seats): void
    {
        if ($seats < ConstitutionalDefaults::HARD_FLOOR || $seats > ConstitutionalDefaults::HARD_CEILING) {
            throw new ConstitutionalViolation(
                sprintf(
                    '%d seats is outside the constitutional band [%d, %d] — above %d the body must be subdivided.',
                    $seats,
                    ConstitutionalDefaults::HARD_FLOOR,
                    ConstitutionalDefaults::HARD_CEILING,
                    ConstitutionalDefaults::HARD_CEILING
                ),
                'Art. II §2'
            );
        }
    }

    // -------------------------------------------------------------------------
    // elections.race_structure (Phase B / WI-B4 — Art. II §2, §8)
    // -------------------------------------------------------------------------

    /**
     * elections.race_structure — design §B.4 ruling, enforced wherever a
     * race is created (F-ELB-001 handler with the resolved max; payload
     * pre-check below with the hardened ceiling):
     *
     *  - chamber races (type_a / type_b) carry 5–9 seats (hardened band);
     *  - `single` races carry exactly 1 seat (individual-executive
     *    exception — fired by Phase D);
     *  - an AT-LARGE race (district_id NULL) may never exceed the
     *    legislature's max seats: above the max, subdivision into a
     *    district map is MANDATORY (Art. II §8) — a 10+-seat at-large
     *    race is unconstitutional on its face.
     */
    public function checkRaceStructure(string $seatKind, int $seats, ?string $districtId, ?int $maxSeats = null): void
    {
        // The amendable max can never exceed the hardened ceiling.
        $max = min($maxSeats ?? ConstitutionalDefaults::HARD_CEILING, ConstitutionalDefaults::HARD_CEILING);

        if ($seatKind === 'single') {
            if ($seats !== 1) {
                throw new ConstitutionalViolation(
                    "A 'single' race elects exactly one seat (got {$seats}) — the individual-executive exception.",
                    'Art. III §2'
                );
            }

            return;
        }

        if (! in_array($seatKind, ['type_a', 'type_b'], true)) {
            throw new ConstitutionalViolation(
                "Unknown race seat_kind [{$seatKind}].",
                'Art. II §2'
            );
        }

        $this->assertSeatsInRange($seats);

        if ($districtId === null && $seats > $max) {
            throw new ConstitutionalViolation(
                sprintf(
                    'An at-large race may not carry %d seats (max %d) — above the maximum, subdivision '
                    . 'into separate voter pools is mandatory.',
                    $seats,
                    $max
                ),
                'Art. II §8'
            );
        }
    }

    /**
     * F-ELB-001 payload pre-check: any explicit race list must satisfy
     * the race-structure rule at the hardened ceiling. The handler
     * re-validates with the per-jurisdiction resolved max.
     */
    private function checkSchedulingOrderRaces(array $payload): void
    {
        $races = $payload['races'] ?? null;

        if (! is_array($races)) {
            return;
        }

        foreach ($races as $race) {
            $race = (array) $race;

            $this->checkRaceStructure(
                (string) ($race['seat_kind'] ?? 'type_a'),
                (int) ($race['seats'] ?? 0),
                isset($race['district_id']) ? (string) $race['district_id'] : null,
            );
        }
    }

    /**
     * F-ELB-002 — Art. I: the ONLY permissible rejection ground is
     * 'no_residency_association'. A filing that names any other ground is
     * rejected pre-commit (the DB CHECK is the last line; this is the
     * first). The handler additionally rejects a 'reject' decision against
     * a candidate whose residency IS satisfied.
     */
    private function checkValidationGround(array $payload): void
    {
        $ground = $payload['rejection_reason'] ?? null;

        if (($payload['decision'] ?? null) === 'reject'
            && $ground !== null
            && $ground !== 'no_residency_association') {
            throw new ConstitutionalViolation(
                sprintf(
                    'Candidacy rejection knows a single permissible ground — no_residency_association. '
                    . 'Ground %s is unconstitutional (candidacy is an absolute right of residency).',
                    json_encode($ground)
                ),
                'Art. I'
            );
        }
    }

    // -------------------------------------------------------------------------
    // supermajority / quorum (Art. VII, Art. II §2)
    // -------------------------------------------------------------------------

    /**
     * Supermajority threshold over ALL serving members (never just those
     * present): ceil(serving × numerator/denominator), clamped so it can
     * never fall below majority + 1, i.e. floor(serving/2) + 2.
     *
     *   supermajority(8) = 6   supermajority(9) = 6   supermajority(6) = 5
     */
    public static function supermajority(int $serving, int $numerator = 2, int $denominator = 3): int
    {
        $fraction      = intdiv($serving * $numerator + $denominator - 1, $denominator); // integer ceil
        $majorityPlus1 = intdiv($serving, 2) + 2;

        return max($fraction, $majorityPlus1);
    }

    /**
     * Quorum: majority of ALL serving members = floor(serving/2) + 1
     * (Art. II §2). Vacancies stay in the denominator.
     */
    public static function quorum(int $serving): int
    {
        return intdiv($serving, 2) + 1;
    }

    // -------------------------------------------------------------------------
    // rights.automatic
    // -------------------------------------------------------------------------

    /**
     * Art. I — residency is the ONLY gate on voting and candidacy. Any
     * attempt to attach eligibility conditions to the residency forms is a
     * constitutional violation, regardless of who files.
     */
    private function guardAutomaticRights(string $canonicalFormId, array $payload): void
    {
        if (! in_array($canonicalFormId, self::RIGHTS_AUTOMATIC_FORMS, true)) {
            return;
        }

        foreach (array_keys($payload) as $key) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_ELIGIBILITY_KEYS, true)) {
                throw new ConstitutionalViolation(
                    sprintf(
                        '%s may never carry eligibility conditions beyond jurisdictional association (offending key: %s).',
                        $canonicalFormId,
                        $key
                    ),
                    'Art. I'
                );
            }
        }
    }
}
