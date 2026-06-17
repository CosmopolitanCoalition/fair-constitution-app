<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G3c — N3) — a signed geospatial-dataset manifest (the GEODATA_ORIGIN
 * channel). Carries only PUBLIC dataset metadata + the origin's signature — no
 * raster bytes, no private data. Federates by the ORIGIN's signature, like a
 * DirectoryService entry; identity is (dataset, version, origin_server_id).
 */
class GeodataDatasetManifest extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'dataset',
        'version',
        'sha256',
        'license',
        'size_bytes',
        'origin_server_id',
        'signature',
        'fetched_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'fetched_at' => 'datetime',
    ];
}
