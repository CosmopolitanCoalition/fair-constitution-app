// node --experimental-vm-modules --test tests/js/a11yStaticScan.test.mjs
//
// L2 accessibility, pass 1 (static). This is the accessible-name scan over
// EVERY .vue file under resources/js. It parses each Single File Component
// template with the installed @vue/compiler-sfc / @vue/compiler-dom and checks
// that every interactive or media element that needs an accessible name has
// one from a real source: text content, default slot content, aria-label,
// :aria-label, aria-labelledby, alt, :alt, title, :title, or a label
// association for form controls. It distinguishes a genuinely missing name
// from a name the parent supplies through a default <slot>, so it neither
// over-reports (a <slot> IS a name source) nor under-reports (an icon-only
// control with no slot and no aria-label IS a defect).
//
// The scan is pinned to tests/js/a11y-baseline.json. That file records the
// known accessible-name gaps as a punch list whose goal is zero. The run FAILS
// only on a finding not in the baseline (a new gap) and PASSES otherwise. A
// baseline gap that no longer appears in the code is reported as fixed and does
// not fail the run; remove it from the baseline to lower the count.
//
// Out of scope for THIS pass, recorded as not established: keyboard traversal
// order, focus visibility, colour contrast, and narrow-layout reflow. Those
// are browser checks and belong to the L2 browser pass in another lane. They
// are marked skipped below with that reason, never reported as passing.

import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import test from 'node:test';
import { parse as parseSfc } from '@vue/compiler-sfc';
import * as CompilerDOM from '@vue/compiler-dom';

// CompilerDOM.parse is the DOM-configured template parser: it is void-tag
// aware (<input>, <br>, <img>, ... need no end tag) and still pre-transform,
// so v-if / v-for stay as directives on ordinary ELEMENT nodes and a plain
// children recursion reaches every element. CompilerDOM.baseParse is the raw
// CORE parser and is NOT void aware, so it throws "Element is missing end tag"
// on a valid <input ...> written without a self-closing slash — the same
// templates that production (@vue/compiler-sfc with DOM options) parses fine.
// Prefer parse; fall back to baseParse with the DOM parserOptions, which carry
// isVoidTag. Never use bare baseParse: it aborts the scan on valid input.
const parseTemplate = typeof CompilerDOM.parse === 'function'
    ? (src) => CompilerDOM.parse(src)
    : (src) => CompilerDOM.baseParse(src, CompilerDOM.parserOptions ?? {});

// Node/element type tags from the compiler. Fall back to the documented
// numeric constants when the build does not re-export the enums.
const T = CompilerDOM.NodeTypes ?? {};
const E = CompilerDOM.ElementTypes ?? {};
const T_ELEMENT = T.ELEMENT ?? 1;
const T_TEXT = T.TEXT ?? 2;
const T_INTERPOLATION = T.INTERPOLATION ?? 5;
const T_ATTRIBUTE = T.ATTRIBUTE ?? 6;
const T_DIRECTIVE = T.DIRECTIVE ?? 7;
const EL_SLOT = E.SLOT ?? 2;

const jsRoot = fileURLToPath(new URL('../../resources/js', import.meta.url));

function vueFiles() {
    return readdirSync(jsRoot, { recursive: true, encoding: 'utf8' })
        .filter(rel => rel.endsWith('.vue'))
        .map(rel => rel.split(path.sep).join('/'))
        .sort();
}

// --- attribute / directive readers -------------------------------------------------

// A static attribute: `role="img"` -> 'img'; `alt=""` -> ''; absent -> undefined.
function staticAttr(node, name) {
    const p = node.props?.find(p => p.type === T_ATTRIBUTE && p.name === name);
    return p ? (p.value?.content ?? '') : undefined;
}
// A bound attribute: `:aria-label="x"` / `v-bind:aria-label="x"` -> 'x'; absent -> undefined.
function boundAttr(node, name) {
    const p = node.props?.find(p =>
        p.type === T_DIRECTIVE && p.name === 'bind' && p.arg && p.arg.content === name);
    return p ? (p.exp?.content ?? '') : undefined;
}
function attrPresent(node, name) {
    return staticAttr(node, name) !== undefined || boundAttr(node, name) !== undefined;
}
function hasDirective(node, dname) {
    return !!node.props?.some(p => p.type === T_DIRECTIVE && p.name === dname);
}
// Non-empty static value, or any binding.
function namePresent(node, name) {
    const s = staticAttr(node, name);
    if (s !== undefined && s.trim() !== '') return true;
    return boundAttr(node, name) !== undefined;
}
function exempt(node) {
    if (staticAttr(node, 'aria-hidden') === 'true') return true;
    const role = staticAttr(node, 'role');
    return role === 'presentation' || role === 'none';
}

