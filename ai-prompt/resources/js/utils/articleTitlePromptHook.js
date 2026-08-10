/**
 * Nút AI gợi ý tiêu đề — mount cạnh .wp-title-input (Livewire, ngoài React hub).
 */
import {
    executePromptHookViaApi,
    readArticleMetaFromDom,
    showArticleEditorFilamentToast,
} from '@content-addon/utils/articleEditorApi.js';
import { t } from '@content-addon/utils/i18n.js';

const HOOK_KEY = 'article.title_suggestion';

function readEditorSettings() {
    try {
        const el = document.getElementById('seo-article-editor-settings');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return {};
        }

        return JSON.parse(raw);
    } catch {
        return {};
    }
}

function readArticleId() {
    try {
        const el = document.getElementById('seo-article-meta');
        const raw = el?.textContent?.trim();
        if (!raw) {
            return 0;
        }
        const meta = JSON.parse(raw);

        return Number(meta?.id ?? 0);
    } catch {
        return 0;
    }
}

function notify(title, body, status = 'danger') {
    // Gọi toast trực tiếp — sau wire.set Alpine morph có thể nuốt CustomEvent.
    showArticleEditorFilamentToast({ title, body, status });
}

function resolveLivewire() {
    if (typeof Livewire === 'undefined') {
        return null;
    }

    const wireId =
        String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim()
        || document.querySelector('.seo-article-edit-page[wire\\:id]')?.getAttribute('wire:id')
        || '';

    if (wireId === '') {
        return null;
    }

    const component = Livewire.find(wireId);

    return component?.set ? component : null;
}

function sparklesSvg() {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>`;
}

function loaderSvg() {
    return `<svg class="seo-prompt-hook-ai-btn__spinner" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>`;
}

export function mountArticleTitlePromptHook() {
    const toolbar = document.querySelector('.wp-article-edit .wp-postbox-title-toolbar');
    const input = toolbar?.querySelector('input.wp-title-input, input[wire\\:model\\.blur="articleTitle"]');
    if (!toolbar || !input || toolbar.querySelector('[data-seo-title-hook-btn]')) {
        return;
    }

    const settings = readEditorSettings();
    const hookCfg = settings?.prompt_hooks?.title_suggestion ?? {};
    const configured = hookCfg.configured === true;
    const hookKey = String(hookCfg.hook_key || HOOK_KEY).trim() || HOOK_KEY;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'seo-prompt-hook-ai-btn';
    btn.setAttribute('data-seo-title-hook-btn', '1');
    btn.innerHTML = sparklesSvg();

    let running = false;
    let requestSeq = 0;

    const syncDisabled = () => {
        const meta = readArticleMetaFromDom();
        const keyword = String(meta.focus_keyword ?? '').trim();
        const noKeyword = keyword === '';
        btn.disabled = running || !configured || noKeyword;
        btn.classList.toggle('is-loading', running);
        if (!configured) {
            btn.title = t('prompt_hook_title_no_prompt');
        } else if (noKeyword) {
            btn.title = t('prompt_hook_title_no_keyword');
        } else if (running) {
            btn.title = t('prompt_hook_title_running');
        } else {
            btn.title = t('prompt_hook_title_tooltip');
        }
        btn.setAttribute('aria-label', btn.title);
    };

    input.addEventListener('input', syncDisabled);
    input.addEventListener('blur', syncDisabled);

    btn.addEventListener('click', async () => {
        if (running || btn.disabled) {
            return;
        }

        const articleId = readArticleId();
        const meta = readArticleMetaFromDom();
        const keyword = String(meta.focus_keyword ?? '').trim();
        const oldTitleRaw = String(input.value ?? '').trim();
        const titleSnapshot = oldTitleRaw;

        if (!configured) {
            notify(t('prompt_hook_title_failed'), t('prompt_hook_title_no_prompt'));

            return;
        }

        if (keyword === '') {
            notify(t('prompt_hook_title_failed'), t('prompt_hook_title_no_keyword'));

            return;
        }

        if (articleId <= 0) {
            notify(t('prompt_hook_title_failed'), t('prompt_hook_try_again'));

            return;
        }

        running = true;
        const seq = ++requestSeq;
        btn.innerHTML = loaderSvg();
        syncDisabled();

        try {
            const result = await executePromptHookViaApi(hookKey, articleId, {
                keyword,
                old_title: oldTitleRaw === '' ? null : oldTitleRaw,
            });

            if (seq !== requestSeq) {
                return;
            }

            const value = String(result?.data?.output?.value ?? '').trim();
            if (value === '') {
                throw new Error(t('prompt_hook_title_empty'));
            }

            const current = String(input.value ?? '').trim();
            if (current !== titleSnapshot) {
                notify(
                    t('prompt_hook_title_stale'),
                    t('prompt_hook_title_stale_body', { title: value }),
                    'warning',
                );

                return;
            }

            input.value = value;
            const wire = resolveLivewire();
            if (wire) {
                // false = skip re-render — tránh morph xóa toast / remount nút.
                try {
                    await wire.set('articleTitle', value, false);
                } catch {
                    await wire.set('articleTitle', value);
                }
            }

            notify(t('prompt_hook_title_success'), value, 'success');
        } catch (error) {
            if (seq !== requestSeq) {
                return;
            }
            notify(
                t('prompt_hook_title_failed'),
                error?.message ?? t('prompt_hook_try_again'),
            );
        } finally {
            if (seq === requestSeq) {
                running = false;
                btn.innerHTML = sparklesSvg();
                syncDisabled();
            }
        }
    });

    toolbar.appendChild(btn);
    syncDisabled();

    window.addEventListener('seo-focus-keyword-updated', syncDisabled);
    window.addEventListener('google-serp-preview-updated', syncDisabled);
}
