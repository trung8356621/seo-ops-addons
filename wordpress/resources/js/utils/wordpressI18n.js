import { getSeoLocale, t as baseT } from '@content-addon/utils/i18n.js';

const dictionary = {
    en: {
        wp_media_rename_preview_failed_scan: 'Unable to scan usage.',
        wp_media_rename_loading_usage_scan: 'Scanning usage...',
        wp_media_rename_submitting_rename: 'Renaming...',
        wp_media_rename_no_usage_scan_result: 'No usage scan result yet.',
        wp_media_rename_scan_incomplete_block: 'WordPress usage scan is not finished - rename is blocked.',
        wp_media_rename_new_slug_required: 'Enter new filename/slug.',
        wp_media_rename_ack_required: 'You must confirm URL change.',
        wp_media_rename_phrase_required: 'Type RENAME exactly.',
        wp_media_rename_scanning_usage: 'Scanning usage...',
    },
    vi: {
        wp_media_rename_preview_failed_scan: 'Khong quet duoc usage.',
        wp_media_rename_loading_usage_scan: 'Dang quet usage...',
        wp_media_rename_submitting_rename: 'Dang doi ten...',
        wp_media_rename_no_usage_scan_result: 'Chua co ket qua usage scan.',
        wp_media_rename_scan_incomplete_block: 'Usage scan WordPress chua hoan thanh - khong doi ten duoc.',
        wp_media_rename_new_slug_required: 'Nhap filename/slug moi.',
        wp_media_rename_ack_required: 'Can tick xac nhan URL se doi.',
        wp_media_rename_phrase_required: 'Nhap chinh xac RENAME.',
        wp_media_rename_scanning_usage: 'Dang quet usage...',
    },
};

function format(template, params) {
    return String(template).replace(/\{(\w+)\}/g, (_, token) => String(params[token] ?? ''));
}

export function wpT(key, params = {}) {
    const translated = baseT(key, params);
    if (translated !== key) {
        return translated;
    }

    const locale = getSeoLocale();
    const table = dictionary[locale] || dictionary.en;
    const raw = table[key] || dictionary.en[key] || key;

    return format(raw, params);
}
