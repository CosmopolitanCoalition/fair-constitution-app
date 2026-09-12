import assert from 'node:assert/strict';
import { h, createTextVNode, createCommentVNode, Fragment } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { referenceTextNode } from '../../resources/js/lib/referenceTextNodes.js';

const comment = createCommentVNode('F-EXE001');
const hidden = h('input', { type: 'hidden', name: 'form_id', value: 'F-EXE001' });
const source = h('section', { 'data-role': 'R09' }, [
    h('a', { href: '/forms/F-EXE001', title: 'F-EXE001' }, 'File F-EXE001'),
    hidden, h('p', [createTextVNode('CLK09'), ' and WF-EXE04']), comment,
    h(Fragment, [createTextVNode('R18')]),
]);
source.dynamicChildren = [source.children[0]];
const rendered = referenceTextNode(source);
assert.equal(source.children[0].children, 'File F-EXE001', 'Original slot nodes are never mutated');
assert.equal(rendered.children[0].children, 'File Board of Governors Nomination');
assert.deepEqual(rendered.children[0].props, source.children[0].props, 'Links and attributes preserve exact identifiers');
assert.equal(rendered.children[1].props.value, 'F-EXE001', 'Submitted hidden form identifiers remain unchanged');
assert.equal(rendered.props['data-role'], 'R09', 'Authority identifiers remain unchanged');
assert.equal(rendered.children[3], comment, 'Comments are not player text');
assert.equal(rendered.dynamicChildren, null, 'Cloned blocks cannot patch the old text tree');
const html = await renderToString(rendered);
assert.match(html, /Judicial \/ Civil Officer Term and Department Creation/);
assert.match(html, /Department governor/);
assert.match(html, /href="\/forms\/F-EXE001"/);
assert.match(html, /value="F-EXE001"/);
const escaped = await renderToString(referenceTextNode(h('p', 'F-EXE001 <script>alert(1)</script>')));
assert.match(escaped, /&lt;script&gt;/, 'Text is still escaped, never parsed as HTML');
assert.equal(referenceTextNode(createTextVNode('R18'), () => 'Translated governor').children, 'Translated governor');
console.log('Rendered labels preserve attributes, payloads, source nodes, and HTML escaping.');
