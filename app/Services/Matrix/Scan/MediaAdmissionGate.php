<?php

namespace App\Services\Matrix\Scan;

/**
 * Phase K-3 (K3-I.4) — M-S, the proactive content-neutral admission filter that sits in FRONT of the
 * media repo. Before a piece of media becomes an event (and federates), its hash is checked against the
 * configured known-illegal list; a match is REFUSED admission. This is the least-abusable point on the
 * pipeline — there is no published event to censor, no viewpoint to infer (the gate has only a hash and
 * a list). Distinct from M-5 (the reactive, operator-plane removal of already-posted material): M-S
 * stops known-illegal media at the door; M-5 removes what slipped through (or predates the list).
 */
class MediaAdmissionGate
{
    public function __construct(private readonly MediaScanProvider $provider) {}

    /** Decide admission for a media hash. A known-illegal match is blocked, carrying only the list source. */
    public function admit(string $mediaHash): MediaAdmissionResult
    {
        if ($this->provider->matchesKnownIllegal($mediaHash)) {
            return new MediaAdmissionResult(false, $this->provider->source());
        }

        return new MediaAdmissionResult(true);
    }
}
