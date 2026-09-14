<?php

namespace App\Console\Commands;

use App\Console\Concerns\GuardsSyntheticData;
use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\CommitteeSeat;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * institutions:demo-room — seed the LIVE CIVIC ROOM keystone the tour opens.
 *
 * The tour (resources/js/registry/surfaces.js) links one committee hearing at a
 * FIXED meeting UUID. Nothing seeded that row, so the stop hit a 404 on any box
 * where the sim had not run and seated a chamber. This command builds the whole
 * chain the room needs, keyed on that exact UUID:
 *
 *   jurisdiction -> active legislature -> seated members -> a seated committee
 *   with a chair -> committee seats -> the scheduled meeting (TOUR_MEETING_ID).
 *
 * It is self-sufficient. On a founded-but-unseated box it mints the members it
 * needs; where the sim has already seated the chamber it reuses those members
 * and adds nothing to the roster. Either way the room at
 * /rooms/committee/<TOUR_MEETING_ID> opens without a prior sim run.
 *
 * Synthetic-data guarded like every demo seeder: it writes only on a world that
 * has declared itself scale_demo or sandbox. Idempotent: a second run finds the
 * meeting and reports the URL. --fresh clears the rows this command created.
 */
class RoomsDemoCommand extends Command
{
    use GuardsSyntheticData;

    /**
     * The tour's committee hearing. This value MUST equal the href UUID in
     * resources/js/registry/surfaces.js (the "A live committee hearing" stop).
     * RoomsDemoTest pins the match.
     */
    public const TOUR_MEETING_ID = '019fae79-aceb-73a8-a87a-8e25969f1e62';

    /** Marks the rows this command owns, so --fresh clears only its own writes. */
    private const TAG = '[ROOM-DEMO]';

    /** Demo users this command mints carry this email host. */
    private const DEMO_EMAIL_HOST = 'room-demo.invalid';

    protected $signature = 'institutions:demo-room
        {--jurisdiction=smr-1-san-marino : slug of the jurisdiction whose hearing to seed}
        {--fresh : clear rows this command previously seeded first}';

    protected $description = 'Seed the live committee hearing the tour opens, at its fixed meeting UUID, so the room resolves without a prior sim run.';

    public function handle(): int
    {
        if (! $this->guardSyntheticData()) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->teardown();
        }

        $existing = CommitteeMeeting::query()->whereKey(self::TOUR_MEETING_ID)->first();
        if ($existing !== null) {
            $this->info('Committee hearing already seeded.');
            $this->line('  room: /rooms/committee/'.self::TOUR_MEETING_ID);

            return self::SUCCESS;
        }

        $jurisdiction = $this->resolveJurisdiction();
        if ($jurisdiction === null) {
            $this->error('No jurisdiction found — found a world first.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($jurisdiction): void {
            $legislature = $this->resolveLegislature($jurisdiction);
            $members = $this->resolveMembers($legislature);
            $chair = $members->first();

            $committee = Committee::create([
                'id'              => (string) Str::uuid(),
                'legislature_id'  => (string) $legislature->id,
                'name'            => 'Committee on Public Works '.self::TAG,
                'purpose'         => 'Considers bills touching infrastructure, water and rights of way.',
                'seats'           => $members->count(),
                'status'          => Committee::STATUS_SEATED,
                'chair_member_id' => (string) $chair->id,
            ]);

            foreach ($members as $member) {
                CommitteeSeat::create([
                    'id'           => (string) Str::uuid(),
                    'committee_id' => (string) $committee->id,
                    'member_id'    => (string) $member->id,
                    'seat_kind'    => 'type_a',
                    'status'       => CommitteeSeat::STATUS_SEATED,
                    'assigned_via' => CommitteeSeat::VIA_ALGORITHM,
                    'seated_at'    => now(),
                ]);
            }

            CommitteeMeeting::create([
                'id'                  => self::TOUR_MEETING_ID,
                'committee_id'        => (string) $committee->id,
                'called_by_member_id' => (string) $chair->id,
                'scheduled_for'       => now(),
                'agenda'              => [
                    'Testimony on the drainage ordinance',
                    'Report of the water reserve subcommittee',
                    'Rights of way across the castelli',
                ],
                'status'              => CommitteeMeeting::STATUS_SCHEDULED,
            ]);
        });

