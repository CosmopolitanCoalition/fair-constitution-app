<?php

namespace App\Services\Economy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase M slice M-3 — the inventory model (operator ruling 2026-07-25).
 *
 * An asset is a THING with its own identity, attributes and provenance: a
 * blanket someone made, a tool, a virtual item on a fantasy map. It is owned,
 * it moves, and its history is append-only.
 *
 * Two decisions worth stating because they shape everything downstream:
 *
 *  1. PHYSICAL AND VIRTUAL ARE ONE FLAG, NOT TWO SYSTEMS. Same table, same
 *     transfer path, same market. A physical thing optionally carries a
 *     jurisdiction (where it is); a virtual one does not. That is what lets a
 *     real craft economy and a D&D-style map run on one engine instead of two
 *     that drift apart.
 *  2. `attributes` IS DELIBERATELY OPEN (jsonb, no schema). A blanket carries
 *     materials and dimensions; a game item carries stats and rarity. The app
 *     does not get to enumerate what people may make — that is content, and
 *     enumerating it would be the engine deciding what a world can contain.
 *
 * Assets are ACCOUNT-scoped, so they inherit M-1's reader privacy with no
 * special case: provenance shows a thing moved between accounts, not between
 * people.
 *
 * Art. III §5: a CGC's public-domain IP is not an asset that can be
 * privatised. The IP register is a separate, hardened plane and this service
 * never touches it.
 */
class AssetService
{
    public const KIND_PHYSICAL = 'physical';
    public const KIND_VIRTUAL  = 'virtual';

    /** Register a thing into an account's inventory (F-IND-024). */
    public function register(
        string $ownerAccountId,
        string $kind,
        string $name,
        ?string $description = null,
        ?array $attributes = null,
        string $quantity = '1',
        string $origin = 'crafted',
        ?string $jurisdictionId = null,
    ): string {
        if (! in_array($kind, [self::KIND_PHYSICAL, self::KIND_VIRTUAL], true)) {
            throw new InvalidArgumentException("An asset is physical or virtual, got [{$kind}].");
        }

        if (bccomp($quantity, '0', 6) !== 1) {
            throw new InvalidArgumentException('An asset needs a quantity greater than zero.');
        }

        $id = (string) Str::uuid();

        DB::transaction(function () use ($id, $ownerAccountId, $kind, $name, $description, $attributes, $quantity, $origin, $jurisdictionId) {
            DB::table('assets')->insert([
                'id'                    => $id,
                'owner_account_id'      => $ownerAccountId,
                'kind'                  => $kind,
                'name'                  => $name,
                'description'           => $description,
                'attributes'            => $attributes === null ? null : json_encode($attributes),
                'quantity'              => $quantity,
                'origin'                => $origin,
                'created_by_account_id' => $ownerAccountId,
                'jurisdiction_id'       => $jurisdictionId,
                'status'                => 'held',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            // Provenance starts at creation: an asset with no origin record
            // is an asset whose history begins in the middle.
            $this->recordTransfer($id, null, $ownerAccountId, $quantity);
        });

        return $id;
    }

    /**
     * Move a thing. Quantity-aware so fungible stacks (ten planks) and unique
     * items (this blanket) use one path: a partial transfer splits the stack.
     */
    public function transfer(string $assetId, string $toAccountId, ?string $quantity = null, ?string $orderId = null, ?string $contractId = null): string
    {
        return DB::transaction(function () use ($assetId, $toAccountId, $quantity, $orderId, $contractId) {
            $asset = DB::table('assets')->where('id', $assetId)->lockForUpdate()->first();

            if ($asset === null) {
                throw new InvalidArgumentException("Unknown asset [{$assetId}].");
            }

            $move = $quantity ?? (string) $asset->quantity;

            if (bccomp($move, (string) $asset->quantity, 6) === 1) {
                throw new InvalidArgumentException('Cannot transfer more of an asset than exists.');
            }

            $from = $asset->owner_account_id;

            if (bccomp($move, (string) $asset->quantity, 6) === 0) {
                // Whole-stack move: the asset changes hands.
                DB::table('assets')->where('id', $assetId)->update([
                    'owner_account_id' => $toAccountId,
                    'status'           => 'held',
                    'updated_at'       => now(),
                ]);
                $movedId = $assetId;
            } else {
                // Partial move: split a new stack off, preserving attributes.
                DB::table('assets')->where('id', $assetId)->update([
                    'quantity'   => bcsub((string) $asset->quantity, $move, 6),
                    'updated_at' => now(),
                ]);

                $movedId = (string) Str::uuid();
                DB::table('assets')->insert([
                    'id'                    => $movedId,
                    'owner_account_id'      => $toAccountId,
                    'kind'                  => $asset->kind,
                    'name'                  => $asset->name,
                    'description'           => $asset->description,
                    'attributes'            => $asset->attributes,
                    'quantity'              => $move,
                    'origin'                => 'split',
                    'created_by_account_id' => $asset->created_by_account_id,
                    'jurisdiction_id'       => $asset->jurisdiction_id,
                    'status'                => 'held',
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
            }

            $this->recordTransfer($movedId, $from, $toAccountId, $move, $orderId, $contractId);

            return $movedId;
        });
    }

    private function recordTransfer(string $assetId, ?string $from, string $to, string $quantity, ?string $orderId = null, ?string $contractId = null): void
    {
        DB::table('asset_transfers')->insert([
            'id'              => (string) Str::uuid(),
            'asset_id'        => $assetId,
            'from_account_id' => $from,
            'to_account_id'   => $to,
            'quantity'        => $quantity,
            'order_id'        => $orderId,
            'contract_id'     => $contractId,
            'created_at'      => now(),
        ]);
    }
}
