const locks = new Set();
let livewireCommitSequence = 0;
let livewireHookInstalled = false;

function notify() {
    window.dispatchEvent(
        new CustomEvent('seo-article-autosave-lock-changed', {
            detail: { locked: locks.size > 0, reasons: [...locks] },
        }),
    );
}

export function setArticleAutosaveLock(reason, locked) {
    const key = String(reason ?? '').trim();
    if (key === '') {
        return;
    }

    if (locked) {
        locks.add(key);
    } else {
        locks.delete(key);
    }

    notify();
}

export function isArticleAutosaveLocked() {
    return locks.size > 0;
}

export function hasOpenArticleEditorModal() {
    return Array.from(document.querySelectorAll('[role="dialog"], [aria-modal="true"]')).some((node) => {
        if (!(node instanceof HTMLElement)) {
            return false;
        }

        const style = window.getComputedStyle(node);

        return node.offsetParent !== null && style.display !== 'none' && style.visibility !== 'hidden';
    });
}

function installLivewireCommitLock() {
    if (livewireHookInstalled || typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
        return;
    }

    livewireHookInstalled = true;

    Livewire.hook('commit', ({ component, succeed, fail }) => {
        const editorPage = document.querySelector('.seo-article-edit-page');
        const pageRoot =
            editorPage?.closest('[wire\\:id]') ??
            editorPage?.querySelector('[wire\\:id]') ??
            null;
        const pageWireId = pageRoot?.getAttribute('wire:id') ?? '';
        if (pageWireId === '' || String(component?.id ?? '') !== pageWireId) {
            return;
        }

        const reason = `livewire-commit-${++livewireCommitSequence}`;
        setArticleAutosaveLock(reason, true);

        const release = () => setArticleAutosaveLock(reason, false);
        succeed(release);
        fail(release);
    });
}

export function installArticleAutosaveLock() {
    window.__seoArticleAutosaveLock = {
        set: setArticleAutosaveLock,
        isLocked: isArticleAutosaveLocked,
        reasons: () => [...locks],
    };

    window.addEventListener('article-autosave-lock', (event) => {
        const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
        setArticleAutosaveLock(detail.reason, Boolean(detail.locked));
    });

    document.addEventListener('livewire:init', installLivewireCommitLock);
    installLivewireCommitLock();
}
