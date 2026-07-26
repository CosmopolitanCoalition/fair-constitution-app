<script setup>
/**
 * TEMPORARY — lane 13, 2026-07-25. @lane-06 replaces this with the real pages.
 *
 * Why it exists: the economy routes shipped ahead of the pages (lane 7's
 * ruling — nothing stalls while lane 6 is parked), and app.js resolves page
 * components over a `Pages/**\/*.vue` glob where a MISS THROWS. So a missing
 * component is not a polite fallback, it is a blank white page: the server
 * returns 200 and the break is entirely client-side.
 *
 * Behind these routes sits a real economy — a hash-chained ledger, wallets, a
 * completed stipend run, a settled sale. A blank page would say "broken" about
 * the most finished thing on the box.
 *
 * So this renders the ACTUAL props, plainly and unstyled. It is deliberately
 * NOT a design: no layout, no chrome, no components borrowed from the design
 * system. That is lane 6's half of the split and this must not pre-empt it —
 * it exists to prove the data is there and to be deleted.
 */
defineProps({
  title: { type: String, required: true },
  blurb: { type: String, default: '' },
  data: { type: Object, default: () => ({}) },
})

// Null-safe by construction: money crosses the boundary as a string and
// collections are always arrays, but this stub must never throw even if that
// contract is violated — a formatter that throws inside render is the exact
// failure this page exists to prevent.
function describe (value) {
  if (value === null || value === undefined) return '—'
  if (Array.isArray(value)) return `${value.length} row${value.length === 1 ? '' : 's'}`
  if (typeof value === 'object') {
    return Object.entries(value)
      .map(([k, v]) => `${k}: ${v === null || v === undefined ? '—' : (typeof v === 'object' ? '…' : String(v))}`)
      .join(' · ')
  }
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  return String(value)
}
</script>

<template>
  <div style="max-width: 60rem; margin: 0 auto; padding: 2rem 1.5rem; font-family: system-ui, sans-serif; color: #e5e7eb; background: #0b0f19; min-height: 100vh;">
    <p style="font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase; color: #93c5fd; margin: 0 0 0.5rem;">
      Economy · placeholder screen
    </p>

    <h1 style="font-size: 1.75rem; font-weight: 700; color: #fff; margin: 0 0 0.75rem;">{{ title }}</h1>

    <p v-if="blurb" style="color: #9ca3af; margin: 0 0 1.5rem; line-height: 1.6;">{{ blurb }}</p>

    <div style="background: #111827; border: 1px solid #1f2937; border-radius: 0.5rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
      <p style="margin: 0; color: #d1d5db; line-height: 1.6;">
        <strong style="color: #fff;">The data below is real.</strong>
        The engine, the ledger and this route are built and tested; the designed screen
        is still being built. Every value here came from the live database through the
        published prop contract.
      </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
      <tbody>
        <tr v-for="(value, key) in data" :key="key" style="border-bottom: 1px solid #1f2937;">
          <td style="padding: 0.6rem 0.75rem 0.6rem 0; color: #9ca3af; vertical-align: top; white-space: nowrap;">{{ key }}</td>
          <td style="padding: 0.6rem 0; color: #e5e7eb;">{{ describe(value) }}</td>
        </tr>
      </tbody>
    </table>

    <p style="margin-top: 2rem; font-size: 0.8rem; color: #6b7280;">
      Placeholder pending the designed economy screens · contract:
      <code style="color: #93c5fd;">docs/plans/economy/ECONOMY_PROP_CONTRACT.md</code>
    </p>
  </div>
</template>
