<?php

namespace Tests\Constitutional;

use App\Models\Term;
use App\Services\CertificationService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — CLK-10 term lockstep (Art. II §2; Art. III §3;
 * Art. IV §3). Replaces the Phase placeholder
 * `test_term_lockstep_across_branches` — the shared `terms` substrate is
 * live in Phase B (legislature seats); executive/judicial offices join the
 * SAME table in Phases D/E and inherit exactly these guarantees.
 *
 * Three pins, all DB-free (established posture):
 *
 *  1. PURE WINDOW MATH — lockstepWindow(): one schedule, starts at
 *     certification, ends starts + election_interval_months;
 *     inheritedWindow(): a replacement term (countback or special
 *     election) ends ON THE ORIGINAL EXPIRY — an identity, never an
 *     extension, never a fresh term.
 *
 *  2. WRITE-ONCE ends_on — the no-API guarantee: a SOURCE SCAN of app/
 *     proves `ends_on` is only ever written at Term creation
 *     (CertificationService) and that no update-shaped write
 *     (update/fill/forceFill/upsert/property assignment) touches it
 *     anywhere. CLK-10 is enforced by the ABSENCE of an API; this test
 *     pins the absence.
 *
 *  3. NO MUTATOR SURFACE — the Term model exposes nothing
 *     extend/reschedule-shaped.
 *
 * If an edit breaks these tests, the edit is the violation — fix the
 * edit, never the test.
 */
class TermLockstepTest extends TestCase
{
    // ======================================================================
    // 1. Pure lockstep window math
    // ======================================================================

    public function test_lockstep_window_is_certification_date_plus_interval(): void
    {
        $certified = CarbonImmutable::parse('2026-06-14T15:42:11Z');

        $window = CertificationService::lockstepWindow($certified, 60);

        $this->assertSame('2026-06-14', $window['starts_on']->toDateString());
        $this->assertSame('2031-06-14', $window['ends_on']->toDateString());

        // Month arithmetic never overflows into the next month.
        $eom = CertificationService::lockstepWindow(CarbonImmutable::parse('2026-01-31T00:00:00Z'), 1);
        $this->assertSame('2026-02-28', $eom['ends_on']->toDateString());

        // The interval is the amendable election_interval_months — the
        // window tracks whatever value certification resolved.
        $short = CertificationService::lockstepWindow($certified, 12);
        $this->assertSame('2027-06-14', $short['ends_on']->toDateString());
    }

