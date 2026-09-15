<?php

namespace App\Support;

use App\Http\Presenters\CandidacyPanel;
use App\Models\Organization;
use App\Models\OrgStaffGrant;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * IO-5 — the agent's active staff-delegation list. A bounded, org-scoped cursor
 * page over org_staff_grants with status 'active', oldest first. Grantee names
 * ride the public-name contract (CandidacyPanel::displayNames), never the raw
 * users.name legal name. The list reveals who holds a grant, so the controller
 * emits it only for the org's own agent (privacy gate); this class carries no
 * authority and never gates.
 */
final class OrgStaffGrantDirectory
{
    public const PAGE_SIZE = 20;
    public const CURSOR_KEY = 'grants_cursor';

    /** @return array{rows: list<array<string, mixed>>, pages: array{previous: ?string, next: ?string, first: string}} */
    public function page(Request $request, Organization $org, ?string $basePath = null): array
    {
        $key = self::CURSOR_KEY;
        $scope = $org->id.':'.$key;
        $token = $request->validate([$key => 'nullable|string|max:2048'])[$key] ?? null;
        $cursor = $this->cursor($token, $scope, $key);

        $page = OrgStaffGrant::query()
            ->where('organization_id', $org->id)
            ->where('status', OrgStaffGrant::STATUS_ACTIVE)
            ->orderBy('granted_at')
            ->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE, ['*'], $key, $cursor);

        $rows = $page->getCollection();
        $ids = $rows->pluck('grantee_user_id')->map(fn ($id) => (string) $id)->all();
        $names = CandidacyPanel::displayNames($ids);
        $context = PublicPersonSelectionContext::forIds($ids);

        $path = $basePath ?? '/organizations/'.$org->id;
        $link = function (?Cursor $c) use ($path, $key, $scope) {
            if ($c === null) {
                return null;
            }
            $encoded = rtrim(strtr(base64_encode((string) json_encode([
                'scope'              => $scope,
                'granted_at'         => (string) $c->parameter('granted_at'),
                'id'                 => (string) $c->parameter('id'),
                '_pointsToNextItems' => $c->pointsToNextItems(),
            ])), '+/', '-_'), '=');

            return $path.'?'.http_build_query([$key => $encoded]);
        };

        return [
            'rows' => $rows->map(fn (OrgStaffGrant $g) => [
                'id'         => (string) $g->id,
                'grantee'    => [
                    'id'   => (string) $g->grantee_user_id,
                    'name' => $names[(string) $g->grantee_user_id] ?? 'Resident',
                ] + ($context[(string) $g->grantee_user_id] ?? ['profile_href' => null, 'public_handle' => null]),
                'bucket'     => (string) $g->task,
                'status'     => (string) $g->status,
                'granted_at' => $g->granted_at?->toIso8601String(),
            ])->all(),
            'pages' => ['previous' => $link($page->previousCursor()), 'next' => $link($page->nextCursor()), 'first' => $path],
        ];
    }

    private function cursor(?string $token, string $scope, string $key): ?Cursor
    {
        if ($token === null || $token === '') {
            return null;
        }
        try {
            $data = json_decode(base64_decode(strtr($token, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 4 || ($data['scope'] ?? null) !== $scope
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                || ! is_string($data['granted_at'] ?? null) || $data['granted_at'] === ''
                || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                throw new \InvalidArgumentException;
            }

            return new Cursor(['granted_at' => $data['granted_at'], 'id' => $data['id']], $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$key => __('This page link is invalid. Return to the first page.')]);
        }
    }
}
