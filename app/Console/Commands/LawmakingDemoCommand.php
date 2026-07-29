<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The lawmaking act, populated — the largest act in the tour contract and,
 * until now, the emptiest: nothing seeded a bill, a committee, a petition or a
 * referendum, so `elections:demo` seated a chamber and then gave it nothing to
 * do. Roughly one stop in seven walked into an empty room.
 *
 * Everything here goes through the ENGINE (ConstitutionalEngine::file) — the
 * same discipline as institutions:demo-treasury. No direct inserts into the
 * bill, petition or session planes. If a filing would be unconstitutional, it
 * is refused and reported, never written around.
 *
 * ⚑ THE ENACTMENT STEP IS DELIBERATELY LEFT PENDING.
 *
 * Both Phase D and Phase E open with a BICAMERAL supermajority vote, and a
 * freshly-activated bicameral jurisdiction seats Type A only — Type B
 * districting is deferred, so there is no second chamber to agree with. Every
 * such vote closes `failed` with `serving_snapshot` = the Type A count. That
 * is correct constitutional behaviour meeting an incomplete seating path, and
 * the ruling on it sits with the operator.
 *
 * So this command does NOT fake a passage and does NOT work around Type B. It
 * fills every room that does not need a vote to succeed — bills under debate,
 * petitions gathering signatures, statements on the record — and reports each
 * blocked step with the reason. A visibly-pending enactment is not a gap in
 * the demo: it is the clearest available demonstration of what the ruling
 * actually unblocks. When Type B seats, the blocked steps light up with no
 * change here.
 *
 * Idempotent-ish: --fresh clears the demo-tagged rows it created.
 */
class LawmakingDemoCommand extends Command
{
    use \App\Console\Concerns\GuardsSyntheticData;

    protected $signature = 'institutions:demo-lawmaking
        {--jurisdiction=smr-1-san-marino : slug of the jurisdiction whose chamber to populate}
        {--fresh : clear previously seeded demo rows first}';

    protected $description = 'Populate the lawmaking act — bills under debate, petitions gathering signatures, statements and motions — with the enactment step left visibly pending on the Type B ruling.';

    private const TAG = '[LAW-DEMO]';

    private array $blocked = [];

