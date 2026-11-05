<?php

namespace App\Services\Assistant;

use App\Support\NodeCatalog;
use Illuminate\Support\Facades\Http;

/**
 * W-0523 - the assistant behind the drawer. It always acts as the signed-in person.
 * Every tool run passes AssistantAccess first, at run time. With no model endpoint
 * configured, a phrase router maps what the person says to the tool catalog. With an
 * endpoint (OpenAI chat-completions format), the model chooses tools and sees only the
 * tools the person has allowed.
 */
class AssistantService
{
    public function __construct(
        private readonly AssistantAccess $access,
        private readonly AssistantTools $tools,
    ) {}

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{text: string, mode: string, tool: ?string, denied: ?string}
     */
    public function reply(string $userId, string $message, array $history = []): array
    {
        $message = trim($message);
        if ($message === '') {
            return $this->out('Say or type something and I will try to help.', 'router');
        }
        if (config('assistant.endpoint') && config('assistant.model')) {
            return $this->viaModel($userId, $message, $history);
        }

        return $this->viaRouter($userId, $message);
    }

    /** @return array{text: string, mode: string, tool: ?string, denied: ?string} */
    private function viaRouter(string $userId, string $message): array
    {
        $norm = $this->normalize($message);
        $best = null;
        foreach (NodeCatalog::assistantTools() as $tool) {
            if (! AssistantTools::has($tool['id'])) {
                continue;
            }
            foreach ($tool['phrases'] as $phrase) {
                $p = $this->normalize($phrase);
                if ($p !== '' && str_contains($norm, $p) && ($best === null || strlen($p) > strlen($best['p']))) {
                    $best = ['tool' => $tool['id'], 'p' => $p];
                }
            }
        }
        if ($best === null && preg_match('/\b(task|todo|to do)s?\b/', $norm)) {
            $best = ['tool' => 'tasks', 'p' => ''];
        }
        if ($best === null) {
            return $this->out('No model is connected to this node, so I can only run tools by phrase. Try: "What is on today", "Summarize my inbox", "Add a task buy milk", "Find a contact Jane" or "Brief me".', 'router');
        }
        $after = $best['p'] === '' ? '' : trim(substr($norm, strpos($norm, $best['p']) + strlen($best['p'])));
        $rest = trim(preg_replace('/^(to|that)\s+/', '', $after));
        $args = match ($best['tool']) {
            'agenda' => ['days' => str_contains($norm, 'week') ? 7 : 1],
            'tasks' => in_array($best['p'], ['add a task', 'remind me'], true) ? ['action' => 'add', 'title' => $rest] : ['action' => 'list'],
            'contacts' => ['query' => $rest],
            default => [],
        };

        return $this->runTool($userId, $best['tool'], $args, 'router');
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{text: string, mode: string, tool: ?string, denied: ?string}
     */
    private function viaModel(string $userId, string $message, array $history): array
    {
        $messages = [['role' => 'system', 'content' => 'You are the personal assistant of the signed-in person. You act only as them. Use the tools you are given for their calendar, mail, tasks and contacts. If a tool is missing, say you cannot do it. Never say you did something you did not do. Keep answers short and plain.']];
        foreach (array_slice($history, -((int) config('assistant.history_turns', 12))) as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => mb_substr((string) ($h['text'] ?? ''), 0, 2000)];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $tools = $this->toolSchemas($userId);
        $lastTool = null;
        $denied = null;
        for ($round = 0; $round <= (int) config('assistant.max_tool_rounds', 4); $round++) {
            try {
                $res = Http::timeout((int) config('assistant.timeout', 60))
                    ->withToken((string) config('assistant.api_key'))
                    ->post(rtrim((string) config('assistant.endpoint'), '/').'/chat/completions', array_filter([
                        'model' => config('assistant.model'),
                        'messages' => $messages,
                        'tools' => $tools ?: null,
                    ]));
            } catch (\Throwable $e) {
                return $this->out('The assistant model did not answer. Try again in a moment.', 'error');
            }
            if (! $res->successful()) {
                return $this->out('The assistant model returned an error.', 'error');
            }
            $msg = $res->json('choices.0.message') ?? [];
            $calls = $msg['tool_calls'] ?? [];
            if (! $calls) {
                return ['text' => trim((string) ($msg['content'] ?? '')) ?: 'I have nothing to add.', 'mode' => 'model', 'tool' => $lastTool, 'denied' => $denied];
            }
            $messages[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $call) {
                $name = (string) ($call['function']['name'] ?? '');
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?: [];
                $r = $this->runTool($userId, $name, $args, 'model');
                $lastTool = $name;
                $denied = $r['denied'] ?? $denied;
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'] ?? $name, 'content' => $r['text']];
            }
        }

        return ['text' => 'I could not finish that. Please ask in a simpler way.', 'mode' => 'model', 'tool' => $lastTool, 'denied' => $denied];
    }

    /** @return array{text: string, mode: string, tool: ?string, denied: ?string} */
    private function runTool(string $userId, string $toolId, array $args, string $mode): array
    {
        if ($toolId === 'briefing') {
            return $this->briefing($userId, $mode);
        }
        $d = $this->access->decide($userId, $toolId);
        if (! $d['allowed']) {
            return ['text' => $this->deniedText($d['reason']), 'mode' => $mode, 'tool' => $toolId, 'denied' => $d['reason']];
        }
        if ($d['needs_approval']) {
            return ['text' => 'That needs your approval. Approvals are not connected yet.', 'mode' => $mode, 'tool' => $toolId, 'denied' => 'approval-not-built'];
        }
        $r = $this->tools->run($userId, $toolId, $args);
        $this->audit($userId, $toolId);

        return ['text' => $r['text'], 'mode' => $mode, 'tool' => $toolId, 'denied' => null];
    }

    /** @return array{text: string, mode: string, tool: ?string, denied: ?string} */
    private function briefing(string $userId, string $mode): array
    {
        $parts = [];
        foreach ([['agenda', ['days' => 1]], ['inbox_summary', []], ['tasks', ['action' => 'list']]] as [$id, $args]) {
            $d = $this->access->decide($userId, $id);
            $parts[] = $d['allowed'] ? $this->tools->run($userId, $id, $args)['text'] : $this->deniedText($d['reason']);
        }
        $this->audit($userId, 'briefing');

        return ['text' => implode("\n\n", $parts), 'mode' => $mode, 'tool' => 'briefing', 'denied' => null];
    }

    private function deniedText(string $reason): string
    {
        return match (true) {
            $reason === 'switched-off' => 'You switched that tool off.',
            str_starts_with($reason, 'missing-right') => 'You have not given the assistant that right on this store. Change it under Datastores and permissions.',
            $reason === 'owner-policy-not-set' => 'That store belongs to a shared owner, and its policy is not set yet.',
            default => 'That is not available yet.',
        };
    }

    /** @return list<array<string, mixed>> */
    private function toolSchemas(string $userId): array
    {
        $params = [
            'agenda' => ['days' => ['type' => 'integer', 'description' => 'Days ahead, 1 to 31']],
            'tasks' => ['action' => ['type' => 'string', 'enum' => ['list', 'add']], 'title' => ['type' => 'string']],
            'contacts' => ['query' => ['type' => 'string']],
        ];
        $out = [];
        foreach (NodeCatalog::assistantTools() as $t) {
            if (! AssistantTools::has($t['id']) || ! $this->access->decide($userId, $t['id'])['allowed']) {
                continue;
            }
            $out[] = ['type' => 'function', 'function' => [
                'name' => $t['id'], 'description' => $t['does'],
                'parameters' => ['type' => 'object', 'properties' => (object) ($params[$t['id']] ?? [])],
            ]];
        }

        return $out;
    }

    private function audit(string $userId, string $toolId): void
    {
        try {
            app(\App\Services\AuditService::class)->append('assistant', 'tool.run', ['tool' => $toolId], 'users', $userId);
        } catch (\Throwable) {
            // The tool ran. A missing audit sink does not undo it.
        }
    }

    private function normalize(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($s))));
    }

    /** @return array{text: string, mode: string, tool: ?string, denied: ?string} */
    private function out(string $text, string $mode): array
    {
        return ['text' => $text, 'mode' => $mode, 'tool' => null, 'denied' => null];
    }
}
