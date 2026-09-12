<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\SocialPost;
use App\Models\SocialSubforum;
use App\Models\SocialThread;
use App\Support\BillWorkspace;
use App\Support\JurisdictionContext;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The discussion section of the selected-bill workspace. Text, votes and version history
 * have one owner in BillController; BillWorkspace shares the bill's context and navigation.
 * Public comments remain F-SOC-001 posts in the bill's bound hall subforum. Commenting
 * grants no authority to amend text or cast a legislative vote.
 */
class BillConversationController extends Controller
{
    public function __construct(private readonly ConstitutionalEngine $engine) {}

    /** GET /bills/{bill}/conversation — public read (the conversation face). */
    public function show(Request $request, Bill $bill): Response
    {
        $bill->loadMissing(['legislature.jurisdiction:id,name,slug,parent_id,adm_level', 'sponsor.user:id,display_name']);

        $subforum = SocialSubforum::query()
            ->where('governing_object_type', SocialSubforum::OBJECT_BILL)
            ->where('governing_object_id', (string) $bill->id)
            ->first();

        $comments = [];
        $commentPages = null;
        if ($subforum !== null) {
            // Bound the input to this bill's subforum. Older comments remain reachable
            // without loading every thread ID or silently dropping posts after 200.
            $posts = SocialPost::query()
                ->whereIn('thread_id', SocialThread::query()->where('subforum_id', $subforum->id)->select('id'))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->simplePaginate(50, ['id', 'author_display', 'body', 'created_at'])
                ->withQueryString();
            $commentPages = [
                'newerHref' => $posts->previousPageUrl(),
                'olderHref' => $posts->nextPageUrl(),
            ];
            $comments = $posts->getCollection()->reverse()->values()
                ->map(fn (SocialPost $p) => [
                    'id'             => (string) $p->id,
                    'author_display' => $p->author_display, // pseudonym snapshot only (Art. I)
                    'body'           => $p->body,
                    'at'             => $p->created_at?->toDayDateTimeString(),
                ])->all();
        }

        return Inertia::render('Legislature/BillConversation', [
            'surface' => SurfaceMeta::for('legislature/bill-detail'),
            'workspace' => BillWorkspace::for($bill),
            'jurisdictionContext' => $bill->legislature?->jurisdiction ? JurisdictionContext::for($bill->legislature->jurisdiction) : null,
            'comments' => $comments,
            'commentPages' => $commentPages,
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

        return back()->with('status', 'Comment posted in this bill’s discussion.');
    }
}
