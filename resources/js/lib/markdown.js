import DOMPurify from 'dompurify';
import { marked } from 'marked';

marked.setOptions({ gfm: true, breaks: false });

/**
 * Render an assistant answer: Markdown → sanitised HTML, with citation
 * markers like [2] turned into buttons that point at the matching source.
 * Only ids that correspond to a real source become buttons, so a stray
 * "[1]" inside a code sample or a hallucinated id stays as plain text.
 *
 * @param {string} text
 * @param {number} sourceCount
 */
export function renderAnswer(text, sourceCount) {
    const html = DOMPurify.sanitize(marked.parse(text ?? ''));

    if (sourceCount === 0) {
        return html;
    }

    const container = document.createElement('div');
    container.innerHTML = html;

    const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, {
        acceptNode: (node) =>
            node.parentElement?.closest('code, pre') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });

    const textNodes = [];
    while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
    }

    for (const node of textNodes) {
        const parts = node.textContent.split(/(\[\d+\])/g);

        if (parts.length === 1) {
            continue;
        }

        const fragment = document.createDocumentFragment();

        for (const part of parts) {
            const match = part.match(/^\[(\d+)\]$/);
            const id = match ? Number(match[1]) : null;

            if (id !== null && id >= 1 && id <= sourceCount) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'citation';
                button.dataset.source = String(id);
                button.textContent = String(id);
                button.setAttribute('aria-label', `Show source ${id}`);
                fragment.append(button);
            } else if (part !== '') {
                fragment.append(document.createTextNode(part));
            }
        }

        node.replaceWith(fragment);
    }

    return container.innerHTML;
}
