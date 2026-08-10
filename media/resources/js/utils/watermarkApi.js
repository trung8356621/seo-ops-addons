const BASE = '/api/seo/watermark';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function parseJson(response) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        throw new Error(data.message ?? 'Yêu cầu thất bại.');
    }

    return data;
}

export const WATERMARK_POSITIONS = [
    { value: 'top-left', label: 'Góc trên — Trái' },
    { value: 'top-center', label: 'Góc trên — Giữa' },
    { value: 'top-right', label: 'Góc trên — Phải' },
    { value: 'center-left', label: 'Giữa — Trái' },
    { value: 'center', label: 'Chính giữa' },
    { value: 'center-right', label: 'Giữa — Phải' },
    { value: 'bottom-left', label: 'Góc dưới — Trái' },
    { value: 'bottom-center', label: 'Góc dưới — Giữa' },
    { value: 'bottom-right', label: 'Góc dưới — Phải' },
];

export async function fetchWatermarkSettings(siteId) {
    const url = `${BASE}/settings?site_id=${encodeURIComponent(String(siteId))}`;
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    const data = await parseJson(response);

    return data.settings ?? {};
}

/**
 * @param {number} siteId
 * @param {Record<string, unknown>} payload
 * @param {File|null} logoFile
 */
/**
 * @param {Array<{ key: string, blob: Blob }>|null} overlayVariants
 */
export async function saveWatermarkSettings(siteId, payload, logoFile = null, overlayVariants = null) {
    const formData = new FormData();
    formData.append('site_id', String(siteId));

    if (payload.design_config) {
        formData.append(
            'design_config',
            typeof payload.design_config === 'string'
                ? payload.design_config
                : JSON.stringify(payload.design_config),
        );
    }

    const scalarKeys = [
        'type',
        'auto_watermark',
        'text_content',
        'text_color',
        'text_size',
        'logo_width_pct',
        'position',
        'opacity',
    ];

    scalarKeys.forEach((key) => {
        if (payload[key] !== undefined && payload[key] !== null) {
            formData.append(key, String(payload[key]));
        }
    });

    if (logoFile) {
        formData.append('logo', logoFile);
    }

    if (Array.isArray(overlayVariants) && overlayVariants.length > 0) {
        overlayVariants.forEach(({ key, blob }) => {
            formData.append(`overlay_${key}`, blob, `watermark-overlay-${key}.png`);
        });
    }

    const response = await fetch(`${BASE}/settings`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    return parseJson(response);
}

export async function applyWatermarkBatch(siteIds) {
    const response = await fetch(`${BASE}/batch`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ site_ids: siteIds }),
    });

    return parseJson(response);
}

export async function saveWatermarkedMedia(mediaId, blob, mode = 'overwrite') {
    const formData = new FormData();
    formData.append('image', blob, 'watermarked.png');
    formData.append('mode', mode);

    const response = await fetch(`${BASE}/media/${mediaId}/save`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    return parseJson(response);
}

export async function saveNewWatermarkedImage(siteId, blob) {
    const formData = new FormData();
    formData.append('image', blob, 'watermarked.png');
    formData.append('site_id', String(siteId));

    const response = await fetch(`${BASE}/save-new`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    return parseJson(response);
}
