/**
 * Money formatting for the economy surfaces.
 *
 * TWO RULES FROM THE PROP CONTRACT (docs/plans/economy/ECONOMY_PROP_CONTRACT.md),
 * and both are load-bearing here:
 *
 *   1. MONEY IS A STRING. The ledger stores numeric(24,6); putting that through
 *      a JS number silently loses precision, and a page that does arithmetic on
 *      a balance is wrong anyway. Everything below is STRING work — no
 *      parseFloat, no Number(), no toFixed. Format it, never compute it.
 *
 *   2. NOTHING IS ABSENT — but formatters still must not throw. The v3 mapper
 *      froze on exactly this: a panel read a key the backend never shipped,
 *      `formatPop(undefined).toLocaleString()` threw INSIDE render, the Vue
 *      patch aborted, and the DOM sat frozen at the last paint. It presented as
 *      a hang, not a type error. So every function here takes undefined/null
 *      without complaint and returns something printable.
 *
 * Trailing zeros are trimmed only down to the currency's own precision, never
 * past a significant digit: "45.000000" at precision 2 reads "45.00", but
 * "45.123456" keeps all six. Money is never quietly rounded away on screen.
 */

/** Group the integer part in threes: "1234567" → "1,234,567". String work only. */
function groupThousands(intPart) {
    const negative = intPart.startsWith('-');
    const digits = negative ? intPart.slice(1) : intPart;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return negative ? `-${grouped}` : grouped;
}

/**
 * Format a money string for display.
 *
 * @param {string|null|undefined} amount  e.g. "45.000000"
 * @param {object|null|undefined} currency  { symbol, precision } — may be null
 * @returns {string} always printable, never throws
 */
export function formatMoney(amount, currency = null) {
    const symbol = currency?.symbol ?? '';
    const precision = Number.isInteger(currency?.precision) ? currency.precision : 2;

    if (amount === null || amount === undefined || amount === '') {
        return `${symbol}—`;
    }

    const raw = String(amount).trim();
    if (!/^-?\d+(\.\d+)?$/.test(raw)) {
        // Not a shape we recognise — show it rather than mangle or throw.
        return `${symbol}${raw}`;
    }

    const [intPart, fracRaw = ''] = raw.split('.');

    // Trim trailing zeros, but never below the currency's precision.
    let frac = fracRaw.replace(/0+$/, '');
    while (frac.length < precision) frac += '0';

    const whole = groupThousands(intPart);
    return frac.length > 0 ? `${symbol}${whole}.${frac}` : `${symbol}${whole}`;
}

/** True when a money string is exactly zero, whatever its trailing zeros. */
export function isZeroMoney(amount) {
    if (amount === null || amount === undefined) return false;
    return /^-?0(\.0+)?$/.test(String(amount).trim());
}

/** Plain integer counts: 1234 → "1,234". Null-safe. */
export function formatCount(n) {
    if (n === null || n === undefined || n === '') return '—';
    const raw = String(n).trim();
    if (!/^-?\d+$/.test(raw)) return raw;
    return groupThousands(raw);
}

/**
 * An ISO timestamp as a readable local date-time. Null-safe, and never throws
 * on a malformed string — an unparseable date returns the raw value, because
 * showing something odd beats blanking a whole row.
 */
export function formatWhen(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Shorten a uuid for display without pretending it is a name. */
export function shortId(id) {
    if (!id) return '—';
    const s = String(id);
    return s.length <= 12 ? s : `${s.slice(0, 8)}…`;
}
