/**
 * Custom SVG icon: paste markup and render via Image.
 */

const imageCache = new Map();

/**
 * @param {string} raw
 * @returns {string}
 */
export function sanitizeCustomSvg(raw) {
    let s = String(raw ?? '').trim();
    if (s === '') {
        return '';
    }

    s = s.replace(/<script[\s\S]*?<\/script>/gi, '');
    s = s.replace(/<foreignObject[\s\S]*?<\/foreignObject>/gi, '');
    s = s.replace(/\bon\w+\s*=\s*(['"])[\s\S]*?\1/gi, '');
    s = s.replace(/\bon\w+\s*=\s*[^\s>]+/gi, '');
    s = s.replace(/javascript:/gi, '');

    return s;
}

/**
 * @param {string} raw
 * @returns {string}
 */
export function normalizeCustomSvgMarkup(raw) {
    let s = sanitizeCustomSvg(raw);
    if (s === '') {
        return '';
    }

    if (!/<svg[\s>]/i.test(s)) {
        s = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${s}</svg>`;
    } else if (!/xmlns=/i.test(s)) {
        s = s.replace(/<svg/i, '<svg xmlns="http://www.w3.org/2000/svg"');
    }

    if (!/viewBox=/i.test(s)) {
        s = s.replace(/<svg/i, '<svg viewBox="0 0 24 24"');
    }

    return s;
}

/**
 * @param {string} svgMarkup
 * @param {string} color
 * @returns {string}
 */
export function tintCustomSvg(svgMarkup, color) {
    const c = String(color || '#ffffff');

    return svgMarkup
        .replace(/stroke="(?!none)([^"]*)"/gi, `stroke="${c}"`)
        .replace(/fill="(?!none)([^"]*)"/gi, `fill="${c}"`)
        .replace(/stroke:\s*(?!none)[^;}"']+/gi, `stroke:${c}`)
        .replace(/fill:\s*(?!none)[^;}"']+/gi, `fill:${c}`)
        .replace(/currentColor/gi, c);
}

/**
 * @param {string} rawSvg
 * @param {string} color
 * @returns {Promise<HTMLImageElement>}
 */
export function loadCustomIconImage(rawSvg, color) {
    const markup = normalizeCustomSvgMarkup(rawSvg);
    if (markup === '') {
        return Promise.reject(new Error('Empty SVG'));
    }

    const tinted = tintCustomSvg(markup, color);
    const cacheKey = `${tinted}`;
    const cached = imageCache.get(cacheKey);
    if (cached) {
        return cached;
    }

    const promise = new Promise((resolve, reject) => {
        const blob = new Blob([tinted], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const img = new Image();

        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Cannot read SVG'));
        };
        img.src = url;
    });

    imageCache.set(cacheKey, promise);
    return promise;
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} size
 * @param {HTMLImageElement} img
 */
export function drawCustomIconImage(ctx, x, y, size, img) {
    if (!img?.naturalWidth) {
        return;
    }

    const scale = size / Math.max(img.naturalWidth, img.naturalHeight);
    const w = img.naturalWidth * scale;
    const h = img.naturalHeight * scale;

    ctx.save();
    ctx.drawImage(img, x - w / 2, y - h / 2, w, h);
    ctx.restore();
}
