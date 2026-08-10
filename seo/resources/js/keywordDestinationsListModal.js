function readConfig() {
    const el = document.getElementById('keyword-destinations-modal-config');
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
        modal: document.getElementById('keyword-destinations-modal'),
        title: document.getElementById('keyword-destinations-modal-title'),
        content: document.getElementById('keyword-destinations-modal-content'),
        loading: document.getElementById('keyword-destinations-modal-loading'),
        error: document.getElementById('keyword-destinations-modal-error'),
    };
}

/** @type {{ open: boolean, title: string, html: string|null, loading: boolean, error: string|null }|null} */
let modalSession = null;

/** @type {AbortController|null} */
let loadController = null;

function applyModalSession() {
    if (!modalSession?.open) {
        return;
    }

    const { modal, title, content, loading, error } = getModalElements();
    if (!modal) {
        return;
    }

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('keyword-destinations-modal-open');

    if (title && modalSession.title) {
        title.textContent = modalSession.title;
    }

    if (loading) {
        loading.classList.toggle('keyword-destinations-modal__loading--hidden', !modalSession.loading);
    }

    if (error) {
        if (modalSession.error) {
            error.textContent = modalSession.error;
            error.classList.remove('keyword-destinations-modal__error--hidden');
        } else {
            error.textContent = '';
            error.classList.add('keyword-destinations-modal__error--hidden');
        }
    }

    if (content && modalSession.html !== null && !modalSession.loading && !modalSession.error) {
        content.innerHTML = modalSession.html;
    }
}

function setModalOpen(open) {
    const { modal } = getModalElements();
    if (!modal) {
        return;
    }

    if (!open) {
        modalSession = null;
        loadController?.abort();
        loadController = null;
    }

    modal.classList.toggle('is-open', open);
    modal.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('keyword-destinations-modal-open', open);

    if (!open) {
        const { content, error, loading } = getModalElements();
        if (content) {
            content.innerHTML = '';
        }
        if (error) {
            error.textContent = '';
            error.classList.add('keyword-destinations-modal__error--hidden');
        }
        if (loading) {
            loading.classList.add('keyword-destinations-modal__loading--hidden');
        }
    }
}

function setLoading(loading) {
    const { loading: loadingEl, error, content } = getModalElements();
    if (modalSession) {
        modalSession.loading = loading;
        if (loading) {
            modalSession.html = null;
            modalSession.error = null;
        }
    }

    if (loadingEl) {
        loadingEl.classList.toggle('keyword-destinations-modal__loading--hidden', !loading);
    }
    if (error) {
        error.classList.add('keyword-destinations-modal__error--hidden');
    }
    if (loading && content) {
        content.innerHTML = '';
    }
}

function showError(message) {
    const { loading: loadingEl, error, content } = getModalElements();
    if (modalSession) {
        modalSession.loading = false;
        modalSession.error = message;
        modalSession.html = null;
    }

    if (loadingEl) {
        loadingEl.classList.add('keyword-destinations-modal__loading--hidden');
    }
    if (error) {
        error.textContent = message;
        error.classList.remove('keyword-destinations-modal__error--hidden');
    }
    if (content) {
        content.innerHTML = '';
    }
}

function resolveLivewireComponent(config) {
    const livewireId = config?.livewireId ?? '';
    if (livewireId === '' || typeof window.Livewire?.find !== 'function') {
        return null;
    }

    return window.Livewire.find(livewireId);
}

/**
 * @param {number} keywordId
 * @param {string} phrase
 */
export async function openKeywordDestinationsModal(keywordId, phrase = '') {
    const config = readConfig();
    const { modal } = getModalElements();

    if (!config || !modal) {
        return;
    }

    const prefix = String(config.headingPrefix ?? 'Target destinations');
    const titleText = phrase !== '' ? `${prefix} · ${phrase}` : prefix;

    modalSession = {
        open: true,
        title: titleText,
        html: null,
        loading: true,
        error: null,
    };

    setModalOpen(true);
    setLoading(true);

    const { title } = getModalElements();
    if (title) {
        title.textContent = titleText;
    }

    loadController?.abort();
    loadController = new AbortController();
    const { signal } = loadController;

    const component = resolveLivewireComponent(config);
    if (!component) {
        showError(String(config.errorLabel ?? 'Livewire unavailable.'));

        return;
    }

    try {
        const result = await component.call('loadKeywordDestinationsModal', keywordId);

        if (signal.aborted) {
            return;
        }

        if (result?.error) {
            showError(String(result.error));

            return;
        }

        const resolvedTitle = result?.phrase
            ? `${prefix} · ${result.phrase}`
            : titleText;
        const html = String(result?.html ?? '');

        modalSession = {
            open: true,
            title: resolvedTitle,
            html,
            loading: false,
            error: null,
        };

        applyModalSession();
    } catch (e) {
        if (signal.aborted || e?.name === 'AbortError') {
            return;
        }

        showError(e?.message ?? String(config.errorLabel ?? 'Could not load destinations.'));
    }
}

function handleDocumentClick(event) {
    const trigger = event.target.closest('.js-keyword-destinations-open');
    if (trigger) {
        event.preventDefault();
        event.stopPropagation();

        const keywordId = parseInt(trigger.getAttribute('data-keyword-id') ?? '', 10);
        const phrase = String(trigger.getAttribute('data-keyword-phrase') ?? '').trim();
        if (keywordId > 0) {
            openKeywordDestinationsModal(keywordId, phrase);
        }

        return;
    }

    if (event.target.closest('[data-keyword-destinations-modal-close]')) {
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
    document.addEventListener('livewire:morph', applyModalSession);
    document.addEventListener('livewire:navigated', applyModalSession);
}

export function initKeywordDestinationsListModal() {
    if (!readConfig()) {
        return;
    }

    bindModalListeners();
    applyModalSession();
}
