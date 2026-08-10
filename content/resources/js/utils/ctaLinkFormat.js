/**
 * @param {string} type
 * @returns {boolean}
 */
export function isCtaPlainTextType(type) {
    return ['address', 'working_hours'].includes(String(type ?? '').toLowerCase());
}

/**
 * @param {string} type
 * @param {string} value
 * @returns {string}
 */
export function formatCtaHref(type, value) {
    const raw = String(value ?? '').trim();
    if (!raw) {
        return '';
    }

    if (isCtaPlainTextType(type)) {
        return '';
    }

    switch (String(type ?? '').toLowerCase()) {
        case 'phone':
        case 'hotline': {
            const digits = raw.replace(/[^\d+]/g, '');
            return digits ? `tel:${digits}` : '';
        }
        case 'email':
            return /^mailto:/i.test(raw) ? raw : `mailto:${raw}`;
        case 'zalo':
            if (/^https?:\/\//i.test(raw)) {
                return raw;
            }
            {
                const digits = raw.replace(/\D/g, '');
                return digits ? `https://zalo.me/${digits}` : raw;
            }
        case 'website':
            if (/^https?:\/\//i.test(raw)) {
                return raw;
            }
            if (raw.startsWith('//')) {
                return `https:${raw}`;
            }
            return `https://${raw.replace(/^\/+/, '')}`;
        default:
            return raw;
    }
}

/**
 * @param {{ type?: string, value?: string, label?: string }} item
 * @returns {string}
 */
export function ctaDisplayLabel(item) {
    return String(item?.label ?? item?.value ?? '').trim();
}

/**
 * CTA luôn cho phép chèn lại — không khóa khi đã có trong bài hoặc khi đã chọn dòng.
 *
 * @param {{ type?: string, value?: string, label?: string, href?: string, plain_text?: boolean }} item
 * @returns {boolean}
 */
export function isCtaItemInsertable(item) {
    if (item?.is_blank === true && String(item?.type ?? '').trim() !== '') {
        return true;
    }

    const label = ctaDisplayLabel(item);
    if (!label) {
        return false;
    }

    const type = String(item?.type ?? '').toLowerCase();
    if (isCtaPlainTextType(type) || item?.plain_text === true) {
        return true;
    }

    const href = String(item?.href ?? formatCtaHref(type, item?.value)).trim();
    return href !== '';
}
