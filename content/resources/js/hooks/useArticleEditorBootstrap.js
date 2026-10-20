import { articleShortcutActionFromEvent } from '../utils/articleEditorShortcuts';
import { canMutateEditor } from '../utils/editorSessionState';
import { blocksFromEditorDocumentEnvelope } from '../utils/articleEditorDocument';
import {
    classifyBootstrapDraftRecovery,
    clearDraft,
    hashContent,
    loadDraft,
    saveDraft,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
import {
    BOOTSTRAP_DIVERGENT_DRAFT_REASON,
    BOOTSTRAP_RECOVERY_ACTION_EVENT,
    clearDocumentConflictRecovery,
    enterDocumentConflictBlocked,
} from '../utils/editorDocumentConflictRecovery';
import { plainTextFromHtmlLoose, repairGluedInlineMarkBoundaryWhitespaceWithReport } from '../utils/inlineWhitespaceGuard';
import { enrichBlocksWithPostImages, reconcileSupplementalImagesWithBlocks } from '@media-addon/utils/articleImagesUtils.js';
import { exportBlocksToHtml, parseHtmlToBlocks, stripLeadingH1FromHtml, createEmptyTextBlock } from '../utils/contentDocumentHelpers';
import { filterSuggestedInternalLinks, isSpecialOrContactHref, mergeSuggestionCatalog } from '../utils/articleLinkSuggestionFilter';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { seoActions, seoApi } from '@seo-addon/editor/domains/seo/state.js';
import { isCachedSeoAnalysisValid } from '../utils/seoAnalysisReadiness';
import { t } from '../utils/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    CONTENT_LIFECYCLE,
    getContentLifecycle,
} from '../utils/articleEditorContentLifecycle';

