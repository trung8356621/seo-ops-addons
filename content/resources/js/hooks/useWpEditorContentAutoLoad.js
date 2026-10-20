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
import { applyEditorDocumentAck } from '../utils/articleEditorApi';
import {
    contentsMeaningfullyEqual,
    loadDraft,
    stripCtaBlankPlaceholderMarkup,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
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
 * Auto-fetch WP editor HTML when body is empty + WP-linked, then hydrate blocks.
 * Server persists into articles.body via loadWpEditorHtmlFromWordPress().
 */
export default function useWpEditorContentAutoLoad({
    articleId = null,
    siteId = null,
    connectionHash = '',
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

    const hydrateHtml = useCallback((html, authority = null) => {
        const trimmed = String(html ?? '').trim();
        const blocks = trimmed === ''
            ? [createEmptyTextBlock()]
            : parseHtmlToBlocks(stripLeadingH1FromHtml(trimmed));
        skipNextAutosave.current = true;
        if (bootstrapBodyPlainRef && typeof bootstrapBodyPlainRef === 'object') {
            bootstrapBodyPlainRef.current = trimmed;
        }
        setBlocks(blocks.length > 0 ? blocks : [createEmptyTextBlock()]);
        // WP hydrate may persist body and bump document_version — ACK before first Save.
        if (authority && typeof authority === 'object') {
            applyEditorDocumentAck({
                document_version: authority.document_version,
                content_hash: authority.content_hash,
                saved_at: authority.updated_at,
                noop: true,
                reconciled: true,
            });
        }
        // Align local snapshot when it matches hydrated body — prevents F5 false Case D
        // from a pre-hydrate dirty draft without deleting real divergent WIP.
        try {
            const id = Number(articleId ?? window.__SEO_ARTICLE_ID__ ?? 0) || 0;
            const connHash = String(connectionHash || window.__SEO_CONNECTION_HASH__ || '').trim();
            const scopedSiteId = Math.max(0, Number(siteId ?? 0) || 0);
            const canonicalHtml = stripCtaBlankPlaceholderMarkup(trimmed);
            if (id > 0) {
                const draft = loadDraft(id, connHash, { siteId: scopedSiteId });
                const draftHtml = String(draft?.content ?? '');
                const noRealDelta = !draft
                    || draft.synced === true
                    || draftHtml.trim() === ''
                    || contentsMeaningfullyEqual(draftHtml, canonicalHtml);
                if (noRealDelta) {
                    writeSyncedLocalSnapshot(id, connHash, {
                        content: canonicalHtml,
                        site_id: scopedSiteId,
                        base_updated_at: authority?.updated_at || null,
                        base_content_hash: authority?.content_hash || null,
                        base_document_version: authority?.document_version || null,
                        version: authority?.content_hash || null,
                    });
                }
            }
        } catch {
            // ignore storage failures
        }
        const next = emitContentLifecycle({
            ...contentLifecycle,
            state: CONTENT_LIFECYCLE.EDITABLE,
            local_content_present: true,
        });
        setContentLifecycle(next);
        setLoadError(false);
    }, [articleId, bootstrapBodyPlainRef, connectionHash, contentLifecycle, setBlocks, setContentLifecycle, siteId, skipNextAutosave]);

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
            hydrateHtml(typeof payload.html === 'string' ? payload.html : '', {
                document_version: payload.document_version,
                content_hash: payload.content_hash,
                updated_at: payload.updated_at,
            });
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
