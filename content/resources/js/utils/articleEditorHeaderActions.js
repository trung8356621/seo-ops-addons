/**
 * Di chuyển Filament header actions vào More menu (secondary group).
 * Thứ tự More: History / View WP (Blade) | Rerun → Restore → Prompts/Assign | Delete
 */

function actionFingerprint(element) {
    if (!(element instanceof HTMLElement)) {
        return '';
    }

    if (element.hasAttribute('data-seo-pipeline-rerun')) {
        return 'pipeline-rerun';
    }

    if (element.hasAttribute('data-seo-shortcuts-wrap') || element.closest?.('[data-seo-shortcuts-below]')) {
        return 'shortcuts';
    }

    if (element.hasAttribute('data-seo-delete-action-wrap') || element.classList.contains('seo-editor-delete-action')) {
        return 'delete-article';
    }

    if (element.hasAttribute('data-seo-restore-action-wrap') || element.classList.contains('seo-editor-restore-action')) {
        return 'restore-wp';
    }

    if (element.closest?.('[data-seo-page-actions-primary]')) {
        return `primary|${element.getAttribute('data-seo-page-action') ?? ''}`;
    }

    const wireClick = element.getAttribute('wire:click') ?? '';
    const alpineClick = element.getAttribute('x-on:click') ?? element.getAttribute('@click') ?? '';
    const href = element.getAttribute('href') ?? '';
    const label = normalizeActionLabel(
        element.querySelector('.fi-btn-label')?.textContent
        ?? element.getAttribute('title')
        ?? element.getAttribute('aria-label')
        ?? '',
    );

    if (
        alpineClick.includes('assign-content-project:open')
        || alpineClick.includes('assign-to-content-project')
        || label.includes('assign to content project')
        || label.includes('phân vào content project')
        || label.includes('phân vào dự án nội dung')
    ) {
        return 'assign-content-project';
    }
    if (label === 'prompts' || (href.includes('/prompts') && href.includes('/articles/'))) {
        return 'prompts';
    }
    if (label.includes('open content project') || label.includes('mở content project')) {
        return 'open-content-project';
    }

    return `${wireClick}|${alpineClick}|${href}|${label}`;
}

