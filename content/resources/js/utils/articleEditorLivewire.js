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

/**
 * Copy session tokens into the Livewire JS snapshot without a network round-trip.
 * Livewire.set(prop, value) (live=true) hydrates the full EditArticle component.
 */
export function applyEditorSessionTokensLocally(component) {
    if (!component) {
        return;
    }

    const client = typeof window !== 'undefined' ? window.__seoEditorSessionClient : null;
    const sessionId = client?.sessionId
        || (typeof window !== 'undefined' ? window.__SEO_EDITOR_SESSION_ID__ : null)
        || null;
    const version = Number(
        client?.documentVersion
        ?? (typeof window !== 'undefined' ? window.__SEO_EDITOR_DOCUMENT_VERSION__ : 0)
        ?? 0,
    ) || null;

    const setter = typeof component.set === 'function'
        ? component.set.bind(component)
        : (typeof component.$set === 'function' ? component.$set.bind(component) : null);
    if (!setter) {
        return;
    }

    const currentId = component.get?.('editorSessionId') ?? component.editorSessionId ?? null;
    const currentVer = component.get?.('expectedDocumentVersion') ?? component.expectedDocumentVersion ?? null;

    if (String(currentId || '') !== String(sessionId || '')) {
        setter('editorSessionId', sessionId, false);
    }
    if (Number(currentVer || 0) !== Number(version || 0)) {
        setter('expectedDocumentVersion', version, false);
    }
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

    applyEditorSessionTokensLocally(component);

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

    applyEditorSessionTokensLocally(component);

    return component.call('mountAction', actionName, argumentsPayload);
}
