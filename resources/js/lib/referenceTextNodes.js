import { cloneVNode, isVNode, Comment } from 'vue';
import { readableReferences } from './referenceLabels.js';

/** Replace visible text only; never touch identifiers in props, links, or inputs. */
export function referenceTextNode(node, translate) {
    if (typeof node === 'string') return readableReferences(node, translate);
    if (!isVNode(node) || node.type === Comment) return node;
    const copy = cloneVNode(node);
    // A cloned block must patch its cloned children, not the original optimized tree.
    copy.dynamicChildren = null;
    if (typeof node.children === 'string') copy.children = readableReferences(node.children, translate);
    else if (Array.isArray(node.children)) copy.children = node.children.map((child) => referenceTextNode(child, translate));
    return copy;
}
