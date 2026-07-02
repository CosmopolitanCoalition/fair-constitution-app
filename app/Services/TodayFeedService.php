<?php

namespace App\Services;

use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\LegislatureSession;
use App\Models\Petition;
use App\Models\PublicRecord;
use App\Models\ReferendumQuestion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The Home "today" feed (mockups-v3-wiring Phase 3b — the civic/today.html
 * contract): everything LIVE in the viewer's footprint, the community
 * calendar of dated future events, and the latest public-record entries.
 *
 * Sources are the four proceeding kinds the app already runs — elections in
 * their live phases, chamber sessions, open petitions, queued referendum
 * questions — scoped by the SAME association jurisdiction ids the Home
 * controller resolves (the Art. I ancestor sweep). Every query is bounded
 * (whereIn + limit) and carries the jurisdiction name via join/eager load —
 * no N+1.
 *
 * Row shape (the fixtures-v2 `live.rows` contract):
 *   {id, kind, status, title, what, part, jurisdiction, pill{tone,label},
 *    target{kind: closesAt|opensAt, iso}|null, href}
 */
class TodayFeedService
{
    /** Feed rows shown; the header still counts the true total. */
    private const ROW_CAP = 12;

    private const CALENDAR_CAP = 10;

    private const RECORD_CAP = 5;

    /** live → open → soon → (closed never produced, sorts last anyway). */
    private const STATUS_RANK = ['live' => 0, 'open' => 1, 'soon' => 2, 'closed' => 3];

    /**
     * @param  list<string>  $jurisdictionIds  the viewer's association ids
     *                                         (HomeController's exact sweep)
     * @return array{rows: list<array<string, mixed>>, total: int, calendar: list<array<string, mixed>>, record: list<array<string, mixed>>}
     */
    public function forUser(User $user, array $jurisdictionIds): array
    {
        if ($jurisdictionIds === []) {
            return ['rows' => [], 'total' => 0, 'calendar' => [], 'record' => []];
        }

        $now = now();

        $rows = [
            ...$this->electionRows($jurisdictionIds),
            ...$this->sessionRows($jurisdictionIds, $now),
            ...$this->petitionRows($jurisdictionIds),
            ...$this->referendumRows($jurisdictionIds),
        ];

        usort($rows, function (array $a, array $b): int {
            $rank = (self::STATUS_RANK[$a['status']] ?? 9) <=> (self::STATUS_RANK[$b['status']] ?? 9);
            if ($rank !== 0) {
                return $rank;
            }

            // Soonest target first; untargeted rows sink within their bucket.
            $aIso = $a['target']['iso'] ?? null;
            $bIso = $b['target']['iso'] ?? null;
            if ($aIso === null && $bIso === null) {
                return 0;
            }
            if ($aIso === null) {
                return 1;
            }
            if ($bIso === null) {
                return -1;
            }

            return strcmp($aIso, $bIso);
        });

        return [
            'rows'     => array_slice($rows, 0, self::ROW_CAP),
            'total'    => count($rows),
            'calendar' => $this->calendar($jurisdictionIds, $now),
            'record'   => $this->record($jurisdictionIds),
        ];
    }