// Accessible name from rendered text: static text, an interpolation ({{ }}),
// v-html / v-text, or a <slot> (the parent supplies the text). Recurses so a
// button wrapping a <span>label</span> is named.
function hasTextName(node) {
    if (hasDirective(node, 'html') || hasDirective(node, 'text')) return true;
    for (const c of node.children ?? []) {
        if (c.type === T_TEXT && c.content && c.content.trim() !== '') return true;
        if (c.type === T_INTERPOLATION) return true;
        if (c.type === T_ELEMENT) {
            if (c.tagType === EL_SLOT || c.tag === 'slot') return true;
            if (hasTextName(c)) return true;
        }
    }
    return false;
}

// A control named by text/aria/title (buttons, links, role=button/link).
function hasControlName(node) {
    if (exempt(node)) return true;
    if (namePresent(node, 'aria-label')) return true;
    if (attrPresent(node, 'aria-labelledby')) return true;
    if (namePresent(node, 'title')) return true;
    return hasTextName(node);
}
// An <img> is named by alt (even alt="" = decorative, which is a valid,
// deliberate absence of a name), :alt, aria-label, or aria-labelledby.
function imgNamed(node) {
    if (exempt(node)) return true;
    if (attrPresent(node, 'alt')) return true;
    if (namePresent(node, 'aria-label')) return true;
    return attrPresent(node, 'aria-labelledby');
}
// video / audio / iframe / role=img: named by aria-label, aria-labelledby, or
// title. There is no text-content or slot name source for these.
function mediaNamed(node) {
    if (exempt(node)) return true;
    if (namePresent(node, 'aria-label')) return true;
    if (attrPresent(node, 'aria-labelledby')) return true;
    return namePresent(node, 'title');
}

// --- template walk -----------------------------------------------------------------

