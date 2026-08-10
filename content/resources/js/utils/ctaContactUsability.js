/**
 * Frontend defensive filter for domain CTA / contact rows.
 * Backend DomainCtaEditorService is SoT; this catches stale payloads.
 */

const PLACEHOLDER_PATTERNS = [
    /^\[[^\]]+\]$/u,
    /^\{\{[^{}]+\}\}$/u,
    /^\{[^{}]+\}$/u,
];

/**
 * @param {unknown} value
 * @returns {boolean}
 */
export function isUnresolvedCtaPlaceholder(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return true;
    }

    return PLACEHOLDER_PATTERNS.some((pattern) => pattern.test(raw));
}

/**
 * @param {{ type?: string, value?: string, label?: string, is_blank?: boolean, usable?: boolean }|null|undefined} item
 * @returns {boolean}
 */
export function isUsableCtaContact(item) {
    if (!item || typeof item !== 'object') {
        return false;
    }

    if (item.usable === false || item.is_blank === true) {
        return false;
    }

    const type = String(item.type ?? '').trim();
    if (type === '') {
        return false;
    }

    const value = String(item.value ?? item.label ?? '').trim();
    if (value === '' || isUnresolvedCtaPlaceholder(value)) {
        return false;
    }

    if (isUnresolvedCtaPlaceholder(item.label) || isUnresolvedCtaPlaceholder(item.value)) {
        return false;
    }

    return true;
}

/**
 * @param {unknown[]} items
 * @returns {unknown[]}
 */
export function filterUsableCtaContacts(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    return items.filter((item) => isUsableCtaContact(item));
}