    // ── feed rows ────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function electionRows(array $jurisdictionIds): array
    {
        $elections = Election::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereIn('status', [
                Election::STATUS_APPROVAL_OPEN,
                Election::STATUS_FINALIST_CUTOFF,
                Election::STATUS_RANKED_OPEN,
            ])
            ->with('jurisdiction:id,name')
            ->orderBy('created_at')
            ->limit(self::ROW_CAP)
            ->get();

        return $elections->map(function (Election $election): array {
            $name = $election->jurisdiction?->name ?? 'your jurisdiction';

            [$phase, $what, $part, $target] = match ($election->status) {
                Election::STATUS_APPROVAL_OPEN => [
                    'Approval open',
                    'Endorse every candidate you approve of; the ranked window opens next.',
                    'Voting is open — your ballot only ever shows THAT you voted, never how.',
                    $this->target('closesAt', $election->finalist_cutoff_at),
                ],
                Election::STATUS_FINALIST_CUTOFF => [
                    'Finalist cutoff',
                    'Approval endorsements are being tallied to settle the finalist list.',
                    'Watch the finalists settle — the ranked window opens next.',
                    $this->target('opensAt', $election->ranked_opens_at),
                ],
                default => [
                    'Ranked open',
                    'Rank the finalists in the order you prefer.',
                    'Voting is open — your ballot only ever shows THAT you voted, never how.',
                    $this->target('closesAt', $election->ranked_closes_at),
                ],
            };

            return [
                'id'           => 'election-'.$election->id,
                'kind'         => 'election',
                'status'       => 'open',
                'title'        => sprintf('%s — %s election', $name, $election->kind),
                'what'         => $what,
                'part'         => $part,
                'jurisdiction' => $name,
                'pill'         => ['tone' => 'vote', 'label' => $phase],
                'target'       => $target,
                'href'         => '/elections',
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function sessionRows(array $jurisdictionIds, CarbonInterface $now): array
    {
        $sessions = LegislatureSession::query()
            ->join('legislatures as l', 'l.id', '=', 'legislature_sessions.legislature_id')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereIn('l.jurisdiction_id', $jurisdictionIds)
            ->where(function ($query) use ($now) {
                $query->where('legislature_sessions.status', LegislatureSession::STATUS_OPEN)
                    ->orWhere(fn ($q) => $q
                        ->where('legislature_sessions.status', LegislatureSession::STATUS_SCHEDULED)
                        ->where('legislature_sessions.scheduled_for', '>', $now));
            })
            ->orderByRaw("CASE WHEN legislature_sessions.status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('legislature_sessions.scheduled_for')
            ->limit(self::ROW_CAP)
            ->get(['legislature_sessions.*', 'j.name as feed_jurisdiction_name']);

        return $sessions->map(function (LegislatureSession $session): array {
            $name = $session->feed_jurisdiction_name ?? 'your jurisdiction';
            $live = $session->status === LegislatureSession::STATUS_OPEN;

            return [
                'id'           => 'session-'.$session->id,
                'kind'         => 'session',
                'status'       => $live ? 'live' : 'soon',
                'title'        => sprintf('%s — chamber session', $name),
                'what'         => $live
                    ? 'The chamber is in session; the agenda is being worked in order.'
                    : 'The chamber convenes soon — the agenda posts with the call.',
                'part'         => 'Watch from the gallery, or take the floor if you reside here.',
                'jurisdiction' => $name,
                'pill'         => $live
                    ? ['tone' => 'live', 'label' => 'Live now']
                    : ['tone' => 'wait', 'label' => 'Scheduled'],
                'target'       => $live ? null : $this->target('opensAt', $session->scheduled_for),
                'href'         => "/legislatures/{$session->legislature_id}/chamber",
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function petitionRows(array $jurisdictionIds): array
    {
        $petitions = Petition::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereIn('status', [Petition::STATUS_GATHERING, Petition::STATUS_THRESHOLD_REACHED])
            ->with('jurisdiction:id,name')
            ->withCount(['signatures as live_signatures_count' => fn ($q) => $q->whereNull('revoked_at')])
            ->orderByDesc('created_at')
            ->limit(self::ROW_CAP)
            ->get();

        return $petitions->map(function (Petition $petition): array {
            $name    = $petition->jurisdiction?->name ?? 'your jurisdiction';
            $reached = $petition->status === Petition::STATUS_THRESHOLD_REACHED;

            return [
                'id'           => 'petition-'.$petition->id,
                'kind'         => 'petition',
                'status'       => 'open',
                'title'        => $petition->title,
                'what'         => $reached
                    ? 'The petition reached its threshold and is heading to the signature audit.'
                    : 'A petition is gathering signatures toward its threshold.',
                'part'         => 'Sign it if you reside here — signatures stay revocable until the audit.',
                'jurisdiction' => $name,
                'pill'         => [
                    'tone'  => 'wait',
                    'label' => sprintf(
                        '%s of %s signatures',
                        number_format((int) $petition->live_signatures_count),
                        number_format((int) $petition->threshold_count),
                    ),
                ],
                'target'       => null,
                'href'         => "/civic/petitions/{$petition->id}",
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function referendumRows(array $jurisdictionIds): array
    {
        $questions = ReferendumQuestion::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereIn('status', [ReferendumQuestion::STATUS_SCHEDULED, ReferendumQuestion::STATUS_VOTED])
            ->with('jurisdiction:id,name')
            ->orderByDesc('created_at')
            ->limit(self::ROW_CAP)
            ->get();

        return $questions->map(function (ReferendumQuestion $question): array {
            $name  = $question->jurisdiction?->name ?? 'your jurisdiction';
            $voted = $question->status === ReferendumQuestion::STATUS_VOTED;

            return [
                'id'           => 'referendum-'.$question->id,
                'kind'         => 'referendum',
                'status'       => $voted ? 'open' : 'soon',
                'title'        => 'Referendum — '.Str::limit($question->question, 90),
                'what'         => $voted
                    ? 'Ballots are in; the result certifies against the whole civic population.'
                    : 'A referendum question is attached to the next jurisdiction-wide ballot.',
                'part'         => $voted
                    ? 'Watch the certification — pass or fail resolves against everyone eligible.'
                    : 'You decide this one directly — a single question, yes or no, on your ballot.',
                'jurisdiction' => $name,
                'pill'         => $voted
                    ? ['tone' => 'info', 'label' => 'Awaiting certification']
                    : ['tone' => 'wait', 'label' => 'On the ballot'],
                'target'       => null,
                'href'         => '/legislature/referendums',
            ];
        })->all();
    }

    // ── the community calendar ───────────────────────────────────────────────

    /**
     * Dated FUTURE events in day buckets: election phase boundaries, scheduled
     * chamber sessions, and armed CLK-01 timers (the next general election).
     *
     * @return list<array{day: string, title: string, where: string, kind: string, at: string, href: string}>
     */
    private function calendar(array $jurisdictionIds, CarbonInterface $now): array
    {
        $events = [];

        $elections = Election::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->where('status', '!=', Election::STATUS_CANCELLED)
            ->where(function ($query) use ($now) {
                $query->where('approval_opens_at', '>', $now)
                    ->orWhere('finalist_cutoff_at', '>', $now)
                    ->orWhere('ranked_opens_at', '>', $now)
                    ->orWhere('ranked_closes_at', '>', $now);
            })
            ->with('jurisdiction:id,name')
            ->limit(25)
            ->get();

        $phaseLabels = [
            'approval_opens_at'  => 'Approval opens',
            'finalist_cutoff_at' => 'Finalist cutoff',
            'ranked_opens_at'    => 'Ranked voting opens',
            'ranked_closes_at'   => 'Ranked voting closes',
        ];

        foreach ($elections as $election) {
            $name = $election->jurisdiction?->name ?? 'your jurisdiction';
            foreach ($phaseLabels as $column => $label) {
                $at = $election->{$column};
                if ($at !== null && $at->gt($now)) {
                    $events[] = $this->calendarEvent($now, $at, "{$label} — {$name}", $name, '/elections');
                }
            }
        }

        $sessions = LegislatureSession::query()
            ->join('legislatures as l', 'l.id', '=', 'legislature_sessions.legislature_id')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereIn('l.jurisdiction_id', $jurisdictionIds)
            ->where('legislature_sessions.status', LegislatureSession::STATUS_SCHEDULED)
            ->where('legislature_sessions.scheduled_for', '>', $now)
            ->orderBy('legislature_sessions.scheduled_for')
            ->limit(self::CALENDAR_CAP)
            ->get(['legislature_sessions.*', 'j.name as feed_jurisdiction_name']);

        foreach ($sessions as $session) {
            $name     = $session->feed_jurisdiction_name ?? 'your jurisdiction';
            $events[] = $this->calendarEvent(
                $now,
                $session->scheduled_for,
                "Chamber session — {$name}",
                $name,
                "/legislatures/{$session->legislature_id}/chamber",
            );
        }

        $timers = ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-01')
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereNotNull('fires_at')
            ->where('fires_at', '>', $now)
            ->with('jurisdiction:id,name')
            ->orderBy('fires_at')
            ->limit(self::CALENDAR_CAP)
            ->get();

        foreach ($timers as $timer) {
            $name     = $timer->jurisdiction?->name ?? 'your jurisdiction';
            $events[] = $this->calendarEvent(
                $now,
                $timer->fires_at,
                "Next general election — {$name}",
                $name,
                '/elections',
            );
        }

        usort($events, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return array_slice($events, 0, self::CALENDAR_CAP);
    }

    /** @return array{day: string, title: string, where: string, kind: string, at: string, href: string} */
    private function calendarEvent(
        CarbonInterface $now,
        CarbonInterface $at,
        string $title,
        string $where,
        string $href,
    ): array {
        return [
            'day'   => $this->dayBucket($at, $now),
            'title' => $title,
            'where' => $where,
            'kind'  => 'jurisdiction',
            'at'    => $at->toIso8601String(),
            'href'  => $href,
        ];
    }

    /** 'Today' | 'Tomorrow' | weekday name inside 7 days | 'Later'. */
    private function dayBucket(CarbonInterface $at, CarbonInterface $now): string
    {
        if ($at->isSameDay($now)) {
            return 'Today';
        }
        if ($at->isSameDay($now->copy()->addDay())) {
            return 'Tomorrow';
        }
        if ($at->lt($now->copy()->addDays(7)->endOfDay())) {
            return $at->format('l');
        }

        return 'Later';
    }

    // ── on the record ────────────────────────────────────────────────────────

    /**
     * The latest CURRENT register rows for the footprint (superseded rows are
     * replaced by their corrections, so only heads show).
     *
     * @return list<array{seq: int, kind: string, title: string, published_at: string|null, href: string}>
     */
    private function record(array $jurisdictionIds): array
    {
        return PublicRecord::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereNull('supersedes_record_id')
            ->orderByDesc('published_at')
            ->orderByDesc('seq')
            ->limit(self::RECORD_CAP)
            ->get(['seq', 'kind', 'title', 'published_at'])
            ->map(fn (PublicRecord $record): array => [
                'seq'          => (int) $record->seq,
                'kind'         => $record->kind,
                'title'        => $record->title,
                'published_at' => $record->published_at?->toIso8601String(),
                'href'         => '/system/public-records',
            ])
            ->all();
    }

    /** @return array{kind: string, iso: string}|null */
    private function target(string $kind, ?CarbonInterface $at): ?array
    {
        return $at === null ? null : ['kind' => $kind, 'iso' => $at->toIso8601String()];
    }
}
