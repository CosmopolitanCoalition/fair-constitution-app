<?php

namespace App\Services\Assistant;

use App\Services\Node\NodeChoices;
use App\Support\NodeCatalog;

/**
 * W-0522 - may the assistant run this tool for this person right now?
 * The assistant is always the person's own. It never holds more than the person does,
 * so a store counts only when the person owns it. The owner's per-store rights then
 * narrow it. Stores owned by a household, an organization or a community need an owner
 * policy that does not exist yet, so they are refused. A tool the person switched off
 * is refused. A tool that needs approval is allowed only as a request for approval.
 */
class AssistantAccess
{
    public function __construct(private readonly NodeChoices $choices) {}

    /** @return array{allowed: bool, needs_approval: bool, reason: string} */
    public function decide(string $userId, string $toolId): array
    {
        return $this->decideFrom($this->choices->all($userId), $toolId);
    }

    /**
     * @param  array<string, array<string, mixed>>  $choices
     * @return array{allowed: bool, needs_approval: bool, reason: string}
     */
    public function decideFrom(array $choices, string $toolId): array
    {
        $tool = $this->tool($toolId);
        if ($tool === null) {
            return $this->no('unknown-tool');
        }
        if (! in_array($tool['status'], ['built', 'partial'], true) || ! AssistantTools::has($toolId)) {
            return $this->no('not-built');
        }
        if (! empty(($choices['tools']['off'] ?? [])[$toolId])) {
            return $this->no('switched-off');
        }

        $storeId = $tool['store'] ?? null;
        $needsApproval = $tool['class'] === 'A';
        if ($storeId === null) {
            return ['allowed' => true, 'needs_approval' => $needsApproval, 'reason' => 'no-store'];
        }

        $store = $this->store($storeId);
        if ($store === null) {
            return $this->no('unknown-store');
        }
        if ($store['owner'] !== 'individual') {
            return $this->no('owner-policy-not-set');
        }
        if ($storeId === 'vault') {
            return $this->no('vault-is-never-read');
        }

        $rights = $choices['stores']['stores'][$storeId]['assistant'] ?? $store['assistant'];
        $need = $tool['class'] === 'R' ? 'read' : 'write';
        if (! in_array($need, (array) $rights, true)) {
            return $this->no("missing-right-{$need}");
        }

        return ['allowed' => true, 'needs_approval' => $needsApproval, 'reason' => 'ok'];
    }

    /** @return array<string, mixed>|null */
    private function tool(string $id): ?array
    {
        foreach (NodeCatalog::assistantTools() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function store(string $id): ?array
    {
        foreach (NodeCatalog::datastores() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    /** @return array{allowed: bool, needs_approval: bool, reason: string} */
    private function no(string $reason): array
    {
        return ['allowed' => false, 'needs_approval' => false, 'reason' => $reason];
    }
}
