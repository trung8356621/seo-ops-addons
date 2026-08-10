import { clearArticleEditorStorage } from './articleEditorStorage';
import { clearFeaturedImageStorage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import { clearArticleMediaPickerCache } from '@media-addon/utils/articleMediaPickerCache.js';
import { clearProductAlbumStorage } from '@media-addon/utils/articleProductAlbumStorage.js';

const BLOCK_HEIGHT_PREFIX = 'seo-block-editor-h:';

export function clearArticleLocalState(articleId, siteId) {
    clearArticleEditorStorage(articleId);
    clearFeaturedImageStorage(articleId);
    clearProductAlbumStorage(articleId);
    clearArticleMediaPickerCache(siteId);

    const sessionKeys = [];
    for (let index = 0; index < sessionStorage.length; index += 1) {
        const key = sessionStorage.key(index);
        if (key?.startsWith(BLOCK_HEIGHT_PREFIX)) {
            sessionKeys.push(key);
        }
    }
    sessionKeys.forEach((key) => sessionStorage.removeItem(key));
}
