<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillVersion;
use App\Models\SocialPost;
use App\Models\SocialSubforum;
use App\Models\SocialThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A bill — the conversation (mockups/v3/shared/bill.html contract). The v3 mockup split a bill into
 * two faces: the CONVERSATION (people talking it over, working its meaning) and the formal record
 * (the lifecycle + the vote math, at /bills/{bill}). This is the conversation face; the formal half
 * stays BillController.
 *
 * The mockup's per-clause "accept / reject" negotiation is DELIBERATELY NOT built. A bill's words
 * change only through amendments the chamber VOTES on (committee_amendment, then floor_amendment →
 * new BillVersions) — never by any one group editing the text, which would defeat the bicameral
 * dual-agreement of Art. V §3. This page explains that path and links to the formal record where the
 * versions and the vote math live; it offers no redline editor.
 *
 * Comments RIDE the bill's auto-bound hall subforum (SubforumReconciler binds every live bill). A
 * comment is an F-SOC-001 post targeting that subforum_id — read straight off the subforum, written
 * through the one canonical post door. Bills carry NO `summary` column, so the page shows the bill's
 * real current text, never a fabricated summary (honest-empty when a version carries no text).
 */
class BillConversationController extends Controller
{
    /** introduced → … → enacted — the ordered spine; the three terminal outcomes are handled apart. */
    private const MAIN_PATH = [
        'introduced'   => 'Introduced',
        'referred'     => 'Referred',
        'in_committee' => 'In committee',
        'reported'     => 'Reported out',
        'on_floor'     => 'On the floor',
        'passed'       => 'Passed',
        'enacted'      => 'Enacted',
    ];

    private const TERMINAL = [
        'tabled'    => 'Tabled',
        'failed'    => 'Failed',
        'withdrawn' => 'Withdrawn',
    ];

    public function __construct(private readonly ConstitutionalEngine $engine) {}

    /** GET /bills/{bill}/conversation — public read (the conversation face). */
    public function show(Request $request, Bill $bill): Response
    {
        $bill->loadMissing(['legislature.jurisdiction', 'sponsor.user:id,name,display_name']);

        $current = BillVersion::query()
            ->where('bill_id', $bill->id)
            ->orderByDesc('version_no')
            ->first(['version_no', 'law_text', 'change_kind']);

        $versionCount = BillVersion::query()->where('bill_id', $bill->id)->count();

        $subforum = SocialSubforum::query()
            ->where('governing_object_type', SocialSubforum::OBJECT_BILL)
            ->where('governing_object_id', (string) $bill->id)
            ->first();

        $comments = [];
        if ($subforum !== null) {
            $threadIds = SocialThread::query()->where('subforum_id', $subforum->id)->pluck('id');
            if ($threadIds->isNotEmpty()) {
                $comments = SocialPost::query()
                    ->whereIn('thread_id', $threadIds)
                    ->orderBy('created_at')
                    ->limit(200)
                    ->get(['id', 'author_display', 'body', 'created_at'])
                    ->map(fn (SocialPost $p) => [
                        'id'             => (string) $p->id,
                        'author_display' => $p->author_display, // pseudonym snapshot only (Art. I)
                        'body'           => $p->body,
                        'at'             => $p->created_at?->toDayDateTimeString(),
                    ])->all();
            }
        }

        return Inertia::render('Legislature/BillConversation', [
            'surface' => ['title' => 'A bill — the conversation', 'nav' => 'bills'],
            'bill'    => [
                'id'               => (string) $bill->id,
                'title'            => $bill->title,
                'sponsor'          => $this->sponsorName($bill),
                'jurisdiction'     => $bill->legislature?->jurisdiction?->name,
                'status'           => $bill->status,
                'text'             => $current?->law_text ?? '',
                'versionCount'     => $versionCount,
                'latestChangeKind' => $current?->change_kind,
                'formalHref'       => "/bills/{$bill->id}",
                'chamberHref'      => $bill->legislature !== null ? "/legislatures/{$bill->legislature->id}/chamber" : null,
            ],
            'stages'   => $this->stages($bill->status),
            'comments' => $comments,
            // 'open' = you can comment; 'needs_auth' = sign in first; 'no_space' = the bill has no
            // bound hall subforum yet (honest-empty — never a faked composer).
            'commentState' => $subforum === null
                ? 'no_space'
                : ($request->user() === null ? 'needs_auth' : 'open'),
        ]);
    }

    /** POST /bills/{bill}/comments — a comment on the bill, riding its bound hall subforum (F-SOC-001). */
    public function comment(Request $request, Bill $bill): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $subforum = SocialSubforum::query()
            ->where('governing_object_type', SocialSubforum::OBJECT_BILL)
            ->where('governing_object_id', (string) $bill->id)
            ->with('space')
            ->first();

        if ($subforum === null || $subforum->space === null) {
            return back()->with('status', 'This bill has no discussion space yet — it opens when the bill is live in the halls.');
        }

        // One canonical "discussion" thread per bill subforum: created on the first comment, appended
        // to after. F-SOC-001 re-resolves the halls space from jurisdiction_id, so we pass the
        // SUBFORUM'S space jurisdiction (which is NOT always the bill's own — verified on live data)
        // else the subforum_id would not resolve within the re-resolved space.
        $thread = SocialThread::query()
            ->where('subforum_id', $subforum->id)
            ->orderBy('created_at')
            ->first(['id']);

        $this->engine->file('F-SOC-001', $request->user(), [
            'jurisdiction_id' => (string) $subforum->space->jurisdiction_id,
            'space_type'      => 'halls',
            'subforum_id'     => (string) $subforum->id,
            'title'           => 'Discussion — '.$bill->title, // used only when the thread is first created
            'body'            => $data['body'],
            'thread_id'       => $thread?->id,
        ]);

        return back()->with('status', 'Comment posted — it rides this bill’s hall thread (F-SOC-001).');
    }

    private function sponsorName(Bill $bill): string
    {
        $u = $bill->sponsor?->user;

        return $u?->display_name ?: ($u?->name ?? 'A member');
    }

    /**
     * The bill's progress as a stage strip. A status on the main path marks position
     * (done / current / pending); a terminal status shows the spine neutral with the outcome badge.
     *
     * @return array{path: list<array{label:string,state:string}>, terminal: ?array{label:string}}
     */
    private function stages(string $status): array
    {
        $keys = array_keys(self::MAIN_PATH);
        $currentIndex = array_search($status, $keys, true);

        $path = [];
        foreach ($keys as $i => $key) {
            $state = 'pending';
            if ($currentIndex !== false) {
                $state = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : 'pending');
            }
            $path[] = ['label' => self::MAIN_PATH[$key], 'state' => $state];
        }

        return [
            'path'     => $path,
            'terminal' => isset(self::TERMINAL[$status]) ? ['label' => self::TERMINAL[$status]] : null,
        ];
    }
}
