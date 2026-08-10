/**
 * Deterministic quick CTA sentence templates (no AI).
 */

/** @type {Record<string, string[]>} */
export const DEFAULT_CTA_QUICK_TEMPLATES = {
    hotline: ['Gọi ngay: [phone]', 'Liên hệ ngay qua số [phone]', 'Cần tư vấn? Gọi [phone]'],
    phone: ['Gọi ngay: [phone]', 'Liên hệ ngay qua số [phone]'],
    zalo: ['Nhắn Zalo: [zalo]', 'Liên hệ Zalo ngay: [zalo]'],
    email: ['Liên hệ qua email: [email]', 'Gửi email đến [email]'],
    address: ['Ghé địa chỉ: [address]'],
    facebook: ['Xem thêm tại Facebook: [facebook]'],
    working_hours: ['Thời gian làm việc: [working_hours]'],
    website: ['Truy cập website: [website]'],
};

/** @type {Record<string, string[]>} */
const TYPE_PLACEHOLDERS = {
    hotline: ['phone', 'label'],
    phone: ['phone', 'label'],
    zalo: ['zalo', 'label'],
    email: ['email', 'label'],
    address: ['address', 'label'],
    facebook: ['facebook', 'label'],
    working_hours: ['working_hours', 'label'],
    website: ['website', 'label'],
};

/**
 * @param {string} type
 * @returns {string[]}
 */
export function allowedPlaceholdersForType(type) {
    const key = String(type ?? '').toLowerCase().trim();
    return TYPE_PLACEHOLDERS[key] ?? ['label', key].filter(Boolean);
}

/**
 * @param {string} type
 * @returns {string[]}
 */
export function defaultTemplatesForType(type) {
    const key = String(type ?? '').toLowerCase().trim();
    return [...(DEFAULT_CTA_QUICK_TEMPLATES[key] ?? [`Liên hệ: [${key || 'value'}]`])];
}

/**
 * @param {unknown} stored
 * @returns {Record<string, { defaultIndex: number, templates: string[] }>}
 */
export function normalizeCtaQuickTemplateSettings(stored) {
    const source = stored && typeof stored === 'object' ? stored : {};
    /** @type {Record<string, { defaultIndex: number, templates: string[] }>} */
    const next = {};

    for (const [type, defaults] of Object.entries(DEFAULT_CTA_QUICK_TEMPLATES)) {
        const row = source[type];
        const templates = Array.isArray(row?.templates)
            ? row.templates.map((item) => String(item ?? '').trim()).filter(Boolean)
            : defaults;
        const safeTemplates = templates.length > 0 ? templates : defaults;
        const defaultIndex = Math.max(
            0,
            Math.min(Number(row?.defaultIndex ?? row?.default_index ?? 0) || 0, safeTemplates.length - 1),
        );
        next[type] = { defaultIndex, templates: safeTemplates };
    }

    return next;
}

/**
 * @param {string} template
 * @param {string} type
 * @returns {{ ok: true }|{ ok: false, error: string }}
 */
export function validateCtaQuickTemplate(template, type) {
    const text = String(template ?? '').trim();
    if (text === '') {
        return { ok: false, error: 'Template trống.' };
    }

    const allowed = new Set(allowedPlaceholdersForType(type));
    const matches = text.match(/\[[^\]]+\]/gu) ?? [];
    for (const token of matches) {
        const name = token.slice(1, -1).trim().toLowerCase();
        if (!allowed.has(name)) {
            return { ok: false, error: `Placeholder không hợp lệ: ${token}` };
        }
    }

    return { ok: true };
}

/**
 * @param {string} template
 * @param {{ type?: string, value?: string, label?: string }} item
 * @returns {string}
 */
export function resolveCtaQuickTemplate(template, item) {
    const type = String(item?.type ?? '').toLowerCase().trim();
    const value = String(item?.value ?? item?.label ?? '').trim();
    const label = String(item?.label ?? item?.value ?? '').trim();

    const map = {
        phone: value,
        zalo: value,
        email: value,
        address: value,
        facebook: value,
        working_hours: value,
        website: value,
        label,
        [type]: value,
    };

    return String(template ?? '').replace(/\[([^\]]+)\]/gu, (full, name) => {
        const key = String(name ?? '').trim().toLowerCase();
        if (Object.prototype.hasOwnProperty.call(map, key) && String(map[key] ?? '').trim() !== '') {
            return String(map[key]);
        }

        return full;
    });
}

/**
 * @param {string} type
 * @param {Record<string, { defaultIndex: number, templates: string[] }>} settings
 * @returns {string}
 */
export function getDefaultCtaQuickTemplate(type, settings) {
    const normalized = normalizeCtaQuickTemplateSettings(settings);
    const key = String(type ?? '').toLowerCase().trim();
    const row = normalized[key] ?? {
        defaultIndex: 0,
        templates: defaultTemplatesForType(key),
    };
    return row.templates[row.defaultIndex] ?? row.templates[0] ?? '';
}

const STORAGE_PREFIX = 'seo-cta-quick-templates:v1:';

/**
 * @param {number|string} siteId
 * @returns {Record<string, { defaultIndex: number, templates: string[] }>}
 */
export function loadCtaQuickTemplatesFromStorage(siteId) {
    const id = Number(siteId) || 0;
    try {
        const raw = window.localStorage?.getItem(`${STORAGE_PREFIX}${id}`);
        if (!raw) {
            return normalizeCtaQuickTemplateSettings(null);
        }
        return normalizeCtaQuickTemplateSettings(JSON.parse(raw));
    } catch {
        return normalizeCtaQuickTemplateSettings(null);
    }
}

/**
 * @param {number|string} siteId
 * @param {Record<string, { defaultIndex: number, templates: string[] }>} settings
 */
export function saveCtaQuickTemplatesToStorage(siteId, settings) {
    const id = Number(siteId) || 0;
    const normalized = normalizeCtaQuickTemplateSettings(settings);
    try {
        window.localStorage?.setItem(`${STORAGE_PREFIX}${id}`, JSON.stringify(normalized));
    } catch {
        // ignore quota / private mode
    }
    return normalized;
}
