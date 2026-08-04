/**
 * Map Health statistics — what each geodata check measures, and how to read it.
 *
 * WHY THIS EXISTS. These seven checks were built as DEVELOPMENT DIAGNOSTICS:
 * their job was to prove the ingestion code wasn't at fault while the world
 * import was being built. That job is done. What they become now is the
 * standing health readout for the common data set — the direct analogue of the
 * Map Statistics panel in the district mapping tool (population equality,
 * shape compactness, seat drift), which nobody reads as "errors" either.
 *
 * The distinction that matters, and the reason this file is prose and not a
 * lookup table: only ONE of these checks describes a defect the code can
 * cause. The rest describe the WORLD — disputed territory that must coexist in
 * one game space, colonial-era administrative anchoring, a source release that
 * records the same village at two ADM levels, a country the population raster
 * simply doesn't cover. A flag is an invitation to look, and the right
 * resolution is often "accept it" rather than "repair it".
 *
 * `nature` encodes exactly that, and drives how a surface should colour and
 * order the check:
 *   structural     the tree itself is wrong; this one is genuinely actionable
 *   reality        real-world geography the data records faithfully; usually accept
 *   informational  a measurement, not a finding; watch the trend, not the number
 *
 * Every surface that shows these statistics — Setup Step 2, the Jurisdiction
 * mapper's flag queue, the jurisdiction list — reads from here, so the
 * explanation a person gets is the same one everywhere and only has to be
 * corrected in one place.
 *
 * Keys are the exact backend category strings (GeodataFlag::CATEGORIES, plus
 * national_delta_gt5, which the ETL's finalize step writes rather than the
 * scan). Anything unknown falls through to `describeCheck`'s default so a
 * category the backend grows still renders.
 */

export const MAP_HEALTH_CHECKS = {
    orphaned_rows: {
        label:    'Orphaned rows',
        nature:   'structural',
        measures: 'Live jurisdictions with no living parent — either no parent at all, or one that has been removed.',
        why:      'Anything that walks the tree down from Earth — districting, apportionment, population rollups — simply cannot see a detached row. It is the one check here that describes a defect rather than the world.',
        reading:  'Expect zero. Any non-zero count is worth acting on, and the count understates it: a detached parent takes its whole subtree with it.',
        remedy:   'Reparent — though the right answer is sometimes that the row is genuinely top-level and should become an L1 of its own.',
    },

    displaced_geometry: {
        label:    'Displaced geometry',
        nature:   'reality',
        measures: "Rows whose centroid falls outside the parent they claim, and which overlap that parent by less than half their own area.",
        why:      'A row sitting outside its parent usually means the source drew the two boundaries against different coastlines or vintages. Genuine mis-parenting shows up here too, but at world scale most of it is geographic noise.',
        reading:  'Treat as background noise unless a specific jurisdiction is misbehaving. The scan caps this check per run, so the number is a ceiling and not a count — read it as "at least this many".',
        remedy:   'Reparent when a specific row is wrong; otherwise leave it.',
    },

    mis_anchored_cluster: {
        label:    'Mis-anchored clusters',
        nature:   'reality',
        measures: 'A parent holding three or more children that each sit almost entirely outside it — less than 5% overlap.',
        why:      'This is the grouped form of displaced geometry: not one stray row but a whole set anchored to the wrong place. In practice it mostly surfaces the administrative legacy of colonial systems — overseas departments and island groups administered from a mainland parent.',
        reading:  'Small and stable. Most entries are real: archipelagos and overseas territories, faithfully recorded.',
        remedy:   'Synthesize an anchor when the group deserves its own parent; accept when the arrangement is the real one.',
    },

    same_space_chain: {
        label:    'Same-space chains',
        nature:   'reality',
        measures: 'A parent whose single child covers the identical footprint — byte-identical geometry, or areas within 2% of each other with a negligible symmetric difference. Consecutive pairs chain into runs.',
        why:      'The source release recorded one place twice at two administrative levels. The two rows are the same ground, so one of them is a pass-through link rather than a distinct place to govern.',
        reading:  'Concentrated by country rather than spread evenly — a national statistics office either subdivides that layer or it does not. Large counts in one country are a property of that source, not damage.',
        remedy:   'Merge the chain: the topmost row survives as the playable jurisdiction and inherits the lower twin\'s children.',
    },

    dual_coverage: {
        label:    'Dual coverage',
        nature:   'reality',
        measures: "A country whose representative surface point is also covered by another country's tree.",
        why:      'Two nations claim the same ground. The data records both because the dispute is real, and the platform is built to let both coexist in one game space rather than pick a winner.',
        reading:  'Informational by design. A non-zero count is the expected state of the world, not a problem to drive to zero.',
        remedy:   'Accept the flag; prune a side only on a deliberate decision about which claim this instance recognises.',
    },

    raster_coverage: {
        label:    'Raster coverage',
        nature:   'reality',
        measures: 'Countries with no population raster tiles at all, or with tiles but a stored population under half of what the raster holds for that footprint.',
        why:      'Population is the input to the seat law — the chamber size is the cube root of it. A country the raster does not cover cannot be sized, and one counted far under its raster will be under-represented.',
        reading:  'Each entry is a real gap worth knowing about. "No tiles" is a limitation of the population source, not something a rescan will fix.',
        remedy:   'Recompute population where tiles exist; accept where the source genuinely has no coverage.',
    },

    national_delta_gt5: {
        label:    'Attribution delta',
        nature:   'informational',
        measures: "A parent whose own population differs from the sum of its children's by more than 5%.",
        why:      'Each level is attributed independently from the raster, so parent and children should land on nearly the same total. A gap means the child layer overlaps itself or leaves holes relative to the parent — the distortion that shows up later as uneven district populations.',
        reading:  'A map-health trend line, not a defect list. It was invaluable for diagnosing district-drawing problems; day to day, watch whether it grows.',
        remedy:   'None automatic — it points at which layer to look at.',
    },
}

/** Order used wherever the checks are listed: actionable first, then reality, then measurement. */
export const MAP_HEALTH_ORDER = [
    'orphaned_rows',
    'raster_coverage',
    'same_space_chain',
    'mis_anchored_cluster',
    'displaced_geometry',
    'dual_coverage',
    'national_delta_gt5',
]

/** Short badge text per nature — the one-word answer to "should I act on this?". */
export const NATURE_BADGE = {
    structural:    { text: 'Structural',    hint: 'The tree itself is wrong — act on this.' },
    reality:       { text: 'Real-world',    hint: 'Faithfully recorded geography — usually accept.' },
    informational: { text: 'Measurement',   hint: 'A statistic to watch, not a defect to clear.' },
}

/**
 * Describe a check by its backend category string. Always returns an object so
 * a surface never has to null-check; an unknown category degrades to a
 * readable label with empty prose rather than breaking the panel.
 */
export function describeCheck(category) {
    const key = String(category ?? '')

    return MAP_HEALTH_CHECKS[key] ?? {
        label:    key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()),
        nature:   'informational',
        measures: '',
        why:      '',
        reading:  '',
        remedy:   '',
    }
}

/** Sort comparator for category keys, MAP_HEALTH_ORDER first then alphabetical. */
export function compareChecks(a, b) {
    const ia = MAP_HEALTH_ORDER.indexOf(a)
    const ib = MAP_HEALTH_ORDER.indexOf(b)

    if (ia !== -1 && ib !== -1) return ia - ib
    if (ia !== -1) return -1
    if (ib !== -1) return 1

    return String(a).localeCompare(String(b))
}
