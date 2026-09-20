export function workerActivity(worker) {
    return ['acquiring', 'executing', 'waiting'].includes(worker.activity)
        ? worker.activity : (worker.claim_type ? 'executing' : 'unknown');
}

export function activityLabel(worker, t) {
    return t(`c_setup.worker_activity.${workerActivity(worker)}`);
}

export function activitySeconds(worker) {
    return worker.activity_secs ?? (workerActivity(worker) === 'executing' ? worker.claim_secs : null);
}

export function workerBusy(worker) {
    return ['acquiring', 'executing'].includes(workerActivity(worker));
}

// Weighted from raw counter deltas, never an average of per-stage averages.
export function recentTimingCards(rows) {
    // Batch durations must never be averaged together with per-item durations
    // while both are present in a phase-transition window.
    const batched = rows.some(row => row.part === 'stage.stipend_batch' && row.recent_count > 0);
    const groups = batched ? [
        ['acquiring', rows.filter(row => row.part === 'stipend_batch.claim')],
        ['executing', rows.filter(row => row.part === 'stage.stipend_batch')],
        ['housekeeping', rows.filter(row => row.part === 'stipend_batch.between_claims')],
    ] : [
        ['acquiring', rows.filter(row => row.part === 'lane.claim_next')],
        ['executing', rows.filter(row => row.part.startsWith('stage.') && row.part !== 'stage.stipend_batch')],
        ['housekeeping', rows.filter(row => row.part === 'lane.between_claims')],
    ];
    return groups.map(([key, parts]) => {
        const measured = parts.filter(row => row.recent_count != null && row.recent_total_us != null && row.window_seconds > 0);
        const count = measured.reduce((sum, row) => sum + row.recent_count, 0);
        const us = measured.reduce((sum, row) => sum + row.recent_total_us, 0);
        return { key, count, batched, avg_ms: count > 0 ? us / count / 1000 : null,
            window_seconds: measured.length ? Math.max(...measured.map(row => row.window_seconds)) : null };
    });
}
