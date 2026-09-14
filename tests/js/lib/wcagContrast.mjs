// WCAG contrast helper for the a11y pins. DB-free, no browser.
// Converts oklch(...) or #hex to sRGB, then computes the WCAG 2.1 ratio.
// color-mix(in oklch, A p%, B) is evaluated in OKLab (matches the browser
// closely for near-neutral pairs; the pins carry margin over the floor).

export function oklchToRgb(L, C, H) {
    const h = H * Math.PI / 180;
    const a = C * Math.cos(h), b = C * Math.sin(h);
    const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
    const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
    const s_ = L - 0.0894841775 * a - 1.2914855480 * b;
    const l = l_ ** 3, m = m_ ** 3, s = s_ ** 3;
    const r = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
    const g = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
    const bb = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s;
    const toGamma = x => { x = Math.max(0, Math.min(1, x)); return x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055; };
    return [toGamma(r), toGamma(g), toGamma(bb)].map(v => Math.round(v * 255));
}
export function hexToRgb(hex) { hex = hex.replace('#', ''); return [0, 2, 4].map(i => parseInt(hex.slice(i, i + 2), 16)); }

function hexToLinear([r, g, b]) { const f = c => { c /= 255; return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }; return [f(r), f(g), f(b)]; }
function linearToOklab([r, g, b]) {
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    return [0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s, 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s, 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s];
}
function oklabToRgb([L, a, b]) {
    const l_ = L + 0.3963377774 * a + 0.2158037573 * b, m_ = L - 0.1055613458 * a - 0.0638541728 * b, s_ = L - 0.0894841775 * a - 1.2914855480 * b;
    const l = l_ ** 3, m = m_ ** 3, s = s_ ** 3;
    const r = 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, g = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, bb = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s;
    const toGamma = x => { x = Math.max(0, Math.min(1, x)); return x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055; };
    return [toGamma(r), toGamma(g), toGamma(bb)].map(v => Math.round(v * 255));
}

// oklab-space linear interpolation of two colors given as rgb arrays.
export function mixOklab(rgbA, pctA, rgbB) {
    const A = linearToOklab(hexToLinear(rgbA)), B = linearToOklab(hexToLinear(rgbB));
    const p = pctA / 100, q = 1 - p;
    return oklabToRgb([A[0] * p + B[0] * q, A[1] * p + B[1] * q, A[2] * p + B[2] * q]);
}

// Parse a single color literal: oklch(...) or #hex. Unknown -> throws.
export function parseColor(s) {
    s = String(s).trim();
    const m = s.match(/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)/i);
    if (m) return oklchToRgb(+m[1], +m[2], +m[3]);
    if (s.startsWith('#')) return hexToRgb(s);
    throw new Error('unrecognised color: ' + s);
}

function relLum([r, g, b]) { const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); }
export function contrast(rgbA, rgbB) { const a = relLum(rgbA), b = relLum(rgbB); const hi = Math.max(a, b), lo = Math.min(a, b); return (hi + 0.05) / (lo + 0.05); }
