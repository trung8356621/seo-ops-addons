/**
 * Fix orphan literal quote characters that sit OUTSIDE paragraph/blockquote nodes
 * (legacy paste / bad serialization). Does NOT strip user quotes inside editable text.
 */

const QUOTE_CHARS = '"\u201C\u201D\u2018\u2019';

/**
 * @param {string} html
 * @returns {string}
 */
export function normalizeOrphanQuoteCharacters(html) {
    const source = String(html ?? '');
    if (source.trim() === '') {
        return source;
    }

    let next = source;

    // Quote after </p> still inside outer block: </p>"</blockquote> → "</p></blockquote>
    next = next.replace(
        new RegExp(
            `(</p>)(\\s*)([${QUOTE_CHARS}])(\\s*)(</(?:blockquote|li)>)`,
            'giu',
        ),
        (_full, pClose, _ws1, quote, _ws2, outerClose) => `${quote}${pClose}${outerClose}`,
    );

    // Quote immediately after a closing block tag → move inside that block before </tag>
    // </p>"  →  "</p>   (append before close)
    next = next.replace(
        new RegExp(`(<\\/(p|h[1-6]|li|blockquote)>)(\\s*[${QUOTE_CHARS}])`, 'giu'),
        (_, close, _tag, quotePart) => {
            const quote = String(quotePart).trim();
            return `${quote}${close}`;
        },
    );

    // Quote immediately before an opening block after another close:
    // </p>\n"\n<p> → </p><p>"
    next = next.replace(
        new RegExp(
            `(</(?:p|h[1-6]|li|blockquote)>)(\\s*)([${QUOTE_CHARS}])(\\s*)(<(?:p|h[1-6]|li|blockquote)\\b)`,
            'giu',
        ),
        (_full, close, _ws1, quote, _ws2, open) => `${close}${open}${quote}`,
    );

    // Leading orphan before first block: " <p> → <p>"
    next = next.replace(
        new RegExp(`^(\\s*)([${QUOTE_CHARS}])(\\s*)(<(?:p|h[1-6]|blockquote)\\b)`, 'u'),
        (_full, _ws1, quote, _ws2, open) => `${open}${quote}`,
    );

    // Trailing orphan after last block: </p>" → "</p>
    next = next.replace(
        new RegExp(`(<\\/(?:p|h[1-6]|li|blockquote)>)(\\s*)([${QUOTE_CHARS}])(\\s*)$`, 'u'),
        (_full, close, _ws1, quote) => `${quote}${close}`,
    );

    return next;
}

/**
 * True when HTML has quote characters as siblings outside block tags (debug/tests).
 *
 * @param {string} html
 * @returns {boolean}
 */
export function hasOrphanQuoteOutsideBlocks(html) {
    const source = String(html ?? '');
    return new RegExp(
        `</(?:p|h[1-6]|li|blockquote)>\\s*[${QUOTE_CHARS}]|[${QUOTE_CHARS}]\\s*<(?:p|h[1-6]|blockquote)\\b`,
        'u',
    ).test(source);
}
