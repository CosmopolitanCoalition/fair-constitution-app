<?php

namespace App\Services\Legislature;

use App\Models\AgendaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The per-item agenda (Wave 5 ⑤). Materializes the agenda JSONB blob into
 * durable AgendaItem rows and walks them: sync() replaces a host's agenda,
 * forHost() reads it ordered, start() takes up the first item when a meeting
 * opens, and advance() progresses pending → in_progress → done.
 *
 * Accepts the two agenda shapes already in the codebase: a committee agenda is
 * a list of STRINGS (F-CHR-002, max 300 each); a session agenda is a list of
 * ARRAYS ({kind, title, locked, ref_type, ref_id, …} — SessionController::
 * agendaDisplay). Both normalize to the same rows.
 */
class AgendaService
{
    /** Kinds the schema CHECK admits — anything else normalizes to 'general'. */
    private const KINDS = [
        'general', 'bill_floor', 'motion', 'committee_report',
        'statement', 'emergency_power', 'constitutional_matter',
    ];

    /**
     * The per-item agenda lights up once its table is migrated. Until then every
     * method degrades to a no-op and the hosts keep their JSONB blob (the room
     * falls back to reading that) — so the feature and its migration can land
     * together without the un-migrated interval breaking the committee flow.
     */
    private function available(): bool
    {
        return Schema::hasTable('agenda_items');
    }

    /**
     * Replace a host's agenda with per-item rows — idempotent: soft-deletes the
     * live rows (freeing their positions), then inserts the new ordered set.
     *
     * @param  array<int, string|array<string, mixed>>  $items
     * @return Collection<int, AgendaItem>
     */
    public function sync(string $agendableType, string $agendableId, array $items): Collection
    {
        if (! $this->available()) {
            return new Collection();
        }

        return DB::transaction(function () use ($agendableType, $agendableId, $items) {
            AgendaItem::query()
                ->where('agendable_type', $agendableType)
                ->where('agendable_id', $agendableId)
                ->whereNull('deleted_at')
                ->delete();

            $position = 0;

            foreach (array_values($items) as $raw) {
                $position++;
                $item = $this->normalize($raw);

                AgendaItem::create([
                    'agendable_type' => $agendableType,
                    'agendable_id'   => $agendableId,
                    'position'       => $position,
                    'kind'           => $item['kind'],
                    'title'          => $item['title'],
                    'ref_type'       => $item['ref_type'],
                    'ref_id'         => $item['ref_id'],
                    'locked'         => $item['locked'],
                    'status'         => AgendaItem::STATUS_PENDING,
                ]);
            }

            return $this->forHost($agendableType, $agendableId);
        });
    }

    /** @return Collection<int, AgendaItem> the live items, ordered by position. */
    public function forHost(string $agendableType, string $agendableId): Collection
    {
        if (! $this->available()) {
            return new Collection();
        }

        return AgendaItem::query()
            ->where('agendable_type', $agendableType)
            ->where('agendable_id', $agendableId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();
    }

    /**
     * Take up the first unlocked pending item (called when a meeting opens). A
     * no-op when the host has no items (e.g. an agenda set directly on the blob,
     * not through F-CHR-002) or the agenda is already under way.
     */
    public function start(string $agendableType, string $agendableId): ?AgendaItem
    {
        if (! $this->available()) {
            return null;
        }

        return DB::transaction(function () use ($agendableType, $agendableId) {
            $items = $this->forHost($agendableType, $agendableId);

            if ($items->firstWhere('status', AgendaItem::STATUS_IN_PROGRESS) !== null) {
                return null; // already under way
            }

            $first = $items->first(
                fn (AgendaItem $i) => $i->status === AgendaItem::STATUS_PENDING && ! $i->locked
            );

            if ($first !== null) {
                $first->forceFill([
                    'status'      => AgendaItem::STATUS_IN_PROGRESS,
                    'taken_up_at' => now(),
                ])->save();
            }

            return $first;
        });
    }

    /**
     * The chair moves on: dispose the item in progress (or, before the first
     * take-up, the first unlocked pending item), then take up the next unlocked
     * pending item. LOCKED head items (emergency / constitutional) are never
     * disposed here — they belong to the engine, not the chair. Returns the
     * newly-current item, or null when the agenda is exhausted.
     */
    public function advance(string $agendableType, string $agendableId, ?string $disposition = null): ?AgendaItem
    {
        if (! $this->available()) {
            return null;
        }

        return DB::transaction(function () use ($agendableType, $agendableId, $disposition) {
            $items = $this->forHost($agendableType, $agendableId);

            $current = $items->firstWhere('status', AgendaItem::STATUS_IN_PROGRESS)
                ?? $items->first(
                    fn (AgendaItem $i) => $i->status === AgendaItem::STATUS_PENDING && ! $i->locked
                );

            if ($current !== null) {
                $current->forceFill([
                    'status'      => AgendaItem::STATUS_DONE,
                    'disposition' => $disposition,
                    'disposed_at' => now(),
                ])->save();
            }

            $next = $items->first(fn (AgendaItem $i) => $i->status === AgendaItem::STATUS_PENDING
                && ! $i->locked
                && ($current === null || $i->position > $current->position));

            if ($next !== null) {
                $next->forceFill([
                    'status'      => AgendaItem::STATUS_IN_PROGRESS,
                    'taken_up_at' => now(),
                ])->save();
            }

            return $next;
        });
    }

    /**
     * Normalize one raw agenda entry (string OR array) into a row's fields.
     *
     * @param  string|array<string, mixed>  $raw
     * @return array{kind: string, title: string, ref_type: ?string, ref_id: ?string, locked: bool}
     */
    private function normalize(string|array $raw): array
    {
        if (is_string($raw)) {
            return [
                'kind'     => 'general',
                'title'    => $raw,
                'ref_type' => null,
                'ref_id'   => null,
                'locked'   => false,
            ];
        }

        $kind = (string) ($raw['kind'] ?? 'general');

        return [
            'kind'     => in_array($kind, self::KINDS, true) ? $kind : 'general',
            'title'    => (string) ($raw['title'] ?? 'Item'),
            'ref_type' => isset($raw['ref_type']) ? (string) $raw['ref_type'] : null,
            'ref_id'   => isset($raw['ref_id']) ? (string) $raw['ref_id'] : null,
            'locked'   => (bool) ($raw['locked'] ?? false),
        ];
    }
}
