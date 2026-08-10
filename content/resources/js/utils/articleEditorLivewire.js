function resolveEditArticleWireId() {
    const preset = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim();
    if (preset !== '') {
        return preset;
    }

    const pageRoot = document.querySelector('.seo-article-edit-page[wire\\:id]');
    if (pageRoot) {
        return pageRoot.getAttribute('wire:id');
    }

    const nested = document.querySelector('.seo-article-edit-page [wire\\:id]');
    return nested?.getAttribute('wire:id') ?? null;
}

export { saveArticleViaApi, syncArticleToWordPressViaApi } from './articleEditorApi.js';

export function callEditArticleLivewire(method, ...args) {
    if (typeof Livewire === 'undefined') {
        return Promise.reject(new Error('Livewire is not available'));
    }

    const wireId = resolveEditArticleWireId();
    if (!wireId) {
        return Promise.reject(new Error('Edit article Livewire component not found'));
    }

    const component = Livewire.find(wireId);
    if (!component?.call) {
        return Promise.reject(new Error('Edit article Livewire component not callable'));
    }

    return component.call(method, ...args);
}

export function mountEditArticleAction(actionName, argumentsPayload = {}) {
    if (typeof Livewire === 'undefined') {
        return Promise.reject(new Error('Livewire is not available'));
    }

    const wireId = resolveEditArticleWireId();
    if (!wireId) {
        return Promise.reject(new Error('Edit article Livewire component not found'));
    }

    const component = Livewire.find(wireId);
    if (!component?.call) {
        return Promise.reject(new Error('Edit article Livewire component not callable'));
    }

    return component.call('mountAction', actionName, argumentsPayload);
}
