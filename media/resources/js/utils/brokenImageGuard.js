/** Static placeholder — không animate (tránh “load hoài” như placeholder-loading.svg). */
export const BROKEN_IMAGE_PLACEHOLDER =
    'data:image/svg+xml,' +
    encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" viewBox="0 0 320 200">' +
            '<rect width="320" height="200" fill="#e5e7eb"/>' +
            '<text x="160" y="105" text-anchor="middle" fill="#6b7280" font-size="14" font-family="Arial,sans-serif">Image unavailable</text>' +
            '</svg>',
    );

/**
 * Chuẩn hóa src để so khớp broken set (bỏ cache-bust query).
 */
export function normalizeBrokenImageSrcKey(src) {
    const raw = String(src ?? '').trim();
    if (!raw) {
        return '';
    }

    try {
        const url = new URL(raw, window.location.origin);
        url.searchParams.delete('seo_reload');
        url.hash = '';

        return url.href.toLowerCase();
    } catch {
        return raw.replace(/([?&])seo_reload=\d+/g, '').toLowerCase();
    }
}

/**
 * Gắn error listener trên mọi <img> trong root.
 * Lần lỗi đầu: đánh dấu broken + đổi sang placeholder tĩnh — không retry khi React re-inject HTML.
 *
 * @param {ParentNode|null} root
 * @param {Set<string>} brokenSrcKeys
 * @returns {() => void} cleanup
 */
export function installBrokenImageGuard(root, brokenSrcKeys) {
    if (!root || !brokenSrcKeys) {
        return () => {};
    }

    const freezeImg = (img) => {
        if (!(img instanceof HTMLImageElement)) {
            return;
        }

        const rawSrc = String(img.getAttribute('src') || img.currentSrc || '').trim();
        const key = normalizeBrokenImageSrcKey(rawSrc);
        if (key) {
            brokenSrcKeys.add(key);
        }

        if (img.dataset.seoBroken === '1') {
            return;
        }

        img.dataset.seoBroken = '1';
        img.classList.add('seo-img-broken');
        img.removeAttribute('srcset');
        img.src = BROKEN_IMAGE_PLACEHOLDER;
    };

    const applyKnownBroken = () => {
        root.querySelectorAll('img').forEach((img) => {
            const rawSrc = String(img.getAttribute('src') || '').trim();
            const key = normalizeBrokenImageSrcKey(rawSrc);
            if (key && brokenSrcKeys.has(key)) {
                freezeImg(img);
            }
        });
    };

    applyKnownBroken();

    const onError = (event) => {
        const target = event?.target;
        if (!(target instanceof HTMLImageElement)) {
            return;
        }
        if (!root.contains(target)) {
            return;
        }
        freezeImg(target);
    };

    root.addEventListener('error', onError, true);

    return () => {
        root.removeEventListener('error', onError, true);
    };
}
