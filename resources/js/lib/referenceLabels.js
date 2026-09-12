import registry from '../registry/referenceLabels.generated.js';
import { FLOWS_BY_SURFACE } from '../registry/flows.js';
import { ROLE_LABELS } from './roles.js';

const workflowLabels = Object.fromEntries(Object.values(FLOWS_BY_SURFACE).flat().map((flow) => [flow.wf, flow.wfName]));
// Legacy registry wording says legislature size; these constraints govern districts.
const clockLabels = { ...registry.clocks, 'CLK-07': 'District maximum seats', 'CLK-08': 'District minimum seats' };
const fallbackLabel = { form: 'Civic action', workflow: 'Civic process', clock: 'Timing rule', role: 'Civic role' };

export function normalizeReference(code) {
    return String(code ?? '').trim()
        .replace(/^(WF-[A-Z]+|F-[A-Z]+)-?(\d+)$/, '$1-$2')
        .replace(/^(CLK|R)-?(\d+)$/, '$1-$2');
}

export function referenceLabel(code, { name = null, translate = (_key, fallback) => fallback } = {}) {
    const id = normalizeReference(code);
    const kind = id.startsWith('WF-') ? 'workflow' : id.startsWith('CLK-') ? 'clock' : id.startsWith('R-') ? 'role' : 'form';
    const canonical = registry.aliases[id] ?? id;
    const labels = { form: registry.forms, workflow: workflowLabels, clock: clockLabels, role: ROLE_LABELS }[kind];
    // Supplied names come from SurfaceMeta/FormRegistry, or a deliberate contextual label.
    const label = name && !/^(?:WF-|F-|CLK-|R-)\S+$/.test(name) ? name : labels[canonical] ?? fallbackLabel[kind];
    return translate('c_references.' + kind + '.' + canonical, label);
}

/** Display-only text replacement. Opaque identifiers in props/attributes/payloads stay untouched. */
export function readableReferences(value, translate) {
    return String(value ?? '').replace(/\b(?:WF-[A-Z]+-?\d+|F-[A-Z]+-?\d+|CLK-?\d+|R-?\d+)\b/g,
        (code) => referenceLabel(code, { translate }));
}

export function referenceCatalog() {
    return { ...registry, clocks: clockLabels, workflows: workflowLabels, roles: ROLE_LABELS };
}

// These legacy setting keys describe districts, never the whole legislature.
const settingLabels = {
    legislature_min_seats: 'District minimum seats',
    legislature_max_seats: 'District maximum seats',
    election_interval_months: 'Election interval (months)',
};

export function settingLabel(key, { name = null, translate = (_key, fallback) => fallback } = {}) {
    if (name) return name;
    const words = String(key ?? '').replaceAll('_', ' ').replaceAll('-', ' ');
    const label = settingLabels[key] || (words.charAt(0).toUpperCase() + words.slice(1));
    return translate('c_references.setting.' + key, label);
}
