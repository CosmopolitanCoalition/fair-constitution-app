<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\GeodataManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase G (G3c — N3) — serve a signed geospatial-dataset MANIFEST to a pinned peer.
 * The manifest is signed by its ORIGIN (this instance, for what we publish); the
 * puller verifies that origin signature against our pinned key. No raster bytes, no
 * private data — only the public dataset descriptor. federation.signed (pinned).
 */
class GeodataController extends Controller
{
    public function __construct(private readonly GeodataManifestService $manifests) {}

    public function manifest(Request $request): JsonResponse
    {
        $dataset = (string) $request->query('dataset', '');

        if ($dataset === '') {
            return response()->json(['error' => 'dataset is required'], 422);
        }

        $wire = $this->manifests->serveWire($dataset);

        if ($wire === null) {
            return response()->json(['error' => 'dataset not found'], 404);
        }

        return response()->json($wire);
    }
}