        $this->info('Committee hearing seeded on '.$jurisdiction->slug.'.');
        $this->line('  room: /rooms/committee/'.self::TOUR_MEETING_ID);

        return self::SUCCESS;
    }

    /** The named jurisdiction, else any founded jurisdiction. */
    private function resolveJurisdiction(): ?Jurisdiction
    {
        $slug = (string) $this->option('jurisdiction');

        return Jurisdiction::query()->where('slug', $slug)->first()
            ?? Jurisdiction::query()->orderBy('id')->first();
    }

    /** Reuse the jurisdiction's legislature if present, else create an active one. */
    private function resolveLegislature(Jurisdiction $jurisdiction): Legislature
    {
        // Reuse the jurisdiction's own legislature as-is (never mutate its
        // status). The room renders for any non-dissolved chamber. Prefer an
        // active one when several exist.
        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdiction->id)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Legislature::STATUS_ACTIVE])
            ->first();

        if ($legislature !== null) {
            return $legislature;
        }

        return Legislature::create([
            'id'              => (string) Str::uuid(),
            'jurisdiction_id' => (string) $jurisdiction->id,
            'term_number'     => 1,
            'status'          => Legislature::STATUS_ACTIVE,
            'total_seats'     => 5,
            'type_a_seats'    => 5,
            'type_b_seats'    => 0,
            'quorum_required' => 3,
        ]);
    }

    /**
     * At least three seated members. Reuse the chamber's current members where
     * the sim has seated them; otherwise mint the demo members this room needs.
     */
    private function resolveMembers(Legislature $legislature)
    {
        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->whereNull('deleted_at')
            ->whereNull('vacated_at')
            ->orderBy('seat_no')
            ->take(5)
            ->get();

        if ($members->count() >= 3) {
            return $members;
        }

        $nextSeat = (int) (LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->max('seat_no')) + 1;

        $seatLabels = ['Aldo', 'Bruna', 'Cesare', 'Diana', 'Ezio'];
        $needed = 5 - $members->count();
        for ($i = 0; $i < $needed; $i++) {
            $user = $this->mintUser($seatLabels[$i] ?? ('Member '.($i + 1)));
            $members->push(LegislatureMember::create([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $legislature->id,
                'user_id'        => (string) $user->id,
                'seat_type'      => 'a',
                'seat_no'        => $nextSeat++,
                'status'         => LegislatureMember::STATUS_SEATED,
                'seated_at'      => now(),
            ]));
        }

        return $members;
    }

    private function mintUser(string $label): User
    {
        $name = $label.' '.self::TAG;

        return User::create([
            'name'              => $name,
            'display_name'      => $label,
            'email'             => Str::lower($label).'-'.Str::uuid().'@'.self::DEMO_EMAIL_HOST,
            'password'          => Str::random(40),
            'terms_accepted_at' => now(),
        ]);
    }

    /** Clear only the rows this command created, in FK-safe order. */
    private function teardown(): void
    {
        CommitteeMeeting::query()->whereKey(self::TOUR_MEETING_ID)->delete();

        $committeeIds = Committee::query()
            ->where('name', 'like', '%'.self::TAG.'%')
            ->pluck('id');

        // committee_seats cascade on the committee delete; a soft-deleting
        // Committee keeps the seat rows, so clear them first.
        CommitteeSeat::query()->whereIn('committee_id', $committeeIds)->delete();
        Committee::query()->whereIn('id', $committeeIds)->forceDelete();

        // Minted demo members and their users. Real (sim) members are untouched.
        $demoUserIds = User::query()
            ->where('email', 'like', '%@'.self::DEMO_EMAIL_HOST)
            ->pluck('id');
        LegislatureMember::query()->whereIn('user_id', $demoUserIds)->forceDelete();
        User::query()->whereIn('id', $demoUserIds)->forceDelete();

        $this->line('--fresh: cleared previously seeded room-demo rows.');
    }
}