/**
 * useArticleEditorBootstrap - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorBootstrap({ analyzedBlocksRef, articleId, bootstrapBodyPlainRef, canRedo, canUndo, cancelLocalDraftSave, clearTempMerge, connectionHashRef, dismissedEditorImageMediaIdsRef, draftScope, expectedContentHash, expectedUpdatedAt, hasHydratedSeoFromServerRef, hydrateBaselineHtmlRef = null, initialEditorDocument, initialEditorDocumentHash, initialHtml, initialPostImages, initialSeo, lastAutosaveHashRef = null, loadedArticleIdRef, markSeoAnalysisReady = null, postImagesRef, redo, requestAnalyze, sessionReadOnly, setActiveBlockId, setAnalyzing, setBlocks, setExtractedLinks, setGlobalEditor, setImagesReloadKey, setSeoStale, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomainRef, skipNextAutosave, suggestionExternalCatalogRef, undo, whitespaceCorruptionLockedRef, withDraftSite }) {
    useEffect(() => {
        const isTypingTarget = (target) =>
            Boolean(
                target?.closest?.(
                    'input, textarea, [contenteditable="true"], [contenteditable=""], .ProseMirror, .tiptap-editor-content, .block-editor-active',
                ),
            );

        const onWindowKeyDown = (event) => {
            const articleAction = articleShortcutActionFromEvent(event);
            if (articleAction) {
                event.preventDefault();
                if (articleAction === 'analyze') {
                    setAnalyzing(true);
                    requestAnalyze();
                } else {
                    window.dispatchEvent(
                        new CustomEvent('article-editor-shortcut', {
                            detail: { action: articleAction },
                        }),
                    );
                }
                return;
            }

            const mod = event.ctrlKey || event.metaKey;
            if (!mod || event.altKey || isTypingTarget(event.target)) {
                return;
            }

            const key = String(event.key || '').toLowerCase();
            if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || !canMutateEditor()) {
                // Copy (C) stays; mutation shortcuts blocked in hard read-only.
                if (key === 'c') {
                    return;
                }
                if (['z', 'y', 'b', 'i', 'u', 'k'].includes(key)) {
                    event.preventDefault();
                }
                return;
            }

            if (key === 'z') {
                event.preventDefault();
                if (event.shiftKey) {
                    if (canRedo) {
                        redo();
                    }
                } else if (canUndo) {
                    undo();
                }
                return;
            }

            if (key === 'y') {
                event.preventDefault();
                if (canRedo) {
                    redo();
                }
            }
        };

        window.addEventListener('keydown', onWindowKeyDown, true);

        return () => {
            window.removeEventListener('keydown', onWindowKeyDown, true);
        };
    }, [undo, redo, canUndo, canRedo, requestAnalyze, sessionReadOnly]);

    // Hydrate: silent A/B/C; Case D → blocking overlay (no 3-button modal).
    const [draftRestoreOffer, setDraftRestoreOffer] = useState(null);
    const draftRestoreOfferRef = useRef(null);
    draftRestoreOfferRef.current = draftRestoreOffer;

    const applyServerBlocksBaseline = useCallback((serverBlocks, serverBodyHash, serverContentHash) => {
        setBlocks(serverBlocks);
        cancelLocalDraftSave();
        window.__seoCancelArticleDraftAutosave?.();
        const analyzedContentHash = String(initialSeo?.analyzed_content_hash ?? '').trim();
        const analysisFresh = analyzedContentHash !== ''
            && serverBodyHash !== ''
            && analyzedContentHash === serverBodyHash;
        if (analysisFresh && isCachedSeoAnalysisValid(initialSeo, {
            contentHash: serverBodyHash,
            bodyHash: serverBodyHash,
        })) {
            markSeoAnalysisReady?.(true);
            setSeoStale(false);
        } else {
            markSeoAnalysisReady?.(false);
            setSeoStale(false);
        }
    }, [cancelLocalDraftSave, initialSeo, markSeoAnalysisReady, setBlocks, setSeoStale]);

    useEffect(() => {
        if (!articleId) return;
        if (loadedArticleIdRef.current === articleId) return;

        loadedArticleIdRef.current = articleId;
        dismissedEditorImageMediaIdsRef.current = new Set();
        skipNextAutosave.current = true;
        whitespaceCorruptionLockedRef.current = false;
        clearTempMerge();

        const connHash = connectionHashRef.current;
        const scope = draftScope();
        const draft = loadDraft(articleId, connHash, scope);

        // Absolute recovery: DB may already have glued mark boundaries (saved after old bug).
        const serverHtmlRepair = repairGluedInlineMarkBoundaryWhitespaceWithReport(initialHtml);
        let effectiveInitialHtml = serverHtmlRepair.html;
        if (serverHtmlRepair.repaired) {
            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_inline_whitespace_repaired_title'),
                    body: t('editor_inline_whitespace_repaired_body'),
                    status: 'warning',
                },
            }));
        }
        bootstrapBodyPlainRef.current = plainTextFromHtmlLoose(effectiveInitialHtml);
        // Always server body — Case C restores draft into blocks but baseline stays ACK/server
        // so TipTap remount of draft still autosaves, while A/B TipTap noise does not.
        if (hydrateBaselineHtmlRef && typeof hydrateBaselineHtmlRef === 'object') {
            hydrateBaselineHtmlRef.current = effectiveInitialHtml;
        }

        const serverBodyHash = hashContent(effectiveInitialHtml);
        const serverContentHash = String(expectedContentHash ?? '').trim() || serverBodyHash;
        // Prefer HTML when server HTML was repaired — corrupted JSON must not win.
        const serverBlocksFromJson = serverHtmlRepair.repaired
            ? null
            : blocksFromEditorDocumentEnvelope(initialEditorDocument, effectiveInitialHtml);
        let serverBlocks = enrichBlocksWithPostImages(
            serverBlocksFromJson
                ?? parseHtmlToBlocks(stripLeadingH1FromHtml(effectiveInitialHtml)),
            postImagesRef.current,
        );
        const lifecycleState = String(getContentLifecycle()?.state || '');
        if (
            lifecycleState === CONTENT_LIFECYCLE.CONTENT_LOADING
            || lifecycleState === CONTENT_LIFECYCLE.ERROR
            || lifecycleState === CONTENT_LIFECYCLE.SYNC_REQUIRED
        ) {
            // WP auto-load pending / failed — never auto-apply hollow draft as editable.
            setBlocks([]);
            markSeoAnalysisReady?.(false);
            setSeoStale(false);
            setDraftRestoreOffer(null);
            setActiveBlockId(null);
            setGlobalEditor(null);
            skipNextAutosave.current = true;
            return;
        }
        if (
            serverBlocks.length === 0
            && lifecycleState === CONTENT_LIFECYCLE.NEW_EMPTY_ARTICLE
        ) {
            serverBlocks = enrichBlocksWithPostImages(
                [createEmptyTextBlock()],
                postImagesRef.current,
            );
        }
        if (initialEditorDocumentHash && !serverHtmlRepair.repaired) {
            window.__SEO_EDITOR_DOCUMENT_HASH__ = String(initialEditorDocumentHash);
        }

        analyzedBlocksRef.current = null;

        const serverState = {
            content_hash: serverBodyHash || serverContentHash,
            expected_content_hash: serverContentHash,
            site_id: scope.siteId,
            updated_at: expectedUpdatedAt || null,
            content: effectiveInitialHtml,
            version: serverContentHash,
            document_version: window.__SEO_EDITOR_DOCUMENT_VERSION__ ?? null,
        };
        const decision = classifyBootstrapDraftRecovery(draft, serverState);
        const hardReadonly = Boolean(sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__);

        if (typeof window !== 'undefined' && (window.__SEO_EDITOR_VERSION_DEBUG__ || window.__SEO_APP_DEBUG__ || decision === 'block')) {
            // Compact bootstrap evidence only — never log article body.
            // eslint-disable-next-line no-console
            console.info('[article-editor-bootstrap]', {
                article_id: articleId,
                decision,
                boot_document_version: serverState.document_version,
                boot_content_hash: String(serverContentHash || '').slice(0, 12) || null,
                draft_base_hash: String(draft?.base_content_hash ?? '').slice(0, 12) || null,
                draft_content_hash: String(draft?.content_hash ?? '').slice(0, 12) || null,
                draft_base_document_version: draft?.base_document_version ?? null,
                draft_dirty: Array.isArray(draft?.dirty_fields) ? draft.dirty_fields : null,
                draft_synced: draft?.synced ?? null,
            });
        }

        // Locked session: keep local draft, never auto-apply / clear / overwrite canonical.
        if (hardReadonly) {
            applyServerBlocksBaseline(serverBlocks, serverBodyHash, serverContentHash);
            setDraftRestoreOffer(null);
            setActiveBlockId(null);
            setGlobalEditor(null);
            return;
        }

        if (decision === 'restore_local' && draft) {
            const draftContentRaw = String(draft.content ?? '');
            const draftRepair = repairGluedInlineMarkBoundaryWhitespaceWithReport(draftContentRaw);
            if (draftRepair.repaired) {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_inline_whitespace_repaired_title'),
                        body: t('editor_inline_whitespace_repaired_body'),
                        status: 'warning',
                    },
                }));
            }
            bootstrapBodyPlainRef.current = plainTextFromHtmlLoose(draftRepair.html);
            const restoredBlocks = enrichBlocksWithPostImages(
                parseHtmlToBlocks(stripLeadingH1FromHtml(draftRepair.html)),
                postImagesRef.current,
            );
            setBlocks(restoredBlocks);
            setSeoStale(true);
            markSeoAnalysisReady?.(false);
            // Silent Case C — keep dirty draft until server ACK; no modal/toast.
            setDraftRestoreOffer(null);
        } else if (decision === 'block' && draft) {
            // Case D — preserve snapshot, show server under blocking overlay.
            applyServerBlocksBaseline(serverBlocks, serverBodyHash, serverContentHash);
            setDraftRestoreOffer({ draft, serverBlocks });
            enterDocumentConflictBlocked({
                reason: BOOTSTRAP_DIVERGENT_DRAFT_REASON,
                article_id: articleId,
            });
        } else {
            // Case A/B — use server, clear redundant snapshot.
            applyServerBlocksBaseline(serverBlocks, serverBodyHash, serverContentHash);
            clearDraft(articleId, connHash, scope);
            writeSyncedLocalSnapshot(articleId, connHash, withDraftSite({
                content: exportBlocksToHtml(serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: serverContentHash,
                version: serverContentHash,
            }));
            if (lastAutosaveHashRef && typeof lastAutosaveHashRef === 'object') {
                lastAutosaveHashRef.current = serverContentHash;
            }
            setDraftRestoreOffer(null);
        }

        setActiveBlockId(null);
        setGlobalEditor(null);
    }, [articleId, initialHtml, initialPostImages, expectedUpdatedAt, expectedContentHash, clearTempMerge, draftScope, withDraftSite, cancelLocalDraftSave, initialSeo, sessionReadOnly, initialEditorDocument, initialEditorDocumentHash, applyServerBlocksBaseline, markSeoAnalysisReady]);

    const applyDraftRestore = useCallback(() => {
        const offer = draftRestoreOfferRef.current;
        if (!offer) return;
        const html = String(offer.draft?.content ?? '');
        const restoredBlocks = enrichBlocksWithPostImages(
            parseHtmlToBlocks(stripLeadingH1FromHtml(html)),
            postImagesRef.current,
        );
        setBlocks(restoredBlocks);
        setDraftRestoreOffer(null);
        setSeoStale(true);
        markSeoAnalysisReady?.(false);
        // Rebase draft baseline onto current server ACK so the next reload is Case C (silent), not Case D again.
        const serverHash = String(expectedContentHash ?? '').trim() || hashContent(String(initialHtml ?? ''));
        saveDraft(articleId, connectionHashRef.current, withDraftSite({
            content: html,
            editor_document: offer.draft?.editor_document ?? null,
            base_updated_at: expectedUpdatedAt || null,
            base_content_hash: serverHash,
            base_document_version: window.__SEO_EDITOR_DOCUMENT_VERSION__ || null,
            content_hash: hashContent(html),
            dirty_fields: ['content'],
            synced: false,
        }));
        clearDocumentConflictRecovery();
    }, [
        articleId,
        expectedContentHash,
        expectedUpdatedAt,
        initialHtml,
        markSeoAnalysisReady,
        setBlocks,
        setSeoStale,
        withDraftSite,
    ]);

    const keepServerOverDraft = useCallback(() => {
        const offer = draftRestoreOfferRef.current;
        clearDraft(articleId, connectionHashRef.current, draftScope());
        if (offer?.serverBlocks) {
            setBlocks(offer.serverBlocks);
            writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                content: exportBlocksToHtml(offer.serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: String(expectedContentHash ?? '').trim(),
                version: String(expectedContentHash ?? '').trim(),
            }));
        }
        setDraftRestoreOffer(null);
        clearDocumentConflictRecovery();
    }, [articleId, draftScope, expectedUpdatedAt, expectedContentHash, withDraftSite, setBlocks]);

    useEffect(() => {
        const onBootstrapRecoveryAction = (event) => {
            const action = String(event?.detail?.action ?? '').trim();
            if (action === 'restore_local') {
                // Temporarily clear conflict flag so mutation path can apply blocks.
                clearDocumentConflictRecovery();
                applyDraftRestore();
                return;
            }
            if (action === 'use_server') {
                keepServerOverDraft();
            }
        };
        window.addEventListener(BOOTSTRAP_RECOVERY_ACTION_EVENT, onBootstrapRecoveryAction);
        return () => {
            window.removeEventListener(BOOTSTRAP_RECOVERY_ACTION_EVENT, onBootstrapRecoveryAction);
        };
    }, [applyDraftRestore, keepServerOverDraft]);

    useEffect(() => {
        if (!initialSeo || hasHydratedSeoFromServerRef.current) {
            return;
        }

        hasHydratedSeoFromServerRef.current = true;
        const bodyHash = hashContent(String(initialHtml ?? ''));
        const currentHash = String(expectedContentHash ?? '').trim() || bodyHash;
        const cacheValid = isCachedSeoAnalysisValid(initialSeo, {
            contentHash: bodyHash || currentHash,
            bodyHash,
        });

        if (cacheValid) {
            seoApi.adopt(initialSeo.analysis, initialSeo.focus_keyword ?? '');
            markSeoAnalysisReady?.(true);
            setSeoStale(false);
        } else {
            seoActions.clearAnalysis();
            seoApi.adopt(null, initialSeo.focus_keyword ?? '');
            markSeoAnalysisReady?.(false);
            setSeoStale(false);
        }
        seoActions.markClean();
        setExtractedLinks({
            internal: initialSeo.extracted_links?.internal ?? [],
            external: (initialSeo.extracted_links?.external ?? []).filter(
                (item) => !isSpecialOrContactHref(item?.href),
            ),
        });
        setSuggestedInternalLinks(
            filterSuggestedInternalLinks(
                initialSeo.suggested_internal_links ?? [],
                initialSeo.extracted_links?.internal ?? [],
                initialSeo.extracted_links?.external ?? [],
            ),
        );
        setSuggestedExternalLinks(
            filterSuggestedInternalLinks(
                initialSeo.suggested_external_links ?? [],
                initialSeo.extracted_links?.internal ?? [],
                initialSeo.extracted_links?.external ?? [],
            ).filter((item) => {
                const href = String(item?.href ?? item?.target_url ?? '').trim();

                return href !== '' && !isSpecialOrContactHref(href);
            }),
        );
        if (String(initialSeo.site_domain ?? '').trim() !== '') {
            siteDomainRef.current = String(initialSeo.site_domain).trim();
        }
        if (Array.isArray(initialSeo.suggested_external_links_catalog)) {
            suggestionExternalCatalogRef.current = mergeSuggestionCatalog(
                initialSeo.suggested_external_links_catalog,
                initialSeo.suggested_external_links ?? [],
            );
        }
    }, [initialSeo, expectedContentHash, initialHtml, markSeoAnalysisReady]);

    const reconcileImagesTabWithBlocks = useCallback((nextBlocks) => {
        mediaActions.setSupplementalImages((prev) => reconcileSupplementalImagesWithBlocks(prev, nextBlocks));
        setImagesReloadKey((key) => key + 1);
    }, []);

    return { applyDraftRestore, draftRestoreOffer, keepServerOverDraft, reconcileImagesTabWithBlocks };
}
