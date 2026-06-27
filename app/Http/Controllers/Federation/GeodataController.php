<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\GeodataManifestService;
use App\Services\Federation\GeodataSeedTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase G (G3c — N3) — serve a signed geospatial-dataset MANIFEST to a pinned peer,
 * and (roles-campaign Phase 0b) range-serve the SEED tarball bytes that manifest names.
 * The manifest is signed by its ORIGIN (this instance, for what we publish); the puller
 * verifies that origin signature against our pinned key, then verifies the assembled seed
 * bytes against the manifest's sha256. The byte pages carry no private data — the seed is
 * the geodata foundation only (no instance_settings, no institutional tables).
 * federation.signed (pinned) on both endpoints.
 */
class GeodataController extends Controller
{
    public function __construct(
        private readonly GeodataManifestService $manifests,
        private readonly GeodataSeedTransportService $seed,
    ) {}

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

    /**
     * Range-serve one page of the published seed tarball. Pinned peers only
     * (federation.signed). The page is opaque bytes; the puller's integrity comes from
     * the origin-signed manifest sha256, so a flipped byte fails the puller's digest check.
     */
    public function seedPage(Request $request): Response
    {
        $dataset = (string) $request->query('dataset', '');
        if ($dataset === '') {
            return response('dataset is required', 422);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $len = (int) $request->query('len', 0);
        // Cap the page so a peer can never demand an unbounded slab; default to the sync page size.
        $cap = max(1, (int) config('cga.federation_seed_page_max_bytes', 16 * 1024 * 1024));
        $len = $len > 0 ? min($len, $cap) : min($cap, 8 * 1024 * 1024);

        $range = $this->seed->readRange($dataset, $offset, $len);
        if ($range === null) {
            return response('seed not found', 404);
        }

        return response($range['bytes'], 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Seed-Total-Bytes' => (string) $range['total_bytes'],
            'X-Seed-Version' => (string) $range['version'],
        ]);
    }
}