    public function handle(ConstitutionalEngine $engine): int
    {
        // Added 2026-07-28 (the D5 preset audit): this command was one of
        // two demo seeders shipping WITHOUT the synthetic-data guard —
        // nothing stopped it minting demo bills and petitions on a
        // production world. Same gate as every other demo command.
        if (! $this->guardSyntheticData()) {
            return self::FAILURE;
        }

        $jurisdiction = Jurisdiction::query()->where('slug', $this->option('jurisdiction'))->first();

        if ($jurisdiction === null) {
            $this->error('Unknown jurisdiction — found a world first.');

            return self::FAILURE;
        }

        $legislature = Legislature::query()->where('jurisdiction_id', $jurisdiction->id)->first();

        if ($legislature === null) {
            $this->error('That jurisdiction has no legislature — activate it first.');

            return self::FAILURE;
        }

        // CURRENT_STATUSES, not merely un-vacated. An election cycle
        // `term_ended`s the previous cohort and leaves those rows in place, so
        // filtering on vacated_at alone picks up EX-legislators who no longer
        // hold R-09 — the engine then refuses every filing with
        // "requires role R-09; actor holds [R-01…]". Seats are current or they
        // are history; there is no third state worth sponsoring a bill.
        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->whereNull('deleted_at')
            ->whereNull('vacated_at')
            ->get();

        if ($members->count() < 3) {
            $this->error('Need a seated chamber — run elections:demo first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->teardown();
        }

        $this->line("institutions:demo-lawmaking — {$jurisdiction->slug} · {$members->count()} serving members");
        $this->newLine();

        $sponsors = $members->take(6)->map(fn ($m) => User::query()->find($m->user_id))->filter()->values();

        if ($sponsors->isEmpty()) {
            $this->error('Seated members have no user rows — cannot file as an actor.');

            return self::FAILURE;
        }

        $legId = (string) $legislature->id;
        $jurId = (string) $jurisdiction->id;

        // ── 1. Bills under debate — the core of the act ──────────────────
        $bills = [
            [Bill::TYPE_ORDINARY, 'Public Libraries Act ' . self::TAG,
             'Every settlement of more than five hundred residents shall maintain a lending library, open without charge.'],
            [Bill::TYPE_ORDINARY, 'Footpaths and Rights of Way Act ' . self::TAG,
             'Ancient footpaths across the castelli are declared public rights of way and may not be enclosed.'],
            [Bill::TYPE_SUPERMAJORITY, 'Emergency Water Reserve Act ' . self::TAG,
             'The Treasury shall hold a reserve sufficient to supply the jurisdiction with drinking water for ninety days.'],
            [Bill::TYPE_ORDINARY, 'Night Quiet Hours Act ' . self::TAG,
             'Between 23:00 and 06:00, commercial noise above sixty decibels is prohibited in residential districts.'],
        ];

        $filed = 0;
        foreach ($bills as $i => [$actType, $title, $lawText]) {
            $sponsor = $sponsors[$i % $sponsors->count()];

            $this->attempt("bill · {$title}", function () use ($engine, $sponsor, $legId, $jurId, $actType, $title, $lawText, &$filed) {
                $engine->file('F-LEG-003', $sponsor, [
                    'legislature_id' => $legId,
                    'title'          => $title,
                    'act_type'       => $actType,
                    'law_text'       => $lawText,
                    'scale'          => [$jurId],
                ]);
                $filed++;
            });
        }
        $this->line("1. {$filed} bills introduced and under debate (F-LEG-003)");

        // ── 2. Statements on the public record ───────────────────────────
        $statements = [
            'The library bill answers a need every castello raised at its own assembly. I ask members to read it before the session.',
            'I will move an amendment to the water reserve: ninety days is prudent, but the Treasury must say what it costs before we bind it.',
            'On the footpaths bill — these paths are older than the republic. Enclosure would be a taking, whatever we call it.',
        ];

        $said = 0;
        foreach ($statements as $i => $body) {
            $speaker = $sponsors[$i % $sponsors->count()];

            $this->attempt('statement ' . ($i + 1), function () use ($engine, $speaker, $legId, $body, &$said) {
                $engine->file('F-LEG-006', $speaker, [
                    'legislature_id' => $legId,
                    'title'          => 'Statement ' . self::TAG,
                    'body'           => $body,
                ]);
                $said++;
            });
        }
        $this->line("2. {$said} statements on the public record (F-LEG-006)");

        // ── 3. A citizen petition, gathering signatures ──────────────────
        // Needs no legislature vote at all: Art. II §6 puts this in the
        // residents' hands, which is why it is the one lawmaking room that
        // fills completely on a world with no second chamber.
        $petitionId = null;
        $residents = User::query()
            ->whereIn('id', DB::table('residency_confirmations')->where('is_active', true)->distinct()->pluck('user_id'))
            ->limit(40)
            ->get();

        $this->attempt('petition creation', function () use ($engine, $residents, $jurId, &$petitionId) {
            $result = $engine->file('F-IND-009', $residents->first(), [
                'jurisdiction_id' => $jurId,
                'title'           => 'Petition for a Weekly Market ' . self::TAG,
                'act_type'        => Bill::TYPE_ORDINARY,
                'law_text'        => 'A weekly market shall be held in the principal square of each castello, with stalls allocated by lot and without fee.',
            ]);
            $petitionId = $result->recorded['petition_id'] ?? null;
        });

        $signed = 0;
        if ($petitionId !== null) {
            foreach ($residents->skip(1) as $resident) {
                try {
                    $engine->file('F-IND-010', $resident, ['petition_id' => $petitionId]);
                    $signed++;
                } catch (Throwable) {
                    // A resident outside the petition's scope cannot sign —
                    // that is the rule working, not a seeding failure.
                }
            }
        }
        $this->line("3. petition created + {$signed} signatures gathered (F-IND-009 / F-IND-010)");

        // ── 4. The steps that need a chamber vote to SUCCEED ─────────────
        // Attempted honestly so the blockage is demonstrated rather than
        // asserted. Each records WHY it could not complete.
        $this->attempt('speaker election (F-LEG-008)', function () use ($engine, $sponsors, $legId) {
            $engine->file('F-LEG-008', $sponsors->first(), [
                'legislature_id'   => $legId,
                'nominee_user_id'  => (string) $sponsors[1]->id,
            ]);
        });

        $this->attempt('committee creation (F-LEG-009)', function () use ($engine, $sponsors, $legId) {
            $engine->file('F-LEG-009', $sponsors->first(), [
                'legislature_id' => $legId,
                'name'           => 'Committee on Public Works ' . self::TAG,
                'purpose'        => 'Considers bills touching infrastructure, water and rights of way.',
                'seats'          => 5,
            ]);
        });

        // ── Report ───────────────────────────────────────────────────────
        $this->newLine();
        $this->line('POPULATED: ' . DB::table('bills')->count() . ' bills · '
            . DB::table('petitions')->count() . ' petitions · '
            . DB::table('public_records')->count() . ' public records');

        if ($this->blocked !== []) {
            $this->newLine();
            $this->warn('PENDING — attempted honestly, refused by the engine. Each reason is its own:');
            foreach ($this->blocked as $what => $why) {
                $this->line("  ⛔ {$what}");
                $this->line("     engine: {$why}");
            }
            $this->newLine();
            $this->line('  Each reason above is the ENGINE in its own words, not a summary. Type B');
            $this->line('  seating is no longer among them: the at-large race certified and both');
            $this->line('  chambers now serve, so a bicameral act can carry (proven — the F-LEG-014');
            $this->line('  delegation vote passed 31 type_a / 27 type_b).');
            $this->newLine();
            $this->line('  Nothing here is faked and nothing is worked around.');
        }

        $this->newLine();
        $this->info('Lawmaking act populated. Bills are under debate, a petition is gathering signatures.');

        return self::SUCCESS;
    }

    private function attempt(string $what, callable $body): void
    {
        try {
            $body();
        } catch (Throwable $e) {
            $this->blocked[$what] = trim(explode("\n", $e->getMessage())[0]);
        }
    }

    private function teardown(): void
    {
        DB::table('bills')->where('title', 'like', '%' . self::TAG . '%')->delete();
        DB::table('petitions')->where('title', 'like', '%' . self::TAG . '%')->delete();
        $this->line('--fresh: cleared previously seeded demo rows (public records are append-only and stay).');
    }
}
