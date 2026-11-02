<?php

namespace App\Http\Controllers\Personal\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Native calendar: events, availability and free slots. Every query is scoped to the key's owner. */
class CalendarApiController extends Controller
{
    public function calendars(Request $r)
    {
        return ['data' => DB::table('personal_calendars')->where('user_id', $r->user()->id)->orderBy('name')->get()];
    }

    public function events(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'limit' => 'nullable|integer|min:1|max:500']);
        $q = DB::table('personal_events')->where('user_id', $r->user()->id)->whereNull('deleted_at')
            ->when($d['from'] ?? null, fn ($q, $v) => $q->where('ends_at', '>=', $v))
            ->when($d['to'] ?? null, fn ($q, $v) => $q->where('starts_at', '<', $v));

        return ['data' => $q->orderBy('starts_at')->limit($d['limit'] ?? 200)->get()];
    }

    public function book(Request $r)
    {
        $d = $this->eventRules($r, true);
        $cal = $this->calendar($r, $d['calendar_id'] ?? null);
        abort_if(! ($d['force'] ?? false) && $this->conflicts($r->user()->id, $d['starts_at'], $d['ends_at']), 409, 'That time overlaps another event.');
        $id = (string) Str::uuid();
        DB::table('personal_events')->insert($this->row($d) + [
            'id' => $id, 'user_id' => $r->user()->id, 'calendar_id' => $cal->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('personal_events')->find($id)], 201);
    }

    public function change(Request $r, string $id)
    {
        $e = $this->own($r, $id);
        $d = $this->eventRules($r, false);
        $starts = $d['starts_at'] ?? $e->starts_at;
        $ends = $d['ends_at'] ?? $e->ends_at;
        abort_if(CarbonImmutable::parse($ends)->lte(CarbonImmutable::parse($starts)), 422, 'The end must be after the start.');
        abort_if(! ($d['force'] ?? false) && $this->conflicts($r->user()->id, $starts, $ends, $e->id), 409, 'That time overlaps another event.');
        DB::table('personal_events')->where('id', $e->id)->update($this->row($d) + ['updated_at' => now()]);

        return ['data' => DB::table('personal_events')->find($e->id)];
    }

    public function cancel(Request $r, string $id)
    {
        $e = $this->own($r, $id);
        DB::table('personal_events')->where('id', $e->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

        return ['data' => DB::table('personal_events')->find($e->id)];
    }

    /** Free slots inside the owner's availability rules, minus busy events. */
    public function slots(Request $r)
    {
        $d = $r->validate([
            'from' => 'required|date', 'to' => 'required|date|after:from', 'minutes' => 'nullable|integer|min:5|max:480',
            'appointment_type' => 'nullable|string|max:40',
        ]);
        $user = $r->user();
        $tz = $user->timezone ?: 'UTC';
        $from = CarbonImmutable::parse($d['from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($d['to'], $tz);
        abort_if($from->diffInDays($to) > 31, 422, 'Ask for at most 31 days.');
        $len = (int) ($d['minutes'] ?? 30);
        $rules = DB::table('availability_rules')->where('user_id', $user->id)
            ->when($d['appointment_type'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('appointment_type', $v)->orWhereNull('appointment_type')))
            ->get()->groupBy('weekday');
        $busy = $this->busy($user->id, $from, $to);
        $now = CarbonImmutable::now($tz);
        $slots = [];
        for ($day = $from; $day->lt($to); $day = $day->addDay()) {
            foreach ($rules->get($day->dayOfWeek, []) as $rule) {
                $cursor = $day->setTimeFromTimeString($rule->starts_at);
                $end = $day->setTimeFromTimeString($rule->ends_at);
                for (; $cursor->addMinutes($len)->lte($end); $cursor = $cursor->addMinutes($len)) {
                    $slotEnd = $cursor->addMinutes($len);
                    if ($cursor->lt($now) || $cursor->gte($to)) {
                        continue;
                    }
                    if (! $this->overlaps($busy, $cursor, $slotEnd)) {
                        $slots[] = ['starts_at' => $cursor->toIso8601String(), 'ends_at' => $slotEnd->toIso8601String()];
                    }
                }
            }
        }

        return ['data' => $slots, 'timezone' => $tz];
    }

    public function availability(Request $r)
    {
        return ['data' => DB::table('availability_rules')->where('user_id', $r->user()->id)->orderBy('weekday')->orderBy('starts_at')->get()];
    }

    public function setAvailability(Request $r)
    {
        $d = $r->validate([
            'rules' => 'required|array|max:60', 'rules.*.weekday' => 'required|integer|between:0,6',
            'rules.*.starts_at' => 'required|date_format:H:i', 'rules.*.ends_at' => 'required|date_format:H:i',
            'rules.*.appointment_type' => 'nullable|string|max:40',
        ]);
        foreach ($d['rules'] as $rule) {
            abort_if($rule['ends_at'] <= $rule['starts_at'], 422, 'Each window must end after it starts.');
        }
        DB::transaction(function () use ($r, $d) {
            DB::table('availability_rules')->where('user_id', $r->user()->id)->delete();
            foreach ($d['rules'] as $rule) {
                DB::table('availability_rules')->insert($rule + ['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return $this->availability($r);
    }

    /** Insert or update events pulled from Microsoft or Google, keyed by (source, external id). */
    public function sync(Request $r)
    {
        $d = $r->validate([
            'source' => 'required|in:microsoft,google', 'events' => 'required|array|max:500',
            'events.*.external_id' => 'required|string|max:200', 'events.*.title' => 'required|string|max:200',
            'events.*.starts_at' => 'required|date', 'events.*.ends_at' => 'required|date',
            'events.*.all_day' => 'nullable|boolean', 'events.*.status' => 'nullable|in:confirmed,tentative,cancelled',
            'events.*.location' => 'nullable|string|max:200',
        ]);
        $cal = DB::table('personal_calendars')->where('user_id', $r->user()->id)->where('source', $d['source'])->first()
            ?? $this->makeCalendar($r->user()->id, ucfirst($d['source']), $d['source']);
        foreach ($d['events'] as $e) {
            DB::table('personal_events')->updateOrInsert(
                ['user_id' => $r->user()->id, 'external_source' => $d['source'], 'external_id' => $e['external_id']],
                $this->row($e) + ['calendar_id' => $cal->id, 'deleted_at' => null, 'updated_at' => now()]
                + ['id' => (string) Str::uuid(), 'created_at' => now()],
            );
        }

        return ['synced' => count($d['events'])];
    }

    private function eventRules(Request $r, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return $r->validate([
            'title' => "$req|string|max:200", 'starts_at' => "$req|date", 'ends_at' => "$req|date",
            'all_day' => 'nullable|boolean', 'location' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:5000',
            'status' => 'nullable|in:confirmed,tentative,cancelled', 'attendees' => 'nullable|array|max:50',
            'calendar_id' => 'nullable|uuid', 'force' => 'nullable|boolean',
        ]);
    }

    private function row(array $d): array
    {
        $row = array_intersect_key($d, array_flip(['title', 'starts_at', 'ends_at', 'all_day', 'location', 'notes', 'status']));
        // One stored shape (UTC, no zone suffix) so every database compares times the same way.
        foreach (['starts_at', 'ends_at'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = CarbonImmutable::parse($row[$k])->utc()->format('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('attendees', $d)) {
            $row['attendees'] = json_encode($d['attendees']);
        }

        return $row;
    }

    private function own(Request $r, string $id): object
    {
        $e = DB::table('personal_events')->where('id', $id)->where('user_id', $r->user()->id)->whereNull('deleted_at')->first();
        abort_unless($e, 404);

        return $e;
    }

    private function calendar(Request $r, ?string $id): object
    {
        $q = DB::table('personal_calendars')->where('user_id', $r->user()->id);
        $cal = $id ? $q->where('id', $id)->first() : (clone $q)->where('source', 'native')->first();
        abort_if($id && ! $cal, 404, 'Calendar not found.');

        return $cal ?? $this->makeCalendar($r->user()->id, 'Calendar', 'native');
    }

    private function makeCalendar(string $userId, string $name, string $source): object
    {
        $id = (string) Str::uuid();
        DB::table('personal_calendars')->insert(['id' => $id, 'user_id' => $userId, 'name' => $name, 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('personal_calendars')->find($id);
    }

    private function busy(string $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('personal_events as e')->join('personal_calendars as c', 'c.id', '=', 'e.calendar_id')
            ->where('e.user_id', $userId)->whereNull('e.deleted_at')->where('e.status', '!=', 'cancelled')->where('c.counts_as_busy', true)
            ->where('e.ends_at', '>', $from)->where('e.starts_at', '<', $to)->get(['e.starts_at', 'e.ends_at', 'e.id'])
            ->map(fn ($e) => [CarbonImmutable::parse($e->starts_at), CarbonImmutable::parse($e->ends_at), $e->id])->all();
    }

    private function overlaps(array $busy, CarbonImmutable $a, CarbonImmutable $b, ?string $ignore = null): bool
    {
        foreach ($busy as [$s, $e, $id]) {
            if ($id !== $ignore && $s->lt($b) && $e->gt($a)) {
                return true;
            }
        }

        return false;
    }

    private function conflicts(string $userId, string $starts, string $ends, ?string $ignore = null): bool
    {
        $a = CarbonImmutable::parse($starts);
        $b = CarbonImmutable::parse($ends);

        return $this->overlaps($this->busy($userId, $a, $b), $a, $b, $ignore);
    }
}
