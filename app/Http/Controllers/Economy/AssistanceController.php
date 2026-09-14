<?php

namespace App\Http\Controllers\Economy;

use App\Http\Controllers\Controller;
use App\Services\Economy\AssistanceService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class AssistanceController extends Controller
{
    public function __construct(private readonly AssistanceService $help) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['tab' => ['nullable', 'in:public,mine,responding']]);
        $tab = $data['tab'] ?? 'public';
        $cursor = $this->cursor($request);
        $actor = $request->user();
        // Public read (operator ruling 2026-09-10). A guest reads the public
        // board. The personal tabs need an account, so they render empty.
        $owned = $actor !== null ? $this->help->ownedAccounts($actor) : null;
        if ($tab !== 'public' && ($owned === null || ! (clone $owned)->exists())) {
            return Inertia::render('Economy/Help', [
                'surface' => SurfaceMeta::for('economy/help'),
                'tab' => $tab, 'requests' => ['data' => [], 'previous' => null, 'next' => null],
                ...$this->participation($request),
            ]);
        }
        $query = DB::table('assistance_requests')->whereNull('deleted_at');
        if ($tab === 'public') {
            $query->where('privacy', 'public')->where('status', 'open');
        } elseif ($tab === 'mine') {
            $query->whereIn('requester_account_id', $owned);
        } else {
            // Collect IDs through owner indexes before reading matching requests.
            $assigned = DB::table('assistance_requests')->select('id')->whereIn('responder_account_id', clone $owned)->whereNull('deleted_at');
            $replied = DB::table('assistance_responses')->select('request_id as id')->whereIn('responder_account_id', clone $owned);
            $query->whereIn('id', $assigned->union($replied))->where(function ($query) use ($owned) {
                // Prior public replies do not grant continuing access to a private request.
                $query->where('privacy', 'public')->orWhereIn('requester_account_id', clone $owned)->orWhereIn('responder_account_id', clone $owned);
            });
        }
        $page = $query->select(['id', 'title', 'need', 'privacy', 'status', 'created_at'])
            ->orderByDesc('id')->cursorPaginate(20, ['*'], 'cursor', $cursor)->withPath('/economy/help')->appends(['tab' => $tab]);
        return Inertia::render('Economy/Help', [
            'surface' => SurfaceMeta::for('economy/help'),
            'tab' => $tab, 'requests' => $this->page($page, fn ($row) => $this->requestRow($row) + ['href' => '/economy/help/'.$row->id]),
            ...$this->participation($request),
        ]);
    }

    public function show(Request $request, string $assistance): Response
    {
        $cursor = $this->cursor($request);
        $actor = $request->user();
        // Public read (operator ruling 2026-09-10). A guest reads a public
        // request only. Actions and the responder roster stay signed-in.
        if ($actor === null) {
            $row = DB::table('assistance_requests')->where('id', $assistance)->whereNull('deleted_at')
                ->where('privacy', 'public')->first(['id', 'title', 'need', 'privacy', 'status', 'created_at', 'requester_account_id']);
            abort_if($row === null, 404);

            return Inertia::render('Economy/HelpDetail', [
                'surface' => SurfaceMeta::for('economy/help-detail'),
                'assistance' => $this->requestRow($row), 'isOwner' => false, ...$this->participation($request),
                'canPublish' => false, 'canWithdraw' => false, 'canResolve' => false, 'canRespond' => false,
                'responses' => ['data' => [], 'next' => null, 'previous' => null],
            ]);
        }
        $row = $this->help->visible($actor, $assistance);
        $owner = $this->help->owns($actor, $row->requester_account_id);
        $unfinished = in_array($row->status, ['open', 'matched'], true);
        $participation = $this->participation($request);
        $ownResponses = DB::table('assistance_responses')->where('request_id', $assistance)->whereIn('responder_account_id', $this->help->ownedAccounts($actor));
        $responses = $owner ? DB::table('assistance_responses')->where('request_id', $assistance) : clone $ownResponses;
        $page = $responses->select(['id', 'message', 'status', 'created_at'])->orderByDesc('id')
            ->cursorPaginate(20, ['*'], 'cursor', $cursor)->withPath('/economy/help/'.$assistance);
        return Inertia::render('Economy/HelpDetail', [
            'surface' => SurfaceMeta::for('economy/help-detail'),
            'assistance' => $this->requestRow($row), 'isOwner' => $owner, ...$participation,
            'canPublish' => $owner && $row->privacy === 'private' && $row->status === 'open',
            'canWithdraw' => $owner && $unfinished, 'canResolve' => $owner && $unfinished,
            'canRespond' => ! $owner && $row->privacy === 'public' && $row->status === 'open' && $participation['canParticipate'] && ! $ownResponses->exists(),
            'responses' => $this->page($page, fn ($offer) => [
                'id' => (string) $offer->id, 'message' => $offer->message, 'status' => $offer->status, 'created_at' => $offer->created_at,
                'canAccept' => $owner && $row->status === 'open' && $offer->status === 'offered',
                'canWithdraw' => ! $owner && $unfinished && in_array($offer->status, ['offered', 'accepted'], true),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'need' => ['required', 'string', 'max:10000'], 'privacy' => ['sometimes', 'in:private,public']]);
        return $this->action(function () use ($request, $data) {
            $id = $this->help->create($request->user(), $data['title'], $data['need'], $data['privacy'] ?? 'private');
            return redirect('/economy/help/'.$id);
        }, ($data['privacy'] ?? 'private') === 'public' ? 'Request published.' : 'Private draft saved. Publish it when you are ready to receive offers of help.');
    }

    public function publish(Request $request, string $assistance): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->help->publish($request->user(), $assistance), 'Request published. Other participants can now offer help.');
    }

    public function withdraw(Request $request, string $assistance): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->help->withdraw($request->user(), $assistance), 'Request withdrawn. Its history is retained.');
    }

    public function resolve(Request $request, string $assistance): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->help->resolve($request->user(), $assistance), 'Request marked complete. This records your confirmation; no payment or contract is created.');
    }

    public function respond(Request $request, string $assistance): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        return $this->action(function () use ($request, $assistance, $data) {
            $this->help->respond($request->user(), $assistance, $data['message']);
            return redirect('/economy/help/'.$assistance);
        }, 'Offer sent privately to the requester.');
    }

    public function match(Request $request, string $assistance, string $response): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->help->match($request->user(), $assistance, $response), 'Offer accepted. Mark the request complete after the help has been provided.');
    }

    public function withdrawResponse(Request $request, string $assistance, string $response): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(function () use ($request, $assistance, $response) {
            $this->help->withdrawResponse($request->user(), $assistance, $response);
            // Withdrawal can remove access to a private match. Return to a safe page.
            return redirect('/economy/help?tab=responding');
        }, 'Offer withdrawn. If it was the selected offer, the request is open again.');
    }

    private function action(callable $action, string $message): RedirectResponse
    {
        try { $result = $action(); }
        catch (InvalidArgumentException $error) {
            if ($error::class !== InvalidArgumentException::class) throw $error;
            return back()->withErrors(['help' => $error->getMessage()]);
        }
        return ($result instanceof RedirectResponse ? $result : back())->with('status', $message);
    }

    private function participation(Request $request): array
    {
        $available = $request->user() !== null && $this->help->participationAccount($request->user()) !== null;
        return ['canParticipate' => $available, 'participationNotice' => $available ? null : 'An open personal wallet is required to post a request or offer help. Your account identity is kept out of these pages.'];
    }

    private function requestRow(object $row): array
    {
        return ['id' => (string) $row->id, 'title' => $row->title, 'need' => $row->need,
            'privacy' => $row->privacy, 'status' => $row->status, 'created_at' => $row->created_at];
    }

    private function page($page, callable $map): array
    {
        return ['data' => array_map($map, $page->items()), 'next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()];
    }

    private function cursor(Request $request): ?Cursor
    {
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:1024']]);
        $encoded = $data['cursor'] ?? null;
        if ($encoded === null || $encoded === '') return null;
        $decoded = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        if (! is_array($decoded) || count($decoded) !== 2 || ! is_bool($decoded['_pointsToNextItems'] ?? null)
            || ! is_string($decoded['id'] ?? null) || ! Str::isUuid($decoded['id'])) {
            throw ValidationException::withMessages(['cursor' => 'This page link is invalid. Open the help workspace again.']);
        }
        return new Cursor(['id' => $decoded['id']], $decoded['_pointsToNextItems']);
    }
}
