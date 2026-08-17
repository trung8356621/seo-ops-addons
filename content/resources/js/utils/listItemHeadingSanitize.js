/**
 * TipTap listItem = `paragraph block*` — heading không được đứng đầu trong li.
 * HTML kiểu `<li><h3>..</h3><p>..</p></li>` làm setContent/insertContentAt ném RangeError.
 *
 * @param {string} html
 * @returns {string}
 */
export function liftHeadingsOutOfListItems(html) {
    const trimmed = String(html ?? '').trim();
    if (!trimmed || !/<li\b/i.test(trimmed) || !/<h[1-6]\b/i.test(trimmed)) {
        return trimmed;
    }

    if (typeof DOMParser === 'undefined') {
        return liftHeadingsOutOfListItemsRegex(trimmed);
    }

    const doc = new DOMParser().parseFromString(trimmed, 'text/html');
    const lists = Array.from(doc.body.querySelectorAll('ul, ol'));
    if (lists.length === 0) {
        return trimmed;
    }

    let changed = false;
    const listHasDirectHeading = (list) => Array.from(list.children).some((li) => (
        li.tagName?.toLowerCase() === 'li'
        && Array.from(li.children).some((child) => /^h[1-6]$/i.test(String(child.tagName || '')))
    ));

    for (const list of lists) {
        if (!list.parentNode || !listHasDirectHeading(list)) {
            continue;
        }

        const parent = list.parentNode;
        const fragment = doc.createDocumentFragment();
        let currentList = null;

        const flushList = () => {
            if (currentList && currentList.childNodes.length > 0) {
                fragment.appendChild(currentList);
            }
            currentList = null;
        };

        const ensureList = () => {
            if (!currentList) {
                currentList = doc.createElement(list.tagName.toLowerCase());
                Array.from(list.attributes).forEach((attr) => {
                    currentList.setAttribute(attr.name, attr.value);
                });
            }

            return currentList;
        };

        Array.from(list.children).forEach((li) => {
            if (li.tagName?.toLowerCase() !== 'li') {
                ensureList().appendChild(li.cloneNode(true));

                return;
            }

            const parts = [];
            let inlineBucket = [];

            const flushInline = () => {
                if (inlineBucket.length === 0) {
                    return;
                }
                const wrap = doc.createElement('li');
                inlineBucket.forEach((node) => wrap.appendChild(node));
                parts.push({ type: 'li', node: wrap });
                inlineBucket = [];
            };

            Array.from(li.childNodes).forEach((child) => {
                if (child.nodeType === 1 && /^h[1-6]$/i.test(child.tagName)) {
                    flushInline();
                    parts.push({ type: 'heading', node: child.cloneNode(true) });
                    changed = true;

                    return;
                }
                inlineBucket.push(child.cloneNode(true));
            });
            flushInline();

            if (parts.length === 0) {
                return;
            }

            if (parts.length === 1 && parts[0].type === 'li') {
                ensureList().appendChild(parts[0].node);

                return;
            }

            parts.forEach((part) => {
                if (part.type === 'heading') {
                    flushList();
                    fragment.appendChild(part.node);
                } else {
                    ensureList().appendChild(part.node);
                }
            });
        });

        flushList();
        parent.replaceChild(fragment, list);
    }

    if (!changed) {
        return trimmed;
    }

    return Array.from(doc.body.childNodes)
        .map((node) => (node.nodeType === 1 ? node.outerHTML : (node.textContent || '')))
        .join('')
        .trim();
}

/**
 * Node/unit-test fallback (no DOMParser): single-item list with a heading child.
 *
 * @param {string} html
 * @returns {string}
 */
function liftHeadingsOutOfListItemsRegex(html) {
    const pattern = /^<(ul|ol)(\b[^>]*)?>\s*<li(\b[^>]*)?>\s*(<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>)\s*([\s\S]*?)<\/li>\s*<\/\1>$/i;
    const match = pattern.exec(html);
    if (!match) {
        return html;
    }

    const listTag = match[1].toLowerCase();
    const listAttrs = match[2] || '';
    const heading = match[4];
    const rest = String(match[5] || '').trim();
    if (rest === '') {
        return heading;
    }

    return `${heading}<${listTag}${listAttrs}><li>${rest}</li></${listTag}>`;
}

export default { liftHeadingsOutOfListItems };
