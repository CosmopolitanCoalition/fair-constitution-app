<?php

namespace App\Http\Controllers\Personal\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Native unified inbox. n8n pushes messages in; statecraft holds counts, flags, due dates and drafts.
 * Sending is gated: a key may draft and ask to send, but only the owner in a signed-in session approves
 * (ConnectionsController::approveDraft). A key with mail:send may then fetch approved drafts and mark them sent.
 */
class MailApiController extends Controller
{
    public function messages(Request $r)
    {
        $d = $r->validate(['unread' => 'nullable|boolean', 'flagged' => 'nullable|boolean', 'account' => 'nullable|string|max:80', 'limit' => 'nullable|integer|min:1|max:200']);
        $q = DB::table('mail_messages')->where('user_id', $r->user()->id)->whereNull('deleted_at')
            ->when(array_key_exists('unread', $d) && $d['unread'], fn ($q) => $q->where('is_read', false))
            ->when(array_key_exists('flagged', $d) && $d['flagged'], fn ($q) => $q->where('is_flagged', true))
            ->when($d['account'] ?? null, fn ($q, $v) => $q->where('account', $v));

        return ['data' => $q->orderByDesc('received_at')->limit($d['limit'] ?? 50)
            ->get(['id', 'account', 'from_name', 'from_email', 'subject', 'preview', 'received_at', 'is_read', 'is_flagged', 'due_at'])];
    }

    public function show(Request $r, string $id)
    {
        $m = DB::table('mail_messages')->where('id', $id)->where('user_id', $r->user()->id)->whereNull('deleted_at')->first();
        abort_unless($m, 404);

        return ['data' => $m];
    }

    /** Counts per mailbox: unread, flagged, due. The dashboards and the voice briefing read this. */
    public function counts(Request $r)
    {
        $rows = DB::table('mail_messages')->where('user_id', $r->user()->id)->whereNull('deleted_at')->groupBy('account')
            ->selectRaw('account, count(*) filter (where not is_read) as unread, count(*) filter (where is_flagged) as flagged, '
                .'count(*) filter (where due_at is not null and due_at <= now() and not is_read) as due')->get();

        return ['data' => $rows, 'total' => [
            'unread' => (int) $rows->sum('unread'), 'flagged' => (int) $rows->sum('flagged'), 'due' => (int) $rows->sum('due'),
        ]];
    }

    public function flag(Request $r, string $id)
    {
        $d = $r->validate(['is_read' => 'nullable|boolean', 'is_flagged' => 'nullable|boolean', 'due_at' => 'nullable|date']);
        $n = DB::table('mail_messages')->where('id', $id)->where('user_id', $r->user()->id)->update($d + ['updated_at' => now()]);
        abort_unless($n, 404);

        return ['data' => DB::table('mail_messages')->find($id)];
    }

    /** Insert or update messages pulled from a mailbox, keyed by (account, external id). */
    public function sync(Request $r)
    {
        $d = $r->validate([
            'account' => 'required|string|max:80', 'messages' => 'required|array|max:500',
            'messages.*.external_id' => 'required|string|max:200', 'messages.*.received_at' => 'required|date',
            'messages.*.from_name' => 'nullable|string|max:160', 'messages.*.from_email' => 'nullable|string|max:255',
            'messages.*.subject' => 'nullable|string|max:400', 'messages.*.preview' => 'nullable|string|max:2000',
            'messages.*.body' => 'nullable|string|max:200000', 'messages.*.is_read' => 'nullable|boolean',
            'messages.*.is_flagged' => 'nullable|boolean', 'messages.*.due_at' => 'nullable|date',
        ]);
        foreach ($d['messages'] as $m) {
            DB::table('mail_messages')->updateOrInsert(
                ['user_id' => $r->user()->id, 'account' => $d['account'], 'external_id' => $m['external_id']],
                array_diff_key($m, ['external_id' => 1]) + ['deleted_at' => null, 'updated_at' => now()]
                + ['id' => (string) Str::uuid(), 'created_at' => now()],
            );
        }

        return ['synced' => count($d['messages'])];
    }

    public function drafts(Request $r)
    {
        return ['data' => DB::table('mail_drafts')->where('user_id', $r->user()->id)->orderByDesc('created_at')->limit(100)->get()];
    }

    public function createDraft(Request $r)
    {
        $d = $r->validate([
            'to_addresses' => 'required|array|min:1|max:20', 'to_addresses.*' => 'email|max:255',
            'subject' => 'required|string|max:400', 'body' => 'required|string|max:100000',
            'account' => 'nullable|string|max:80', 'in_reply_to' => 'nullable|string|max:200',
        ]);
        $id = (string) Str::uuid();
        DB::table('mail_drafts')->insert(['to_addresses' => json_encode($d['to_addresses'])] + $d + [
            'id' => $id, 'user_id' => $r->user()->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('mail_drafts')->find($id)], 201);
    }

    /** Ask to send. This never sends; it puts the draft in the owner's approval list. */
    public function requestSend(Request $r, string $id)
    {
        $n = DB::table('mail_drafts')->where('id', $id)->where('user_id', $r->user()->id)->where('status', 'draft')
            ->update(['status' => 'pending', 'updated_at' => now()]);
        abort_unless($n, 409, 'Only a draft can be put forward for approval.');

        return ['data' => DB::table('mail_drafts')->find($id)];
    }

    /** Drafts the owner approved and nothing has sent yet. */
    public function approved(Request $r)
    {
        return ['data' => DB::table('mail_drafts')->where('user_id', $r->user()->id)->where('status', 'approved')->orderBy('approved_at')->limit(50)->get()];
    }

    public function markSent(Request $r, string $id)
    {
        $n = DB::table('mail_drafts')->where('id', $id)->where('user_id', $r->user()->id)->where('status', 'approved')
            ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        abort_unless($n, 409, 'Only an approved draft can be marked sent.');

        return ['data' => DB::table('mail_drafts')->find($id)];
    }
}
