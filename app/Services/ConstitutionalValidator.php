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
 *
 * Phase C rules (PHASE_C_DESIGN_chamber_ops §G.3 — additive pure asserts;
 * supermajority()/quorum() remain THE only threshold functions and are
 * untouched):
 *
 *  - speaker.tiebreak_only — the Speaker remains a serving member (every
 *                           denominator) but casts ONLY when the vote is
 *                           in tie state: every other serving member's
 *                           cast resolved AND yes == no (Art. II §3).
 *                           assertSpeakerTieState() is the rule; the
 *                           chamber vote engine calls it before any
 *                           F-SPK-004 cast records.
 *  - removal.presider     — a removal proceeding is never presided over
 *                           by its own subject (Art. II §3); the chamber
 *                           designates a substitute presider for the
 *                           Speaker's own case.
 *  - committees.kind_split — bicameral committees mirror the chamber-kind
 *                           ratio: split present, totals the seat count,
 *                           each kind ≥ 1 whenever seats ≥ 2 (Art. V §3 —
 *                           per-kind dual agreement must never be vacuous
 *                           at committee stage, q-ledger #q7).
 *  - vacancy.declarer     — F-LEG-036: the Speaker or the system may
 *                           declare any current seat vacant; a plain
 *                           legislator only their OWN (resignation) —
 *                           declaration is never a weapon (Art. II §5 ·
 *                           as implemented).
 *  - session.agenda_order — the locked agenda head (emergency review,
 *                           constitutional matters) is immutable and
 *                           precedes all general business (Art. II §2).
 *
 * Phase C batch 2 rules (PHASE_C_DESIGN_votes_laws §D/§F — additive):
 *
 *  - emergency.civic_process_shield — emergency powers cannot disrupt any
 *                           civic process (Art. II §7). Three mechanisms:
 *                           (1) structural absence — no API anywhere can
 *                           defer an election, session, ballot, or
 *                           residency process (architecture-pinned);
 *                           (2) protected-form invariance — filings of
 *                           EMERGENCY_PROTECTED_FORMS may never carry
 *                           emergency_power_id / enabling_* keys;
 *                           (3) forward rule — only forms declared in
 *                           EMERGENCY_ENABLED_FORMS may cite a power as
 *                           enabling authority (empty in Phase C; Phase D
 *                           executive orders register there).
 *  - referendum.shield    — CLK-19 (Art. II §6): an act passed by
 *                           population supermajority is immune to
 *                           legislative modification/repeal until the
 *                           next general election certifies. Evaluated at
 *                           FILING time (F-LEG-034) against
 *                           laws.shield_expires_with_election_id — no
 *                           timer exists; the gate IS the clock.
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
     * emergency.civic_process_shield (Art. II §7) — the forms an emergency
     * power may NEVER touch: the whole individual-rights surface
     * (registration, residency, pings, ballots, petitions, candidacy),
     * candidates, the election board's machinery, session calling/quorum,
     * attendance/votes/vacancies, and the judiciary (Phase E). No handler
     * for these forms may read emergency_powers state, and no filing of
     * them may carry an emergency citation. This list may only ever GROW,
     * under constitutional review; EmergencyCeilingTest pins it.
     */
    public const EMERGENCY_PROTECTED_FORMS = [
        // Individuals — the rights surface (Art. I + Art. II).
        'F-IND-001', 'F-IND-002', 'F-IND-003', 'F-IND-004', 'F-IND-005', 'F-IND-006',
        'F-IND-007', 'F-IND-008', 'F-IND-009', 'F-IND-010', 'F-IND-011', 'F-IND-012',
        'F-IND-013', 'F-IND-014', 'F-IND-015', 'F-IND-016', 'F-IND-017',
        // Candidates.
        'F-CAN-001', 'F-CAN-002', 'F-CAN-003',
        // Election board machinery — elections cannot be touched.
        'F-ELB-001', 'F-ELB-002', 'F-ELB-003', 'F-ELB-004', 'F-ELB-005', 'F-ELB-006',
        // Sessions cannot be suspended; quorum cannot be gamed.
        'F-SPK-001', 'F-SPK-003',
        // Attendance, votes, vacancy machinery.
        'F-LEG-002', 'F-LEG-004', 'F-LEG-005', 'F-LEG-036',
        // Courts (Phase E) — protected from day one.
        'F-JDG-001', 'F-JDG-002', 'F-JDG-003', 'F-JDG-004', 'F-JDG-005',
        'F-JDG-006', 'F-JDG-007', 'F-JDG-008', 'F-JDG-009', 'F-JDG-010',
    ];

    /**
     * The forward rule's allowlist: forms whose handlers may accept
     * enabling_type = 'emergency_power'. Phase D registers the executive
     * branch here (PHASE_D_DESIGN_executive §D): orders (F-EXE-005) and
     * department rules (F-BOG-001) may cite an ACTIVE power as enabling
     * authority — and only within its declared area and duration; the
     * scope rules below + EnablingInstruments enforce the bounds, and
     * emergency-enabled rules EXPIRE with their power (CLK-03 cascade).
     * EmergencyCeilingTest pins this list.
     */
    public const EMERGENCY_ENABLED_FORMS = ['F-BOG-001', 'F-EXE-005'];

    /**
     * order.civic_process_protection (HARDENED — Art. II §7): the
     * target_domain values an executive order may NEVER carry. Kept in
     * the column enum so the ATTEMPT is typed honestly; rejection is
     * unconditional. Semantic evasion is Phase E judicial-review
     * territory — this is the engine-checkable floor.
     */
    public const ORDER_PROTECTED_DOMAINS = [
        'electoral_process',
        'judicial_process',
        'legislative_process',
    ];

    /**
     * Engine entry point: hardened checks for a canonical form filing.
     * Throws ConstitutionalViolation (with citation) on breach; returns
     * silently when the filing is constitutionally permissible.
     */
    public function check(string $canonicalFormId, array $payload): void
    {
        $this->guardAutomaticRights($canonicalFormId, $payload);
        $this->guardEmergencyCivicProcessShield($canonicalFormId, $payload);

        match ($canonicalFormId) {
            'F-LEG-031' => $this->checkSettingChange($payload),
            'F-LEG-034' => $this->checkReferendumActModification($payload),
            'F-ELB-001' => $this->checkSchedulingOrderRaces($payload),
            'F-ELB-002' => $this->checkValidationGround($payload),
            // Phase D — order pre-issuance scope validation (Art. III §2 ·
            // Art. II §7). Runs at the validator stage, OUTSIDE the engine
            // transaction, so a violation persists its rejection artifacts
            // (rejected_pre_issuance row + public record) BEFORE the
            // rejected=true chain entry and the 422 — the exit-criterion
            // mechanism (design §D "rejection-on-record").
            'F-EXE-005' => $this->checkExecutiveOrder($payload),
            // Phase D — organizations (PHASE_D_DESIGN_organizations §D.2).
            'F-IND-012' => $this->checkOrganizationRegistration($payload),
            'F-ORG-001' => $this->checkOrgProfileManagement($payload),
            'F-LEG-026' => $this->checkMonopolyAcquisition($payload),
            'F-LEG-027' => $this->checkCgcReorgSale($payload),
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

        // Co-determination ordering (Art. III §6 — Phase D cross-key
        // guard): the first-seat threshold must stay strictly below the
        // parity threshold or the Art. III §6 interpolation collapses.
        // Companion value defaults to the constitutional 100/2000 when the
        // filing changes only one side (the supermajority-fraction
        // pattern); the enactment-time re-run repeats the check.
        if ($key === 'worker_rep_min_employees' || $key === 'worker_rep_parity_employees') {
            $min    = $key === 'worker_rep_min_employees' ? (int) $value : (int) ($payload['worker_rep_min_employees'] ?? 100);
            $parity = $key === 'worker_rep_parity_employees' ? (int) $value : (int) ($payload['worker_rep_parity_employees'] ?? 2000);

            self::assertCodeterminationOrdering($min, $parity);
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

        // Phase D (D-1 recut): the executive-committee race floors at 5
        // with NO ceiling — Art. III §2 states no maximum; the 1–9 band
        // is a chamber (Art. II §2) rule and must not cap it.
        if ($seatKind === 'exec_committee') {
            if ($seats < ConstitutionalDefaults::HARD_FLOOR) {
                throw new ConstitutionalViolation(
                    "An executive-committee race elects at least 5 seats (got {$seats}).",
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
    // Phase C — chamber-operations rules (pure asserts; design §G.3)
    // -------------------------------------------------------------------------

    /**
     * speaker.tiebreak_only (Art. II §3) — the Speaker votes ONLY to break
     * a tie. Tie state: every other serving member's cast is resolved (all
     * have cast, or the vote is closing with non-casters counting as part
     * of the unchanged peg denominator) AND yes == no. The tie-break never
     * manufactures a supermajority — the outcome is recomputed against the
     * UNCHANGED peg threshold afterwards; this assert only gates whether
     * the Speaker's cast may record at all.
     */
    public static function assertSpeakerTieState(int $yes, int $no, bool $allOtherCastsResolved): void
    {
        if (! $allOtherCastsResolved) {
            throw new ConstitutionalViolation(
                'The Speaker votes only on ties — other serving members have not yet resolved their casts.',
                'Art. II §3'
            );
        }

        if ($yes !== $no) {
            throw new ConstitutionalViolation(
                sprintf(
                    'The Speaker votes only on ties — the vote stands %d yes / %d no, which is not a tie.',
                    $yes,
                    $no
                ),
                'Art. II §3'
            );
        }
    }

    /**
     * removal.presider (Art. II §3) — a removal proceeding may never be
     * presided over by its own subject. The subject identity is the
     * member row for legislature subjects; callers pass the resolved ids.
     */
    public static function assertRemovalPresider(string $presiderMemberId, string $subjectType, string $subjectId): void
    {
        if ($subjectType === 'legislature_members' && $presiderMemberId === $subjectId) {
            throw new ConstitutionalViolation(
                'No one presides over their own removal proceeding — the chamber must designate a substitute presider.',
                'Art. II §3'
            );
        }
    }

    /**
     * committees.kind_split (Art. V §3) — bicameral committees mirror the
     * chamber-kind ratio: a split must be present, total the seat count,
     * and give each kind at least one seat whenever seats ≥ 2 (a committee
     * containing one kind makes per-kind dual agreement vacuous at the
     * committee stage — q-ledger #q7). Unicameral committees carry no split.
     */
    public static function assertCommitteeKindSplit(int $seats, ?int $typeASeats, ?int $typeBSeats, bool $bicameral): void
    {
        if (! $bicameral) {
            if ($typeASeats !== null || $typeBSeats !== null) {
                throw new ConstitutionalViolation(
                    'A unicameral chamber\'s committees carry no kind split.',
                    'Art. V §3'
                );
            }

            return;
        }

        if ($typeASeats === null || $typeBSeats === null) {
            throw new ConstitutionalViolation(
                'A bicameral chamber\'s committees must carry a type_a/type_b seat split (Art. V §3 mirror).',
                'Art. V §3'
            );
        }

        if ($typeASeats + $typeBSeats !== $seats) {
            throw new ConstitutionalViolation(
                sprintf('Committee kind split %d + %d must total the %d seats.', $typeASeats, $typeBSeats, $seats),
                'Art. V §3'
            );
        }

        if ($seats >= 2 && ($typeASeats < 1 || $typeBSeats < 1)) {
            throw new ConstitutionalViolation(
                'Every committee of 2+ seats must seat both chamber kinds — per-kind dual agreement may never be vacuous.',
                'Art. V §3'
            );
        }
    }

    /**
     * vacancy.declarer (Art. II §5 · as implemented) — F-LEG-036: the
     * Speaker or the system may declare any current seat vacant; a plain
     * legislator may declare only their OWN seat (resignation). Prevents
     * declaration-as-weapon.
     */
    public static function assertVacancyDeclarer(bool $isSystem, bool $isSpeaker, bool $ownSeat): void
    {
        if ($isSystem || $isSpeaker || $ownSeat) {
            return;
        }

        throw new ConstitutionalViolation(
            'A legislator may declare only their own seat vacant (resignation); declaring another '
            . 'member\'s seat requires the Speaker or the system.',
            'Art. II §5 · as implemented'
        );
    }

    /**
     * session.agenda_order (Art. II §2) — the locked agenda head is
     * immutable: every locked item of the current agenda must appear
     * unchanged, in order, at the head of the proposed agenda. Items are
     * arrays carrying at least `kind` and `locked`; locked items are
     * compared by their stable identity keys (kind, ref_type, ref_id).
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $proposed
     */
    public static function assertAgendaOrder(array $current, array $proposed): void
    {
        $identity = fn (array $item): string => json_encode([
            'kind'     => $item['kind'] ?? null,
            'ref_type' => $item['ref_type'] ?? null,
            'ref_id'   => $item['ref_id'] ?? null,
        ]);

        $lockedHead = array_values(array_filter($current, fn ($item) => (bool) (((array) $item)['locked'] ?? false)));

        foreach ($lockedHead as $position => $item) {
            $candidate = $proposed[$position] ?? null;

            if ($candidate === null
                || ! (bool) (((array) $candidate)['locked'] ?? false)
                || $identity((array) $candidate) !== $identity((array) $item)) {
                throw new ConstitutionalViolation(
                    'The locked agenda head (emergency review, constitutional matters) is immutable and '
                    . 'precedes all general business — agenda filings may only reorder or insert after it.',
                    'Art. II §2'
                );
            }
        }

        // No NEW locked items may be smuggled in by a filing either.
        $proposedLocked = array_filter($proposed, fn ($item) => (bool) (((array) $item)['locked'] ?? false));

        if (count($proposedLocked) !== count($lockedHead)) {
            throw new ConstitutionalViolation(
                'Agenda filings may not add or remove locked items — the locked head is engine-composed.',
                'Art. II §2'
            );
        }
    }

    // -------------------------------------------------------------------------
    // emergency.civic_process_shield (Art. II §7 — Phase C batch 2)
    // -------------------------------------------------------------------------

    /**
     * Pure form of the shield (pinned by EmergencyCeilingTest):
     *  - a PROTECTED form may never carry an emergency citation key
     *    (emergency_power_id / enabling_*) — the civic process runs
     *    byte-identically with or without an active power;
     *  - NO form outside EMERGENCY_ENABLED_FORMS may cite a power as its
     *    enabling authority.
     *
     * @param  list<string>  $payloadKeys  top-level payload keys (lowercased by caller)
     */
    public static function assertEmergencyCivicProcessShield(
        string $canonicalFormId,
        array $payloadKeys,
        mixed $enablingType,
    ): void {
        if (in_array($canonicalFormId, self::EMERGENCY_PROTECTED_FORMS, true)) {
            foreach ($payloadKeys as $key) {
                if ($key === 'emergency_power_id' || str_starts_with($key, 'enabling_')) {
                    throw new ConstitutionalViolation(
                        sprintf(
                            '%s is a protected civic process — emergency powers cannot touch it '
                            . '(offending key: %s). Elections, sessions, courts, residency, petitions, '
                            . 'and records run identically under any emergency.',
                            $canonicalFormId,
                            $key
                        ),
                        'Art. II §7'
                    );
                }
            }
        }

        if ($enablingType === 'emergency_power'
            && ! in_array($canonicalFormId, self::EMERGENCY_ENABLED_FORMS, true)) {
            throw new ConstitutionalViolation(
                sprintf(
                    '%s is not declared as an emergency-enabled form — no undeclared handler may cite '
                    . 'an emergency power as enabling authority.',
                    $canonicalFormId
                ),
                'Art. II §7'
            );
        }
    }

    private function guardEmergencyCivicProcessShield(string $canonicalFormId, array $payload): void
    {
        self::assertEmergencyCivicProcessShield(
            $canonicalFormId,
            array_map(fn ($key) => strtolower((string) $key), array_keys($payload)),
            $payload['enabling_type'] ?? null,
        );
    }

    // -------------------------------------------------------------------------
    // referendum.shield (CLK-19, Art. II §6 — Phase C batch 2)
    // -------------------------------------------------------------------------

    /**
     * Pure form of the CLK-19 gate (pinned by ReferendumShieldTest): a
     * referendum act passed by POPULATION SUPERMAJORITY whose shield
     * election has not yet certified cannot be modified or repealed by
     * the legislature. A majority-passed referendum act is modifiable
     * same-term — at chamber supermajority (the F-LEG-034 vote class);
     * after the shield election certifies it is an ordinary law.
     */
    public static function assertReferendumActModifiable(
        bool $passedByPopulationSupermajority,
        bool $shieldElectionPending,
    ): void {
        if ($passedByPopulationSupermajority && $shieldElectionPending) {
            throw new ConstitutionalViolation(
                'This act was passed by population supermajority — the legislature cannot modify or '
                . 'repeal it until the next general election certifies; the protection lapses there '
                . '(CLK-19).',
                'Art. II §6'
            );
        }
    }

    /**
     * F-LEG-034 filing gate: resolve the targeted law's shield state and
     * delegate to the pure assert. Runs PRE-VOTE — the rejection (with
     * citation) is recorded as a rejected=true chain row by the engine.
     */
    private function checkReferendumActModification(array $payload): void
    {
        $lawId = $payload['law_id'] ?? null;

        if (! is_string($lawId) || $lawId === '') {
            throw new ConstitutionalViolation(
                'F-LEG-034 names the referendum act it modifies (law_id).',
                'Art. II §6'
            );
        }

        $law = \Illuminate\Support\Facades\DB::table('laws')
            ->where('id', $lawId)
            ->whereNull('deleted_at')
            ->first(['origin', 'referendum_passed_by_supermajority', 'shield_expires_with_election_id']);

        if ($law === null) {
            throw new ConstitutionalViolation('F-LEG-034 targets an unknown law.', 'Art. II §6');
        }

        if ($law->origin !== 'referendum') {
            throw new ConstitutionalViolation(
                'F-LEG-034 modifies referendum-passed acts only — other laws amend through the bill path.',
                'Art. II §6'
            );
        }

        $shieldPending = false;

        if ($law->shield_expires_with_election_id !== null) {
            $status = \Illuminate\Support\Facades\DB::table('elections')
                ->where('id', (string) $law->shield_expires_with_election_id)
                ->value('status');

            $shieldPending = $status !== null
                && ! in_array($status, ['certified', 'audit_rerun', 'final', 'cancelled'], true);
        }

        self::assertReferendumActModifiable(
            (bool) $law->referendum_passed_by_supermajority,
            $shieldPending,
        );
    }

    // -------------------------------------------------------------------------
    // Phase D — executive order scope rules (Art. III §2 · Art. II §7)
    // -------------------------------------------------------------------------

    /**
     * order.civic_process_protection (HARDENED, pure — pinned by
     * OrderScopeValidationTest): an executive order targeting the
     * electoral, judicial, or legislative process is rejected
     * UNCONDITIONALLY — no enabling instrument, including an active
     * emergency power, can reach a civic process (Art. II §7; the
     * mockups' rejected fixture: deferring a ranked-window opening).
     */
    public static function assertOrderCivicProcessProtection(string $targetDomain): void
    {
        if (in_array($targetDomain, self::ORDER_PROTECTED_DOMAINS, true)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Executive orders cannot touch the %s — elections, courts, and legislatures run '
                    . 'identically under any executive instrument, emergency powers included.',
                    str_replace('_', ' ', $targetDomain)
                ),
                'Art. II §7'
            );
        }
    }

    /**
     * settings.codetermination_ordering (Art. III §6 — Phase D): the
     * worker-representation thresholds must satisfy min < parity; the
     * linear interpolation between them is the constitutional scaling.
     */
    public static function assertCodeterminationOrdering(int $minEmployees, int $parityEmployees): void
    {
        if ($minEmployees >= $parityEmployees) {
            throw new ConstitutionalViolation(
                sprintf(
                    'worker_rep_min_employees (%d) must stay strictly below worker_rep_parity_employees '
                    . '(%d) — Art. III §6 scales worker representation linearly between the two.',
                    $minEmployees,
                    $parityEmployees
                ),
                'Art. III §6'
            );
        }
    }

    /**
     * F-EXE-005 filing gate: delegate to the ExecutiveOrderService
     * preflight — it resolves the acting context, runs the three scope
     * rules (rule 3 via the pure assert above), and on violation PERSISTS
     * the rejection artifacts before rethrowing (design §D
     * "rejection-on-record"; the DB-read-in-validator posture follows the
     * F-LEG-034 CLK-19 precedent).
     */
    private function checkExecutiveOrder(array $payload): void
    {
        // Drafts save without scope validation; issuance validates.
        if (($payload['action'] ?? 'issue') === 'revoke') {
            return; // revocation scope-checks in the service
        }

        app(\App\Services\Executive\ExecutiveOrderService::class)->preflight($payload);
    }

    // -------------------------------------------------------------------------
    // Phase D — organizations rules (Art. III §5/§6; PHASE_D_DESIGN_organizations)
    // -------------------------------------------------------------------------

    /**
     * orgs.fair_market_floor (HARDENED — Art. III §5): a monopoly
     * acquisition pays AT OR ABOVE the recorded fair-market floor. A
     * below-floor compensation filing is rejected pre-commit with the
     * citation (the engine records the rejected=true chain entry); the
     * org_conversions CHECK is the DB belt.
     */
    public static function assertFairMarketCompensation(?float $floor, ?float $compensation): void
    {
        if ($floor === null || $compensation === null) {
            throw new ConstitutionalViolation(
                'A monopoly acquisition records the fair-market floor before compensation, and the '
                . 'compensation itself — both are constitutional facts.',
                'Art. III §5'
            );
        }

        if ($compensation < $floor) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Compensation %s is below the recorded fair-market floor %s — monopoly acquisition '
                    . 'requires fair-market compensation to the prior owners.',
                    number_format($compensation, 2),
                    number_format($floor, 2)
                ),
                'Art. III §5'
            );
        }
    }

    /**
     * orgs.mutual_consent (WF-ORG-06): an ownership transfer proceeds
     * only on BOTH consents — from-side and to-side. The only ownership
     * path that overrides owner consent is monopoly acquisition, which is
     * a conversion (Art. III §5), never a transfer.
     */
    public static function assertTransferConsents(bool $fromConsented, bool $toConsented): void
    {
        if (! $fromConsented || ! $toConsented) {
            throw new ConstitutionalViolation(
                'An ownership transfer requires the consent of BOTH parties — nothing moves on one signature.',
                'Art. I · WF-ORG-06 · as implemented'
            );
        }
    }

    /**
     * F-IND-012 — type whitelist: Common Good Corporations are created by
     * LEGISLATURES (F-LEG-019), never self-registered (Art. III §5). The
     * rejection carries the citation pre-commit.
     */
    private function checkOrganizationRegistration(array $payload): void
    {
        if (($payload['type'] ?? null) === 'common_good_corp') {
            throw new ConstitutionalViolation(
                'A Common Good Corporation is created by a legislative act (F-LEG-019) — it can never be '
                . 'self-registered.',
                'Art. III §5'
            );
        }
    }

    /**
     * F-ORG-001 'manage_document_package' — the package key may never
     * collide with a canonical/alias constitutional form ID: self-managed
     * internal forms live ABOVE the constitutional floor and can never
     * override it.
     */
    private function checkOrgProfileManagement(array $payload): void
    {
        if (($payload['action'] ?? null) !== 'manage_document_package') {
            return;
        }

        $key = strtoupper(trim((string) ($payload['key'] ?? '')));

        if ($key !== '' && \App\Domain\Forms\FormRegistry::exists($key)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Document package key [%s] collides with a constitutional form ID — self-managed '
                    . 'internal forms live above the constitutional floor and can never override it.',
                    $key
                ),
                'CGA Forms Catalog · as implemented'
            );
        }
    }

    /**
     * F-LEG-026 filing gates (Art. III §5): the proposal records the
     * fair-market floor + published basis BEFORE any vote; the completion
     * filing's compensation must meet the recorded floor (DB-read posture
     * per the F-LEG-034 CLK-19 precedent).
     */
    private function checkMonopolyAcquisition(array $payload): void
    {
        $action = $payload['action'] ?? 'propose';

        if ($action === 'propose') {
            $floor = $payload['fair_market_floor'] ?? null;

            if (! is_numeric($floor) || (float) $floor <= 0
                || trim((string) ($payload['fair_market_basis'] ?? '')) === '') {
                throw new ConstitutionalViolation(
                    'A monopoly acquisition records the fair-market floor and its published valuation basis '
                    . 'BEFORE any vote — fair-market compensation is the constitutional condition.',
                    'Art. III §5'
                );
            }

            return;
        }

        if ($action === 'record_compensation') {
            $conversion = \Illuminate\Support\Facades\DB::table('org_conversions')
                ->where('id', (string) ($payload['conversion_id'] ?? ''))
                ->whereNull('deleted_at')
                ->first(['fair_market_floor']);

            $compensation = $payload['compensation'] ?? null;

            self::assertFairMarketCompensation(
                $conversion?->fair_market_floor !== null ? (float) $conversion->fair_market_floor : null,
                is_numeric($compensation) ? (float) $compensation : null,
            );
        }
    }

    /**
     * F-LEG-027 — Art. III §5 irreversibility gate, pre-vote: no
     * reorganization/sale payload may carry an ip_-prefixed or reclaim
     * key — a public-domain dedication can never be privatized, and the
     * attempt itself is rejected on the record.
     */
    private function checkCgcReorgSale(array $payload): void
    {
        foreach (array_keys($payload) as $key) {
            $lower = strtolower((string) $key);

            if (str_starts_with($lower, 'ip_') || str_contains($lower, 'reclaim')) {
                throw new ConstitutionalViolation(
                    'CGC public-domain dedications are irreversible — no reorganization or sale may reclaim '
                    . 'or privatize dedicated intellectual property (offending key: ' . $key . ').',
                    'Art. III §5'
                );
            }
        }
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
