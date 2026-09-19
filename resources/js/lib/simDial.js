// THE SIMULATION DIAL (operator order 2026-09-19): the percent of each leaf
// population the simulation mints as people. The number box is the exact
// value; the slider is a LOGARITHMIC scale over the same value, so one drag
// covers 0.01 % to 100 % and the low end (where the dial is used) is not
// crushed into the first pixel. Pure functions: pinned in
// tests/js/simDial.test.mjs. The bounds and the default mirror
// App\Support\SimDial on the server, which clamps again.

export const DIAL_DEFAULT = 0.1
export const DIAL_MIN = 0
export const DIAL_MAX = 100
export const DIAL_DECIMALS = 4

// The slider's own range. Position 0 is SLIDER_LOW_PCT, position SLIDER_STEPS
// is DIAL_MAX. Values below SLIDER_LOW_PCT (down to 0) are number-box only.
export const SLIDER_STEPS = 400
export const SLIDER_LOW_PCT = 0.01

const LOG_LOW = Math.log10(SLIDER_LOW_PCT)
const LOG_SPAN = Math.log10(DIAL_MAX) - LOG_LOW

/** A dial value from any input: not a number gives the default; the rest clamps to the bounds. */
export function clampPct(value) {
    const v = typeof value === 'number' ? value : parseFloat(value)
    if (!Number.isFinite(v)) return DIAL_DEFAULT
    const f = 10 ** DIAL_DECIMALS
    return Math.round(Math.min(DIAL_MAX, Math.max(DIAL_MIN, v)) * f) / f
}

/** Round to two significant digits, so a drag lands on readable values (0.1, 0.25, 1.5, 12). */
function twoSignificant(v) {
    if (v <= 0) return 0
    const mag = 10 ** (Math.floor(Math.log10(v)) - 1)
    return Math.round(v / mag) * mag
}

/** Slider position (0..SLIDER_STEPS) to percent. */
export function pctFromSlider(position) {
    const p = Math.min(SLIDER_STEPS, Math.max(0, Number(position) || 0))
    return clampPct(twoSignificant(10 ** (LOG_LOW + (LOG_SPAN * p) / SLIDER_STEPS)))
}

/** Percent to the nearest slider position. Values under the slider's low end sit at position 0. */
export function sliderFromPct(pct) {
    const v = clampPct(pct)
    if (v <= SLIDER_LOW_PCT) return 0
    return Math.round(((Math.log10(v) - LOG_LOW) / LOG_SPAN) * SLIDER_STEPS)
}

/** "1 in 1,000" for a percent, or null when the dial is 0. */
export function oneIn(pct) {
    const v = clampPct(pct)
    return v > 0 ? Math.round(100 / v) : null
}