function normalizeActionLabel(text) {
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

function getPageActionsSlot() {
    return document.querySelector('[data-seo-page-actions-slot]');
}

function getSecondaryHost(slot = getPageActionsSlot()) {
    return slot?.querySelector('[data-seo-page-actions-secondary]') ?? slot;
}

function getDangerHost(slot = getPageActionsSlot()) {
    return slot?.querySelector('[data-seo-page-actions-danger]') ?? slot;
}

function isPersistentToolbarChild(child) {
    if (!(child instanceof HTMLElement)) {
        return false;
    }

    return child.hasAttribute('data-seo-page-actions-primary')
        || child.hasAttribute('data-seo-page-actions-secondary')
        || child.hasAttribute('data-seo-page-actions-danger')
        || child.hasAttribute('data-seo-page-actions-more')
        || child.classList.contains('seo-editor-page-actions__divider')
        || child.hasAttribute('data-seo-shortcuts-wrap')
        || child.hasAttribute('data-seo-pipeline-rerun')
        || child.hasAttribute('data-seo-delete-action-wrap')
        || child.hasAttribute('data-seo-restore-action-wrap')
        || child.classList.contains('seo-editor-delete-action')
        || child.classList.contains('seo-editor-restore-action')
        || child.classList.contains('seo-editor-menu-item')
        || child.classList.contains('seo-editor-menu-divider')
        || child.classList.contains('seo-editor-preview-split');
}

function findDeleteAction(host) {
    const wrap = host?.querySelector?.('[data-seo-delete-action-wrap], .seo-editor-delete-action');
    if (wrap instanceof HTMLElement) {
        return wrap;
    }

    const customBtn = host?.querySelector?.('[data-seo-delete-article-btn]');
    if (customBtn instanceof HTMLElement) {
        return customBtn.closest('.seo-editor-delete-action') ?? customBtn;
    }

    return null;
}

function findRestoreAction(host) {
    const wrap = host?.querySelector?.('[data-seo-restore-action-wrap], .seo-editor-restore-action');
    if (wrap instanceof HTMLElement) {
        return wrap;
    }

    const btn = host?.querySelector?.('[data-seo-restore-wp-btn]');
    if (btn instanceof HTMLElement) {
        return btn.closest('.seo-editor-restore-action') ?? btn;
    }

    return null;
}

function styleAsMenuItem(button) {
    if (!(button instanceof HTMLElement)) {
        return;
    }

    button.classList.add('seo-editor-menu-item');
    button.classList.remove('seo-editor-toolbar-btn');

    const label =
        button.querySelector('.fi-btn-label')?.textContent?.trim()
        || button.getAttribute('title')
        || button.getAttribute('aria-label')
        || '';

    if (label !== '') {
        if (!button.getAttribute('title')) {
            button.setAttribute('title', label);
        }
        if (!button.getAttribute('aria-label')) {
            button.setAttribute('aria-label', label);
        }
    }

    const fiLabel = button.querySelector('.fi-btn-label');
    if (fiLabel instanceof HTMLElement) {
        fiLabel.classList.add('seo-editor-menu-item__label');
    }
}

function compactToolbarButtons(host) {
    host?.querySelectorAll?.('.fi-btn, a.fi-btn, .fi-icon-btn').forEach((button) => {
        styleAsMenuItem(button);
    });
}

function dedupeHostChildren(host) {
    if (!host) {
        return;
    }

    const seen = new Set();

    [...host.children].forEach((child) => {
        const fingerprint = actionFingerprint(child);
        if (fingerprint === '' || fingerprint.startsWith('primary|')) {
            return;
        }

        if (seen.has(fingerprint)) {
            child.remove();
            return;
        }

        seen.add(fingerprint);
    });
}

function clearMovedHeaderActionsFromSlot() {
    const secondary = getSecondaryHost();
    if (!secondary) {
        return;
    }

    [...secondary.children].forEach((child) => {
        if (
            child.hasAttribute('data-seo-restore-action-wrap')
            || child.classList.contains('seo-editor-restore-action')
            || child.hasAttribute('data-seo-pipeline-rerun')
        ) {
            return;
        }

        if (child.matches('.fi-btn, a.fi-btn, .fi-icon-btn, .seo-editor-menu-item') || child.querySelector?.('.fi-btn, a.fi-btn, .fi-icon-btn')) {
            if (child.hasAttribute('data-seo-restore-action-wrap') || child.hasAttribute('data-seo-delete-action-wrap')) {
                return;
            }
            if (child.matches('[data-seo-page-action="history"], [data-seo-page-action="view-wp"]')) {
                return;
            }
            child.remove();
        }
    });
}

function normalizeToolbarLayout() {
    const slot = getPageActionsSlot();
    if (!slot) {
        return;
    }

    const secondary = getSecondaryHost(slot);
    const danger = getDangerHost(slot);
    const morePanel = slot.querySelector('[data-seo-page-actions-more-panel]');

    slot.querySelectorAll(':scope > [data-seo-shortcuts-wrap]').forEach((wrap) => {
        wrap.remove();
    });

    const strayShortcuts = document.querySelectorAll(
        '.wp-seo-fields-toolbar-end > [data-seo-shortcuts-wrap], .wp-seo-fields-toolbar-end > .relative',
    );
    strayShortcuts.forEach((wrap) => {
        if (wrap.hasAttribute('data-seo-shortcuts-wrap') || wrap.querySelector('.article-editor-shortcuts-trigger')) {
            wrap.remove();
        }
    });

    [...slot.children].forEach((child) => {
        if (!(child instanceof HTMLElement) || isPersistentToolbarChild(child)) {
            return;
        }

        if (
            child.matches('.fi-btn, a.fi-btn, .fi-icon-btn, .seo-editor-toolbar-btn, .seo-editor-menu-item')
            || child.hasAttribute('data-seo-pipeline-rerun')
            || child.hasAttribute('data-seo-restore-action-wrap')
        ) {
            secondary?.appendChild(child);
        }
    });

    const deleteInSecondary = findDeleteAction(secondary);
    if (deleteInSecondary && danger && !danger.contains(deleteInSecondary)) {
        danger.appendChild(deleteInSecondary);
    }

    dedupeHostChildren(secondary);
    compactToolbarButtons(morePanel ?? slot);

    const rerunButton = secondary?.querySelector('[data-seo-pipeline-rerun]');
    const restoreAction = findRestoreAction(secondary);
    const middleButtons = secondary
        ? [...secondary.children].filter((child) => child !== rerunButton && child !== restoreAction)
        : [];

    [rerunButton, restoreAction, ...middleButtons]
        .filter(Boolean)
        .forEach((child) => secondary.appendChild(child));
}

export function mountFilamentHeaderActionsToToolbar() {
    const page = document.querySelector('.seo-article-edit-page');
    const slot = getPageActionsSlot();
    const secondary = getSecondaryHost(slot);
    const headerActions = page?.querySelector('.fi-header > div:last-child');

    if (!page || !slot || !secondary || !headerActions) {
        return false;
    }

    if (headerActions.childElementCount === 0) {
        normalizeToolbarLayout();
        window.dispatchEvent(new CustomEvent('seo-article-editor-header-actions-mounted'));

        return secondary.childElementCount > 0 || slot.querySelector('[data-seo-page-actions-primary]') !== null;
    }

    clearMovedHeaderActionsFromSlot();

    while (headerActions.firstChild) {
        const child = headerActions.firstChild;
        headerActions.removeChild(child);

        const fingerprint = actionFingerprint(child);
        const isDuplicate =
            fingerprint !== ''
            && [...secondary.children].some((existing) => actionFingerprint(existing) === fingerprint);

        if (isDuplicate) {
            continue;
        }

        secondary.appendChild(child);
    }

    normalizeToolbarLayout();

    window.dispatchEvent(new CustomEvent('seo-article-editor-header-actions-mounted'));

    return true;
}

let mountTimer = null;

export function scheduleMountFilamentHeaderActionsToToolbar() {
    if (mountTimer !== null) {
        window.clearTimeout(mountTimer);
    }

    mountTimer = window.setTimeout(() => {
        mountTimer = null;

        const attempt = (retriesLeft) => {
            if (mountFilamentHeaderActionsToToolbar() || retriesLeft <= 0) {
                return;
            }

            window.setTimeout(() => attempt(retriesLeft - 1), 80);
        };

        window.requestAnimationFrame(() => attempt(20));
    }, 40);
}

let persistenceRegistered = false;
let morphHookAttached = false;

/** Giữ nút sau khi Livewire morph / re-render partial. */
export function registerFilamentHeaderActionsPersistence() {
    if (persistenceRegistered) {
        scheduleMountFilamentHeaderActionsToToolbar();
        return;
    }

    persistenceRegistered = true;

    const attachMorphHook = () => {
        if (morphHookAttached || !window.Livewire?.hook) {
            return;
        }

        morphHookAttached = true;
        window.Livewire.hook('morph.updated', () => {
            scheduleMountFilamentHeaderActionsToToolbar();
        });
    };

    const onPageRefresh = () => {
        clearMovedHeaderActionsFromSlot();
        scheduleMountFilamentHeaderActionsToToolbar();
    };

    document.addEventListener('livewire:navigated', onPageRefresh);
    document.addEventListener('seo-article-editor-toolbar-refresh', scheduleMountFilamentHeaderActionsToToolbar);
    document.addEventListener('livewire:init', attachMorphHook);
    if (window.Livewire) {
        attachMorphHook();
    }

    const page = document.querySelector('.seo-article-edit-page');
    const header = page?.querySelector('.fi-header');
    if (header) {
        const observer = new MutationObserver(() => {
            const headerActionCount = header.querySelector(':scope > div:last-child')?.childElementCount ?? 0;
            if (headerActionCount > 0) {
                scheduleMountFilamentHeaderActionsToToolbar();
            }
        });
        observer.observe(header, { childList: true, subtree: true });
    }

    scheduleMountFilamentHeaderActionsToToolbar();
}
