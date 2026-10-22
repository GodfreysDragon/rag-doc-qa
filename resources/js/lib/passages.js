const STOPWORDS = new Set(
    'the and for are but not you your yours with that this these those from into onto over under than then there their they them what when where which who whom why how can could should would will shall may might must have has had was were been being does did doing its our ours out any all each per also only just more most some such very about after before between during within without'.split(
        ' ',
    ),
);

/** A single shared word is too weak a signal to point at a sentence. */
const MIN_SCORE = 2;

/**
 * Lower-case words and numbers worth matching on: numbers of any length,
 * other words of three characters or more that aren't stopwords.
 *
 * @param {string} text
 * @returns {string[]}
 */
function terms(text) {
    return (text.toLowerCase().match(/[\p{L}\p{N}]+/gu) ?? []).filter(
        (term) => /\d/.test(term) || (term.length > 2 && !STOPWORDS.has(term)),
    );
}

/**
 * The claim a citation button supports: the answer text in the same block
 * before the button, back to the previous citation.
 *
 * @param {HTMLElement} button
 * @returns {string}
 */
export function claimForCitation(button) {
    const block = button.closest('p, li, td, th, blockquote, h1, h2, h3, h4, h5, h6') ?? button.parentElement;
    const range = document.createRange();
    range.setStart(block, 0);
    range.setEndBefore(button);

    const fragment = range.cloneContents();
    let text = '';
    let previousClaim = '';

    // Citations and text in document order, at any depth (e.g. inside <strong>).
    const walker = document.createTreeWalker(fragment, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
        acceptNode: (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.parentElement?.closest('.citation') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
            return node.classList.contains('citation') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
        },
    });

    while (walker.nextNode()) {
        const node = walker.currentNode;

        if (node.nodeType === Node.ELEMENT_NODE) {
            // An earlier citation ends the previous claim.
            if (terms(text).length > 0) {
                previousClaim = text;
            }
            text = '';
        } else {
            text += node.textContent;
        }
    }

    // Nothing said since the last citation, as in "[1][2]": both support the same claim.
    if (terms(text).length === 0) {
        text = previousClaim;
    }

    return text.trim();
}

/**
 * Find the sentence (or line) of a source chunk that best supports a claim,
 * by the claim terms it contains; numbers count double because they're the
 * most specific thing a claim can share with its source.
 *
 * @param {string} content
 * @param {string} claim
 * @returns {{ start: number, end: number } | null}
 */
export function findSupportingPassage(content, claim) {
    const claimTerms = new Set(terms(claim));

    if (claimTerms.size === 0) {
        return null;
    }

    let best = null;

    for (const match of content.matchAll(/[^\n]+?(?:[.!?]+(?=\s|$)|(?=\n)|$)/g)) {
        const sentenceTerms = new Set(terms(match[0]));
        let score = 0;

        for (const term of claimTerms) {
            if (sentenceTerms.has(term)) {
                score += /\d/.test(term) ? 2 : 1;
            }
        }

        const length = match[0].trim().length;

        if (score >= MIN_SCORE && (best === null || score > best.score || (score === best.score && length < best.length))) {
            const start = match.index + (match[0].length - match[0].trimStart().length);
            best = { score, length, start, end: start + length };
        }
    }

    return best && { start: best.start, end: best.end };
}