function scanFile(rel) {
    const file = path.join(jsRoot, rel.split('/').join(path.sep));
    const source = readFileSync(file, 'utf8');
    const { descriptor, errors } = parseSfc(source, { filename: rel });
    const findings = [];
    const liveRegions = [];
    if (!descriptor.template) return { findings, liveRegions, parseErrors: errors ?? [] };

    // Map a template-AST node back to its absolute line in the .vue source.
    // The template block's content starts at descriptor.template.loc.start.offset
    // in the original source; the node's own offset is relative to that content.
    // Counting newlines up to the summed offset is exact regardless of how the
    // compiler numbers lines inside the isolated template block.
    const tplStartOffset = descriptor.template.loc.start.offset;
    // A thrown parse is recorded as a parse error and the file is skipped, so
    // one unparsable template can never abort the whole scan (the defect that
    // let bare baseParse stop the run at file 1 of 271).
    let ast;
    try {
        ast = parseTemplate(descriptor.template.content);
    } catch (e) {
        return {
            findings,
            liveRegions,
            parseErrors: [...(errors ?? []), { message: `template parse threw: ${String(e?.message ?? e)}` }],
        };
    }
    const line = node => {
        const abs = tplStartOffset + (node.loc?.start?.offset ?? 0);
        let n = 1;
        for (let i = 0; i < abs && i < source.length; i += 1) if (source[i] === '\n') n += 1;
        return n;
    };

    // First: collect every <label>'s association target, static and dynamic.
    const labelForStatic = new Set();
    let labelForDynamic = false;
    (function collectLabels(node) {
        if (node.type === T_ELEMENT && node.tag === 'label') {
            const f = staticAttr(node, 'for');
            if (f !== undefined) labelForStatic.add(f);
            if (boundAttr(node, 'for') !== undefined) labelForDynamic = true;
        }
        for (const c of node.children ?? []) collectLabels(c);
    })(ast);

    function controlHasLabel(node, inLabel, inControlSlot) {
        const type = (staticAttr(node, 'type') ?? '').toLowerCase();
        if (node.tag === 'input' && type === 'hidden') return true;
        if (node.tag === 'input' && (type === 'submit' || type === 'button' || type === 'reset')) {
            if (attrPresent(node, 'value')) return true;
        }
        if (exempt(node)) return true;
        if (namePresent(node, 'aria-label')) return true;
        if (attrPresent(node, 'aria-labelledby')) return true;
        if (namePresent(node, 'title')) return true;
        if (inLabel) return true;
        const id = staticAttr(node, 'id');
        if (id !== undefined && labelForStatic.has(id)) return true;
        // A dynamic id (:id) cannot be matched to a static for, but if the file
        // pairs it with a dynamic :for label the association holds. Treat that
        // as named to avoid over-reporting the common Field-wrapper pattern.
        if (boundAttr(node, 'id') !== undefined && labelForDynamic) return true;
        // Design-system control slot. Ui/Field is the only component that
        // defines <slot name="control" :id="fieldId">, and it always renders
        // <label class="field-label" :for="fieldId"> above the slot, passing
        // that SAME fieldId in as the slot's :id. So a native control placed in
        // a `<template #control="{ id }">` slot and bound `:id="id"` is
        // programmatically labelled by the wrapper, even though the <label> is
        // in Field.vue and not in this file. Without this the scan flags every
        // one of the ~219 Field-slotted controls as unlabelled (false
        // positive). The label lives across the component boundary; the id
        // binding is the association.
        if (inControlSlot && boundAttr(node, 'id') !== undefined) return true;
        return false;
    }

    function add(node, kind, detail) {
        findings.push({ file: rel, line: line(node), kind, detail });
    }

    // A `<template #control="...">` (v-slot named 'control') element. Its
    // descendant native controls are wired to the Ui/Field wrapper's <label>.
    function isControlSlotTemplate(node) {
        return node.type === T_ELEMENT && node.tag === 'template'
            && !!node.props?.some(p =>
                p.type === T_DIRECTIVE && p.name === 'slot' && p.arg && p.arg.content === 'control');
    }

    (function walk(node, inLabel, inControlSlot) {
        if (node.type === T_ELEMENT) {
            const tag = node.tag;
            const role = staticAttr(node, 'role');

            // Interactive controls that need an accessible name.
            if (tag === 'button' && !hasControlName(node)) {
                add(node, 'button-no-name', 'native <button> has no text, slot, or aria-label');
            }
            if (tag === 'a' && (attrPresent(node, 'href')) && !hasControlName(node)) {
                add(node, 'link-no-name', '<a href> has no text, slot, or aria-label');
            }
            if (role === 'button' && tag !== 'button' && !hasControlName(node)) {
                add(node, 'role-button-no-name', 'role="button" element has no accessible name');
            }
            if (role === 'link' && tag !== 'a' && !hasControlName(node)) {
                add(node, 'role-link-no-name', 'role="link" element has no accessible name');
            }

            // Images.
            if (tag === 'img' && !imgNamed(node)) {
                add(node, 'img-no-alt', '<img> has no alt / :alt / aria-label');
            }
            if (role === 'img' && tag !== 'img' && !mediaNamed(node)) {
                add(node, 'role-img-no-name', 'role="img" element has no aria-label / title');
            }

            // Time-based media and embedded frames.
            if ((tag === 'video' || tag === 'audio') && !mediaNamed(node)) {
                add(node, `${tag}-no-name`, `<${tag}> has no aria-label / :aria-label / title`);
            }
            if (tag === 'iframe' && !mediaNamed(node)) {
                add(node, 'iframe-no-title', '<iframe> has no title / aria-label');
            }

            // Form controls need a label association.
            if ((tag === 'input' || tag === 'select' || tag === 'textarea') && !controlHasLabel(node, inLabel, inControlSlot)) {
                add(node, 'control-no-label', `<${tag}> has no label / aria-label / wrapping <label>`);
            }

            // Live regions. A bare aria-live="polite"/"assertive" IS a valid,
            // announced live region, so its absence of a role is not a defect
            // and is not flagged. What IS a defect is a contradiction between a
            // live-region role and an explicit aria-live that fights it
            // (role="status" is implicitly polite; role="alert" is implicitly
            // assertive). Count the well-formed regions for the record.
            const live = staticAttr(node, 'aria-live');
            if (role === 'status' || role === 'alert' || (live !== undefined && live !== 'off')) {
                liveRegions.push({ file: rel, line: line(node), role: role ?? '', live: live ?? '' });
            }
            if (role === 'status' && live !== undefined && live !== 'polite') {
                add(node, 'live-region-mismatch', `role="status" with aria-live="${live}" (status is implicitly polite)`);
            }
            if (role === 'alert' && live !== undefined && live !== 'assertive') {
                add(node, 'live-region-mismatch', `role="alert" with aria-live="${live}" (alert is implicitly assertive)`);
            }

            const childInLabel = inLabel || tag === 'label';
            const childInControlSlot = inControlSlot || isControlSlotTemplate(node);
            for (const c of node.children ?? []) walk(c, childInLabel, childInControlSlot);
            return;
        }
        for (const c of node.children ?? []) walk(c, inLabel, inControlSlot);
    })(ast, false, false);

    return { findings, liveRegions, parseErrors: errors ?? [] };
}

