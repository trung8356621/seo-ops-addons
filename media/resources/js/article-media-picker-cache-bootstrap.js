import '../css/seo-article-media-modal.css';
import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from './utils/articleMediaPickerCache';
import {
    isCustomPickerTab,
    customTabIdFromPickerTab,
    pickerTabFromCustomId,
    normalizeArticleDomain,
    loadCustomPickerTabs,
    addCustomPickerTab,
    removeCustomPickerTab,
    loadStagedPickerImages,
    stagePickerImageToTab,
    unstagePickerImageFromTab,
    countStagedPickerImages,
    renameCustomPickerTab,
    readCustomTabFetchCache,
    writeCustomTabFetchCache,
    CUSTOM_WP_TABS_COOKIE_NAME,
} from './utils/articleMediaPickerCustomTabs';
import { createSeoWorkspaceMediaPicker } from './utils/seoWorkspaceMediaPicker';

window.__seoArticleMediaPickerCache = {
    read: readArticleMediaPickerCache,
    write: writeArticleMediaPickerCache,
    isCacheableTab: isArticleMediaPickerCacheableTab,
};

window.__seoArticleMediaPickerCustomTabs = {
    isCustomTab: isCustomPickerTab,
    customTabIdFromPickerTab,
    pickerTabFromCustomId,
    normalizeDomain: normalizeArticleDomain,
    cookieName: CUSTOM_WP_TABS_COOKIE_NAME,
    loadTabs: loadCustomPickerTabs,
    addTab: addCustomPickerTab,
    removeTab: removeCustomPickerTab,
    loadStagedImages: loadStagedPickerImages,
    stageImage: stagePickerImageToTab,
    unstageImage: unstagePickerImageFromTab,
    renameTab: renameCustomPickerTab,
    countStagedImages: countStagedPickerImages,
    readFetchCache: readCustomTabFetchCache,
    writeFetchCache: writeCustomTabFetchCache,
};

function registerSeoWorkspaceMediaPicker() {
    if (!window.Alpine?.data) {
        return;
    }

    window.Alpine.data('seoWorkspaceMediaPicker', (config = {}) => createSeoWorkspaceMediaPicker(config));
}

document.addEventListener('alpine:init', registerSeoWorkspaceMediaPicker);
registerSeoWorkspaceMediaPicker();
