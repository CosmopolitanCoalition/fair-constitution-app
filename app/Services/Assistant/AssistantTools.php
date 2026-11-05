<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * W-0523 - the tools the assistant can run today. Each reads or writes only the signed-in
 * person's own rows. The permission check (AssistantAccess) runs before any handler.
 * The briefing is composed by AssistantService from the other tools, each checked on its own.
 * The catalog in NodeCatalog lists more tools. A tool with no handler here is "not built".
 */
class AssistantTools
{
    private const HANDLERS = ['agenda', 'inbox_summary', 'tasks', 'contacts', 'briefing'];

    public static function has(string $toolId): bool
    {
        return in_array($toolId, self::HANDLERS, true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{text: string, data: array<string, mixed>}
     */
    public function run(string $userId, string $toolId, array $args = []): array
    {
        return match ($toolId) {
            'agenda' => $this->agenda($userId, (int) ($args['days'] ?? 7)),
            'inbox_summary' => $this->inbox($userId),
            'tasks' => $this->tasks($userId, (string) ($args['action'] ?? 'list'), (string) ($args['title'] ?? '')),
            'contacts' => $this->contacts($userId, (string) ($args['query'] ?? '')),
            default => ['text' => 'That tool is not built yet.', 'data' => []],
        };
    }

    /** @return array{text: string, data: array<string, mixed>} */
    private function agenda(string $userId, int $days): array
    {
        $days = max(1, min(31, $days));
        $rows = DB::table('personal_events')->where('user_id', $userId)->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '>=', now()->startOfDay())->where('starts_at', '<', now()->startOfDay()->addDays($days))
            ->orderBy('starts_at')->limit(25)->get(['title', 'starts_at', 'location']);
        if ($rows->isEmpty()) {
            return ['text' => $days === 1 ? 'Nothing is on your calendar today.' : "Nothing is on your calendar for the next {$days} days.", 'data' => ['events' => []]];
        }
        $lines = $rows->map(fn ($e) => '- '.date('D j M H:i', strtotime((string) $e->starts_at)).'  '.$e->title.($e->location ? " ({$e->location})" : ''));

        return ['text' => "Your calendar:\n".$lines->implode("\n"), 'data' => ['events' => $rows->all()]];
    }

    /** @return array{text: string, data: array<string, mixed>} */
    private function inbox(string $userId): array
    {
        $q = DB::table('mail_messages')->where('user_id', $userId)->whereNull('deleted_at');
        $unread = (clone $q)->where('is_read', false)->count();
        $flagged = (clone $q)->where('is_flagged', true)->count();
        $latest = (clone $q)->where('is_read', false)->orderByDesc('received_at')->limit(3)->get(['from_name', 'subject']);
        $text = "You have {$unread} unread and {$flagged} flagged messages.";
        if ($latest->isNotEmpty()) {
            $text .= "\nNewest unread:\n".$latest->map(fn ($m) => '- '.($m->from_name ?: 'Unknown').': '.($m->subject ?: '(no subject)'))->implode("\n");
        }

        return ['text' => $text, 'data' => ['unread' => $unread, 'flagged' => $flagged]];
    }

    /** @return array{text: string, data: array<string, mixed>} */
    private function tasks(string $userId, string $action, string $title): array
    {
        if ($action === 'add') {
            $title = trim($title);
            if ($title === '') {
                return ['text' => 'Tell me what the task is. For example: add a task buy milk.', 'data' => []];
            }
            $id = (string) Str::uuid();
            DB::table('personal_tasks')->insert([
                'id' => $id, 'user_id' => $userId, 'title' => Str::limit($title, 240, ''), 'status' => 'open',
                'priority' => 3, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['text' => 'Added the task: '.Str::limit($title, 240, '').'.', 'data' => ['task_id' => $id]];
        }
        $rows = DB::table('personal_tasks')->where('user_id', $userId)->whereNull('deleted_at')->where('status', 'open')
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('priority')->limit(15)->get(['title', 'due_at']);
        if ($rows->isEmpty()) {
            return ['text' => 'You have no open tasks.', 'data' => ['tasks' => []]];
        }

        return ['text' => "Your open tasks:\n".$rows->map(fn ($t) => '- '.$t->title.($t->due_at ? ' (due '.date('j M', strtotime((string) $t->due_at)).')' : ''))->implode("\n"), 'data' => ['tasks' => $rows->all()]];
    }

    /** @return array{text: string, data: array<string, mixed>} */
    private function contacts(string $userId, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['text' => 'Who should I look for? For example: find a contact Jane.', 'data' => []];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($query)).'%';
        $rows = DB::table('crm_contacts')->where('user_id', $userId)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(organization) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]))
            ->orderBy('name')->limit(8)->get(['name', 'organization', 'email', 'phone']);
        if ($rows->isEmpty()) {
            return ['text' => "No contact matches \"{$query}\".", 'data' => ['contacts' => []]];
        }

        return ['text' => $rows->map(fn ($c) => '- '.$c->name.($c->organization ? ", {$c->organization}" : '').($c->email ? " <{$c->email}>" : '').($c->phone ? " {$c->phone}" : ''))->implode("\n"), 'data' => ['contacts' => $rows->all()]];
    }
}
