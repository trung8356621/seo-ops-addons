import { mountArticleSeoPreview, unmountArticleSeoPreview } from './articleSeoPreviewMount';

/** @type {AbortController|null} */
let fetchController = null;

function readConfig() {
    const el = document.getElementById('article-seo-list-config');
    if (!el?.textContent?.trim()) {
        return null;
    }

    try {
        return JSON.parse(el.textContent);
    } catch {
        return null;
    }
}

function getModalElements() {
    return {
        modal: document.getElementById('article-seo-modal'),
        backdrop: document.querySelector('[data-article-seo-modal-close]'),
        closeButtons: document.querySelectorAll('[data-article-seo-modal-close]'),
        title: document.getElementById('article-seo-modal-title'),
        subtitle: document.getElementById('article-seo-modal-subtitle'),
        root: document.getElementById('seo-article-preview-modal-root'),
        loading: document.getElementById('article-seo-modal-loading'),
        error: document.getElementById('article-seo-modal-error'),
        editLink: document.getElementById('article-seo-modal-edit'),
    };
}

function setModalOpen(open) {
    const { modal } = getModalElements();
    if (!modal) {
        return;
    }

    modal.classList.toggle('is-open', open);
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('article-seo-modal-open', open);

    if (!open) {
        fetchController?.abort();
        fetchController = null;
        const { root } = getModalElements();
        if (root) {
            unmountArticleSeoPreview(root);
        }
    }
}

function setLoading(loading) {
    const { loading: loadingEl, error, root } = getModalElements();
    if (loadingEl) {
        loadingEl.classList.toggle('article-seo-modal__loading--hidden', !loading);
    }
    if (error) {
        error.classList.add('article-seo-modal__error--hidden');
    }
    if (loading && root) {
        unmountArticleSeoPreview(root);
    }
}

function showError(message) {
    const { loading: loadingEl, error, root } = getModalElements();
    if (loadingEl) {
        loadingEl.classList.add('article-seo-modal__loading--hidden');
    }
    if (error) {
        error.textContent = message;
        error.classList.remove('article-seo-modal__error--hidden');
    }
    if (root) {
        unmountArticleSeoPreview(root);
    }
}

function buildPreviewUrl(config, articleId) {
    const template = config.previewUrlTemplate ?? '';
    return template.replace('__ID__', String(articleId));
}

/**
 * @param {number} articleId
 */
export async function openArticleSeoModal(articleId) {
    const config = readConfig();
    const { modal, title, subtitle, root, editLink } = getModalElements();

    if (!config || !modal || !root) {
        return;
    }

    setModalOpen(true);
    setLoading(true);

    if (title) {
        title.textContent = 'SEO point';
    }
    if (subtitle) {
        subtitle.textContent = 'Đang tải…';
    }
    if (editLink) {
        editLink.classList.add('article-seo-modal__edit--hidden');
        editLink.setAttribute('href', '#');
    }

    fetchController?.abort();
    fetchController = new AbortController();

    try {
        const response = await fetch(buildPreviewUrl(config, articleId), {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: fetchController.signal,
        });

        if (!response.ok) {
            let message = `HTTP ${response.status}`;
            try {
                const err = await response.json();
                message = err.message ?? message;
            } catch {
                // ignore
            }
            showError(message);

            return;
        }

        const data = await response.json();
        const article = data.article ?? {};
        const seo = data.seo ?? {};

        const score = article.score;
        if (title) {
            title.textContent = score != null ? `SEO point (${score})` : 'SEO point';
        }
        if (subtitle) {
            subtitle.textContent = article.title ?? '';
        }
        if (editLink && article.edit_url) {
            editLink.setAttribute('href', article.edit_url);
            editLink.classList.remove('article-seo-modal__edit--hidden');
        }

        setLoading(false);
        mountArticleSeoPreview(root, seo);
    } catch (e) {
        if (e?.name === 'AbortError') {
            return;
        }
        showError(e?.message ?? 'Không tải được dữ liệu SEO.');
    }
}

function handleDocumentClick(event) {
    const trigger = event.target.closest('.js-article-seo-open');
    if (trigger) {
        event.preventDefault();
        event.stopPropagation();

        const articleId = parseInt(trigger.getAttribute('data-article-id') ?? '', 10);
        if (articleId > 0) {
            openArticleSeoModal(articleId);
        }

        return;
    }

    if (event.target.closest('[data-article-seo-modal-close]')) {
        event.preventDefault();
        setModalOpen(false);
    }
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        const { modal } = getModalElements();
        if (modal?.classList.contains('is-open')) {
            setModalOpen(false);
        }
    }
}

let modalListenersBound = false;

function bindModalListeners() {
    if (modalListenersBound) {
        return;
    }

    modalListenersBound = true;
    document.addEventListener('click', handleDocumentClick, true);
    document.addEventListener('keydown', handleKeydown);
}

export function initArticleSeoListModal() {
    if (!readConfig()) {
        return;
    }

    bindModalListeners();
}