// --- the scan ----------------------------------------------------------------------

test('accessible-name static scan over every .vue under resources/js', () => {
    const files = vueFiles();
    console.log(`[a11y-static] scanning ${files.length} .vue files under resources/js`);

    const allFindings = [];
    const allParseErrors = [];
    const allLiveRegions = [];
    let scanned = 0;
    for (const rel of files) {
        const { findings, liveRegions, parseErrors } = scanFile(rel);
        scanned += 1;
        for (const f of findings) allFindings.push(f);
        for (const r of liveRegions) allLiveRegions.push(r);
        for (const e of parseErrors) allParseErrors.push({ file: rel, message: String(e.message ?? e) });
    }

    console.log(`[a11y-static] scanned ${scanned} files, parse errors: ${allParseErrors.length}`);
    console.log(`[a11y-static] live regions (role=status|alert or aria-live) inspected: ${allLiveRegions.length}`);
    if (allParseErrors.length) {
        for (const e of allParseErrors) console.log(`[a11y-static] PARSE ${e.file}: ${e.message}`);
    }
    console.log(`[a11y-static] accessible-name findings: ${allFindings.length}`);
    for (const f of allFindings) {
        console.log(`[a11y-static] FINDING ${f.file}:${f.line} [${f.kind}] ${f.detail}`);
    }

    // Baseline pinning. a11y-baseline.json is the recorded punch list of known
    // accessible-name gaps. The GOAL IS ZERO: each baseline entry is a real
    // defect for a build lane to repair, and repairing one lowers the count.
    // This assertion does not demand zero today. It demands NO REGRESSION: the
    // run fails only on a finding that is NOT in the baseline (a new gap). A
    // baseline gap that no longer appears in the code is reported as fixed so
    // the entry can be removed from the baseline; it never fails the run.
    // The key is file:line:element, the same key the baseline stores.
    const keyOf = (f) => {
        const m = /<([a-zA-Z]+)>/.exec(f.detail);
        const element = m ? m[1] : f.kind;
        return `${f.file}:${f.line}:${element}`;
    };
    const baselinePath = fileURLToPath(new URL('./a11y-baseline.json', import.meta.url));
    const baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));
    const baselineKeys = new Set((baseline.findings ?? []).map(e => e.key));

    const currentKeys = new Set(allFindings.map(keyOf));
    const newGaps = allFindings.filter(f => !baselineKeys.has(keyOf(f)));
    const remaining = allFindings.filter(f => baselineKeys.has(keyOf(f))).length;
    const fixed = [...baselineKeys].filter(k => !currentKeys.has(k)).sort();

    for (const k of fixed) {
        console.log(`[a11y-static] FIXED ${k} — fixed, remove from baseline`);
    }
    console.log(`a11y static scan: ${remaining} baseline findings remain (goal 0)`);

    assert.equal(allParseErrors.length, 0, 'every .vue template must parse');
    assert.equal(
        newGaps.length, 0,
        `NEW accessible-name gaps not in a11y-baseline.json (regression):\n${newGaps.map(f => `  ${f.file}:${f.line} [${f.kind}] ${f.detail}`).join('\n')}`,
    );
});

test('keyboard traversal order and focus visibility', { skip: 'browser check, L2 browser pass; not established by the static scan' }, () => {});
test('colour contrast measurement', { skip: 'browser check, L2 browser pass; not established by the static scan' }, () => {});
test('narrow-layout reflow at phone width', { skip: 'browser check, L2 browser pass; not established by the static scan' }, () => {});
