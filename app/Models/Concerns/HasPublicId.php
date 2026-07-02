<?php

namespace App\Models\Concerns;

use App\Support\PublicId;

/**
 * HasPublicId — gives a model a `public_id` short reference (the pretty-URL
 * foundation). On `creating`, an empty public_id is filled with a random
 * base62 id (PublicId::generate()). Models that want pretty route binding
 * add `getRouteKeyName(): string { return 'public_id'; }` themselves.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = PublicId::generate();
            }
        });
    }

    /** Find by public_id (soft-deleted rows excluded), or null. */
    public static function resolvePublicId(string $value): ?static
    {
        return static::query()->where('public_id', $value)->first();
    }
}
