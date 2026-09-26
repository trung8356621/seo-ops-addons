import { getSeoLocale, t as baseT } from '@content-addon/utils/i18n.js';

const dictionary = {
    en: {
        open_in_images_tab: 'Open in Images tab',
        wm_overlay_empty: 'No saved overlay yet.',
        wm_overlay_save_hint: 'Click Save config to export 8 PNG files (~{max}px long edge) by ratio, then preview them here.',
        wm_overlay_view: 'View',
        wm_overlay_zoom: 'Zoom',
        wm_overlay_mode_composite: 'Composite over sample image',
        wm_overlay_mode_overlay_only: 'Overlay only (checkerboard)',
        wm_overlay_zoom_fit: 'Fit',
        wm_overlay_zoom_half: '50% actual size',
        wm_overlay_zoom_full: '100% (1:1 pixel)',
        wm_overlay_sample_image: 'sample image',
        wm_overlay_composite_using: 'Composite uses',
        wm_overlay_ratio_mismatch: 'Tab {activeKey} does not match sample ratio - composite mode auto-uses {bestKey} (same as real watermarking). Pick a matching tab or use overlay-only for {activeKey}.',
        wm_overlay_footer_hint: 'When watermarking, the system picks nearest-ratio overlay then scales to full frame. Composite preview follows the same behavior to avoid distortion.',
    },
    vi: {
        open_in_images_tab: 'Mo trong tab Hinh anh',
        wm_overlay_empty: 'Chua co overlay da luu.',
        wm_overlay_save_hint: 'Bam Luu cau hinh de xuat 8 file PNG (~{max}px canh dai) theo ti le, roi xem lai tai day.',
        wm_overlay_view: 'Xem',
        wm_overlay_zoom: 'Thu phong',
        wm_overlay_mode_composite: 'Ghep len anh mau',
        wm_overlay_mode_overlay_only: 'Chi overlay (nen caro)',
        wm_overlay_zoom_fit: 'Vua khung',
        wm_overlay_zoom_half: '50% kich thuoc that',
        wm_overlay_zoom_full: '100% (1:1 pixel)',
        wm_overlay_sample_image: 'anh mau',
        wm_overlay_composite_using: 'Ghep dung',
        wm_overlay_ratio_mismatch: 'Tab {activeKey} khong khop ti le anh mau - che do ghep tu dung {bestKey} (giong khi dong dau anh that). Chon tab khop hoac xem chi overlay cho {activeKey}.',
        wm_overlay_footer_hint: 'Khi dong dau, he thong chon overlay co ti le gan anh nhat roi scale full khung - preview ghep lam tuong tu de khong bi meo watermark.',
    },
};

function format(template, params) {
    return String(template).replace(/\{(\w+)\}/g, (_, token) => String(params[token] ?? ''));
}

export function mediaT(key, params = {}) {
    const translated = baseT(key, params);
    if (translated !== key) {
        return translated;
    }

    const locale = getSeoLocale();
    const table = dictionary[locale] || dictionary.en;
    const raw = table[key] || dictionary.en[key] || key;

    return format(raw, params);
}
