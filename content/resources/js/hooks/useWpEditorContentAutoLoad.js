import { useCallback, useEffect, useRef, useState } from 'react';
import {
    CONTENT_LIFECYCLE,
    emitContentLifecycle,
} from '../utils/articleEditorContentLifecycle';
import {
    createEmptyTextBlock,
    parseHtmlToBlocks,
    stripLeadingH1FromHtml,
} from '../utils/contentDocumentHelpers';
import { t } from '../utils/i18n';

function resolveEditArticleWire() {
    const wireId = String(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__ ?? '').trim();
    if (typeof window.__seoResolveEditArticleWire === 'function') {
        return window.__seoResolveEditArticleWire(wireId);
    }
    if (typeof Livewire !== 'undefined' && wireId !== '') {
        return Livewire.find(wireId);
    }

    return null;
}

/**
 * Auto-fetch WP editor HTML on cache miss. Does not write articles.body.
 */
export default function useWpEditorContentAutoLoad({
    contentLifecycle,
    setContentLifecycle,
    setBlocks,
    skipNextAutosave,
    bootstrapBodyPlainRef,
    allowFetch,
}) {
    const startedRef = useRef(false);
    const [loadError, setLoadError] = useState(false);
    const [loadBusy, setLoadBusy] = useState(false);

    const hydrateHtml = useCallback((html) => {
        const trimmed = String(html ?? '').trim();
        const blocks = trimmed === ''
            ? [createEmptyTextBlock()]
            : parseHtmlToBlocks(stripLeadingH1FromHtml(trimmed));
        skipNextAutosave.current = true;
        if (bootstrapBodyPlainRef && typeof bootstrapBodyPlainRef === 'object') {
            bootstrapBodyPlainRef.current = trimmed;
        }
        setBlocks(blocks.length > 0 ? blocks : [createEmptyTextBlock()]);
        const next = emitContentLifecycle({
            ...contentLifecycle,
            state: CONTENT_LIFECYCLE.EDITABLE,
            local_content_present: true,
        });
        setContentLifecycle(next);
        setLoadError(false);
    }, [bootstrapBodyPlainRef, contentLifecycle, setBlocks, setContentLifecycle, skipNextAutosave]);

    const load = useCallback(async () => {
        if (!allowFetch || loadBusy) {
            return;
        }

        const wire = resolveEditArticleWire();
        if (!wire?.call) {
            setLoadError(true);
            setContentLifecycle(emitContentLifecycle({
                ...contentLifecycle,
                state: CONTENT_LIFECYCLE.ERROR,
            }));
            return;
        }

        setLoadBusy(true);
        setLoadError(false);
        setContentLifecycle(emitContentLifecycle({
            ...contentLifecycle,
            state: CONTENT_LIFECYCLE.CONTENT_LOADING,
        }));

        try {
            const result = await wire.call('loadWpEditorHtmlFromWordPress');
            const payload = result && typeof result === 'object' ? result : {};
            if (payload.success === false) {
                setLoadError(true);
                setContentLifecycle(emitContentLifecycle({
                    ...contentLifecycle,
                    state: CONTENT_LIFECYCLE.ERROR,
                }));
                return;
            }
            hydrateHtml(typeof payload.html === 'string' ? payload.html : '');
        } catch (_err) {
            setLoadError(true);
            setContentLifecycle(emitContentLifecycle({
                ...contentLifecycle,
                state: CONTENT_LIFECYCLE.ERROR,
            }));
            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('content_wp_load_failed'),
                    body: t('content_wp_load_retry'),
                    status: 'warning',
                },
            }));
        } finally {
            setLoadBusy(false);
        }
    }, [allowFetch, contentLifecycle, hydrateHtml, loadBusy, setContentLifecycle]);

    useEffect(() => {
        if (!contentLifecycle.wordpress_linked) {
            return;
        }
        if (contentLifecycle.local_content_present) {
            return;
        }
        if (contentLifecycle.state === CONTENT_LIFECYCLE.ERROR) {
            return;
        }
        if (contentLifecycle.state === CONTENT_LIFECYCLE.EDITABLE
            || contentLifecycle.state === CONTENT_LIFECYCLE.NEW_EMPTY_ARTICLE) {
            return;
        }
        if (!allowFetch) {
            return;
        }
        if (startedRef.current) {
            return;
        }
        startedRef.current = true;
        void load();
    }, [allowFetch, contentLifecycle, load]);

    const retry = useCallback(() => {
        startedRef.current = false;
        setLoadError(false);
        void load();
    }, [load]);

    return { loadError, loadBusy, retry };
}
