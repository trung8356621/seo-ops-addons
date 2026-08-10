import { uploadSeoMediaFromFile } from './seoMediaApi';

export const LOCAL_MEDIA_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
export const LOCAL_MEDIA_FILE_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';

/**
 * @param {FileList|File[]} fileList
 * @param {{ articleId?: number|null, siteId?: number|null, source?: string }} options
 * @returns {Promise<Array<Record<string, unknown>>>}
 */
export async function uploadLocalMediaFiles(fileList, { articleId = null, siteId = null, source = 'library' } = {}) {
    const files = Array.from(fileList ?? []).filter((file) => file && String(file.type || '').startsWith('image/'));

    if (files.length === 0) {
        throw new Error('Không có file ảnh hợp lệ (JPEG, PNG, GIF, WebP).');
    }

    const oversized = files.find((file) => file.size > LOCAL_MEDIA_MAX_UPLOAD_BYTES);
    if (oversized) {
        throw new Error('Ảnh vượt quá 10MB. Hãy nén hoặc chọn file nhỏ hơn.');
    }

    const results = [];

    for (const file of files) {
        const data = await uploadSeoMediaFromFile(file, { articleId, siteId, source });
        results.push(data);
    }

    return results;
}

export function registerSeoLocalMediaUploadGlobals() {
    if (window.__seoLocalMediaUploadRegistered) {
        return;
    }

    window.__seoLocalMediaUploadRegistered = true;
    window.seoUploadLocalMediaFiles = uploadLocalMediaFiles;
}

registerSeoLocalMediaUploadGlobals();