    /**
     * THE pin: a replacement term inherits the ORIGINAL expiry exactly.
     * Whatever the original date was, the inherited ends_on IS that date —
     * for every certification instant before, on, or after it.
     */
    public function test_inherited_window_preserves_the_original_expiry_exactly(): void
    {
        $originals = [
            '2031-06-14',
            '2026-12-31',
            '2027-02-28',
            '2099-01-01',
        ];

        $certificationInstants = [
            '2026-06-20T00:00:00Z',   // early in the term
            '2031-06-13T23:59:59Z',   // the day before expiry
            '2031-06-14T08:00:00Z',   // expiry day itself
        ];

        foreach ($originals as $original) {
            foreach ($certificationInstants as $instant) {
                $window = CertificationService::inheritedWindow(
                    CarbonImmutable::parse($instant),
                    CarbonImmutable::parse($original.'T00:00:00Z'),
                );

                $this->assertSame(
                    $original,
                    $window['ends_on']->toDateString(),
                    'Inherited ends_on must be the ORIGINAL expiry — identity, never recomputed.'
                );
            }
        }

        // A replacement term NEVER outlives the term it fills: the
        // inherited end is independent of when the replacement starts.
        $a = CertificationService::inheritedWindow(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2031-06-14'));
        $b = CertificationService::inheritedWindow(CarbonImmutable::parse('2030-01-01'), CarbonImmutable::parse('2031-06-14'));
        $this->assertTrue($a['ends_on']->equalTo($b['ends_on']));
    }

    // ======================================================================
    // 2. The no-API guarantee (source scan)
    // ======================================================================

    /**
     * No update-shaped write of `ends_on` exists anywhere in app/.
     * The quoted-key regex deliberately does NOT match the distinct
     * `term_ends_on` columns (legislatures / legislature_members), whose
     * derived copies legitimately move with their own rows.
     */
    public function test_no_code_path_updates_a_term_ends_on(): void
    {
        $violations = [];

        // Any statement that combines a mutation verb with the quoted
        // ends_on KEY (`'ends_on' =>` — array reads like $w['ends_on']
        // are not writes) — across the statement, up to its semicolon.
        $forbidden = [
            '/->\s*update\s*\([^;]*[\'"]ends_on[\'"]\s*=>/s' => 'query/model update() touching ends_on',
            '/forceFill\s*\([^;]*[\'"]ends_on[\'"]\s*=>/s' => 'forceFill() touching ends_on',
            '/->\s*fill\s*\([^;]*[\'"]ends_on[\'"]\s*=>/s' => 'fill() touching ends_on',
            '/upsert\s*\([^;]*[\'"]ends_on[\'"]\s*=>/s' => 'upsert() touching ends_on',
            '/->\s*ends_on\s*=[^=>]/' => 'property assignment to ends_on',
            '/\bUPDATE\s+[^;]*\bSET\s+[^;]*\bends_on\b/is' => 'raw SQL UPDATE ... SET ends_on',
        ];

        foreach ($this->appPhpFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'ends_on')) {
                continue;
            }

            foreach ($forbidden as $pattern => $label) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = "{$path}: {$label}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "CLK-10 violation — lockstep ends_on is write-once at Term creation:\n".implode("\n", $violations)
        );
    }

    /**
     * The creation sites are exactly where the design puts them: `'ends_on'
     * =>` (the write-shaped quoted key) appears in app/ ONLY in the Term
     * model's casts, CertificationService's Term::create blocks, and —
     * Phase C (constitutional review note, PHASE_C_DESIGN_chamber_ops
     * §D.1/§E.1) — ChamberActService's CIVIL-APPOINTMENT Term::create
     * (election board members / admin staff, Art. II §9: 10-year civil
     * appointments seated by chamber consent votes, CLK-09 armed at the
     * expiry). Civil appointments are NOT lockstep terms; the no-update
     * pin above still covers them — ends_on stays write-once everywhere.
     * Phase D adds two more creation-only sites in the same spirit:
     * CivilAppointmentService (BoG 10-year civil terms) and
     * OrgBoardSeatingService (board-cycle terms at certification).
     */
    public function test_ends_on_writes_live_only_in_the_certification_pipeline(): void
    {
        $whitelist = [
            $this->normalize($this->appPath().'/Models/Term.php'),                  // fillable/casts
            $this->normalize($this->appPath().'/Services/CertificationService.php'), // Term::create + window math
            $this->normalize($this->appPath().'/Services/Legislature/ChamberActService.php'), // civil-appointment Term::create (Phase C)
            // Phase D (constitutional review note, PHASE_D_DESIGN_executive
            // §C): BoG governors are 10-year CIVIL appointments (Art. II §9)
            // — CivilAppointmentService::openCivilTerm is a Term::create with
            // CLK-09 armed at the expiry, exactly the ChamberActService
            // posture. Civil appointments are NOT lockstep terms; the
            // no-update pin above still covers them.
            $this->normalize($this->appPath().'/Services/CivilAppointmentService.php'),
            // Phase D (PHASE_D_DESIGN_organizations §C): org/CGC/department
            // board seats get a board-cycle term at election certification —
            // OrgBoardSeatingService::certify is a Term::create (ends_on =
            // certified + cycle_months) plus window-math/clock-metadata reads
            // of the same key. Write-once at creation; never mutated.
            $this->normalize($this->appPath().'/Services/Organizations/OrgBoardSeatingService.php'),
            // FE-C2: READ-shaped display prop — the Chamber page's term card
            // serializes legislatures.term_ends_on into an Inertia array
            // ('ends_on' => …->toDateString()); no Term row is touched. The
            // no-update pin above still covers every mutation path.
            $this->normalize($this->appPath().'/Http/Controllers/Legislature/Concerns/ResolvesChamber.php'),
            // FE-C10: same READ-shaped display posture — the TermSync page
            // (§B.16, read-only by design: zero actions, no API) serializes
            // grouped Term.ends_on values into its lockstep table props.
            // SELECT + groupBy only; no Term row is ever written.
            $this->normalize($this->appPath().'/Http/Controllers/System/TermSyncController.php'),
            // FE-D2..D9: the same READ-shaped display posture — the Phase D
            // executive/org surfaces serialize board_seats.term ends_on into
            // their BoardStrip roster props ('ends_on' => …->toDateString()).
            // GET controllers only; no Term row is ever written (the no-update
            // pin above covers every mutation path).
            $this->normalize($this->appPath().'/Http/Controllers/Executive/ExecutiveController.php'),
            $this->normalize($this->appPath().'/Http/Controllers/Executive/DepartmentController.php'),
            $this->normalize($this->appPath().'/Http/Controllers/Organizations/OrganizationController.php'),
            $this->normalize($this->appPath().'/Http/Controllers/Organizations/BoardElectionController.php'),
            $this->normalize($this->appPath().'/Http/Controllers/Organizations/CgcController.php'),
            // FE-E2: same READ-shaped display posture — the Judiciary/Home page
            // serializes judicial_seats.term_ends_on into its nomination-roster
            // props ('ends_on' => …->toDateString()). GET controller only; the
            // no-update pin above still covers every mutation path.
            $this->normalize($this->appPath().'/Http/Controllers/Judiciary/JudiciaryController.php'),
        ];

        $found = [];

        foreach ($this->appPhpFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/[\'"]ends_on[\'"]\s*=>/', $source) === 1) {
                $found[] = $this->normalize($path);
            }
        }

        $rogue = array_diff($found, $whitelist);

        $this->assertSame(
            [],
            array_values($rogue),
            "ends_on written outside the certification pipeline:\n".implode("\n", $rogue)
        );

        // Sanity: the legitimate creation site actually exists.
        $this->assertContains($this->normalize($this->appPath().'/Services/CertificationService.php'), $found);
    }

    // ======================================================================
    // 3. No mutator surface on the model
    // ======================================================================

    public function test_term_model_exposes_no_extension_helpers(): void
    {
        $reflection = new \ReflectionClass(Term::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/extend|prolong|postpone|reschedule|setEnds|moveEnd/i',
                $method->getName(),
                "Term::{$method->getName()}() is extension-shaped — lockstep terms cannot be moved (CLK-10)."
            );
        }
    }

    // ======================================================================
    // Plumbing
    // ======================================================================

    /** @return \Generator<string> every .php file under app/ */
    private function appPhpFiles(): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->appPath(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function appPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
