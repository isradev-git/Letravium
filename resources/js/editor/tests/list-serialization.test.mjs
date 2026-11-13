import test from 'node:test';
import assert from 'node:assert/strict';

import { serializeDocument } from '../document.mjs';

function fakeDocument() {
    return {
        createTreeWalker(root, _show, filter) {
            const nodes = [];

            (function walk(node) {
                for (const child of node.childNodes ?? []) {
                    if (child.nodeType === 3) {
                        nodes.push(child);
                    } else {
                        walk(child);
                    }
                }
            })(root);

            let index = -1;
            let current = null;

            return {
                get currentNode() {
                    return current;
                },
                nextNode() {
                    while (++index < nodes.length) {
                        const node = nodes[index];
                        const accepted = !filter
                            || (filter.acceptNode ? filter.acceptNode(node) === 1 : filter(node));

                        if (accepted) {
                            current = node;
                            return true;
                        }
                    }

                    return false;
                },
            };
        },
    };
}

function text(value, parent) {
    return { nodeType: 3, nodeValue: value, parentNode: parent };
}

function element(tagName, children, dataset = {}) {
    const node = {
        nodeType: 1,
        tagName,
        dataset,
        children,
        childNodes: children,
        parentNode: null,
        querySelectorAll() {
            return [];
        },
    };

    for (const child of children) {
        child.parentNode = node;
    }

    return node;
}

test('nested lists do not duplicate their child text in the parent item', () => {
    const doc = fakeDocument();

    const childText = text('Hijo', null);
    const childItem = element('LI', [childText], { blockId: 'l2' });
    const childList = element('UL', [childItem]);
    const parentText = text('Padre', null);
    const parentItem = element('LI', [parentText, childList], { blockId: 'l1' });
    parentItem.querySelectorAll = (selector) => selector.includes('ul') ? [childList] : [];
    const list = element('UL', [parentItem]);

    for (const node of [list, parentItem, childList, childItem]) {
        node.ownerDocument = doc;
    }

    const blocks = serializeDocument({ childNodes: [list] }).blocks;

    assert.deepEqual(blocks.map((block) => block.text), ['Padre', 'Hijo']);
    assert.deepEqual(blocks.map((block) => block.depth), [0, 1]);
});
