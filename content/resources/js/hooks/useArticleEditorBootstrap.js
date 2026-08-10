import { ARTICLE_EDITOR_DRAFT_ALERT_EVENT, ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT } from '../utils/articleEditorStickyHeader';
import { articleShortcutActionFromEvent } from '../utils/articleEditorShortcuts';
import { assertWritableEditorSession, canMutateEditor } from '../utils/editorSessionState';
import { blocksFromEditorDocumentEnvelope } from '../utils/articleEditorDocument';
import {
    clearDraft,
    draftOffersManualChoice,
    hashContent,
    loadDraft,
    resolveLocalDraftDecision,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
import { countGluedInlineMarkBoundaries, plainTextFromHtmlLoose, repairGluedInlineMarkBoundaryWhitespaceWithReport } from '../utils/inlineWhitespaceGuard';
import { enrichBlocksWithPostImages, reconcileSupplementalImagesWithBlocks } from '@media-addon/utils/articleImagesUtils.js';
import { exportBlocksToHtml, parseHtmlToBlocks, stripLeadingH1FromHtml } from '../utils/contentDocumentHelpers';
import { filterSuggestedInternalLinks, isSpecialOrContactHref, mergeSuggestionCatalog } from '../utils/articleLinkSuggestionFilter';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { seoActions, seoApi } from '@seo-addon/editor/domains/seo/state.js';
import { t } from '../utils/i18n';
import { useCallback, useEffect, useState } from 'react';

/**
 * useArticleEditorBootstrap - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorBootstrap({ analyzedBlocksRef, articleId, bootstrapBodyPlainRef, canRedo, canUndo, cancelLocalDraftSave, clearTempMerge, connectionHashRef, dismissedEditorImageMediaIdsRef, draftScope, expectedContentHash, expectedUpdatedAt, hasHydratedSeoFromServerRef, initialEditorDocument, initialEditorDocumentHash, initialHtml, initialPostImages, initialSeo, loadedArticleIdRef, postImagesRef, redo, requestAnalyze, sessionReadOnly, setActiveBlockId, setAnalyzing, setBlocks, setExtractedLinks, setGlobalEditor, setImagesReloadKey, setSeoStale, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomainRef, skipNextAutosave, suggestionExternalCatalogRef, undo, whitespaceCorruptionLockedRef, withDraftSite }) {
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

    // Hydrate: tự chọn bản gần nhất; nếu local≠server thì hiện nút ! trên sticky header để mở modal chọn lại.
    const [draftRestoreOffer, setDraftRestoreOffer] = useState(null);
    const [draftChoiceModalOpen, setDraftChoiceModalOpen] = useState(false);

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

        const serverBodyHash = hashContent(effectiveInitialHtml);
        const serverContentHash = String(expectedContentHash ?? '').trim() || serverBodyHash;
        // Prefer HTML when server HTML was repaired — corrupted JSON must not win.
        const serverBlocksFromJson = serverHtmlRepair.repaired
            ? null
            : blocksFromEditorDocumentEnvelope(initialEditorDocument, effectiveInitialHtml);
        const serverBlocks = enrichBlocksWithPostImages(
            serverBlocksFromJson
                ?? parseHtmlToBlocks(stripLeadingH1FromHtml(effectiveInitialHtml)),
            postImagesRef.current,
        );
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
        };
        const decision = resolveLocalDraftDecision(draft, serverState);
        const canManualChoose = draftOffersManualChoice(draft, serverState);
        const hardReadonly = Boolean(sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__);

        // Locked session: keep local draft, never auto-apply / clear / overwrite canonical.
        if (hardReadonly) {
            setBlocks(serverBlocks);
            const analyzedContentHash = String(initialSeo?.analyzed_content_hash ?? '').trim();
            setSeoStale(
                analyzedContentHash !== ''
                && serverBodyHash !== ''
                && analyzedContentHash !== serverBodyHash,
            );
            setDraftRestoreOffer(null);
            setDraftChoiceModalOpen(false);
            setActiveBlockId(null);
            setGlobalEditor(null);
            return;
        }

        const draftContentRaw = String(draft?.content ?? '');
        const draftGlue = draft ? countGluedInlineMarkBoundaries(draftContentRaw) : 0;
        const serverGlue = countGluedInlineMarkBoundaries(effectiveInitialHtml);
        const preferServerOverGluedDraft = Boolean(draft)
            && decision === 'restore_local'
            && draftGlue > serverGlue;

        if (decision === 'restore_local' && draft && !preferServerOverGluedDraft) {
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
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_draft_auto_restored_title'),
                        body: t('editor_draft_auto_restored_body'),
                        status: 'info',
                    },
                }),
            );
        } else {
            setBlocks(serverBlocks);
            cancelLocalDraftSave();
            window.__seoCancelArticleDraftAutosave?.();
            // Keep a glued draft only when it is healthier than server; otherwise clear so F5 recovers.
            if (!draft || draftGlue >= serverGlue) {
                clearDraft(articleId, connHash, scope);
            }
            writeSyncedLocalSnapshot(articleId, connHash, withDraftSite({
                content: exportBlocksToHtml(serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: serverContentHash,
                version: serverContentHash,
            }));

            const analyzedContentHash = String(initialSeo?.analyzed_content_hash ?? '').trim();
            setSeoStale(
                analyzedContentHash !== ''
                && serverBodyHash !== ''
                && analyzedContentHash !== serverBodyHash,
            );
        }

        if (canManualChoose && draft && !preferServerOverGluedDraft) {
            setDraftRestoreOffer({ draft, serverBlocks });
            setDraftChoiceModalOpen(false);
        } else {
            setDraftRestoreOffer(null);
            setDraftChoiceModalOpen(false);
        }

        setActiveBlockId(null);
        setGlobalEditor(null);
    }, [articleId, initialHtml, initialPostImages, expectedUpdatedAt, expectedContentHash, clearTempMerge, draftScope, withDraftSite, cancelLocalDraftSave, initialSeo, sessionReadOnly, initialEditorDocument, initialEditorDocumentHash]);

    // After lock → writable (retry/takeover): offer kept local draft, never auto-apply.
    useEffect(() => {
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || !articleId) {
            return;
        }
        if (draftRestoreOffer) {
            return;
        }
        const connHash = connectionHashRef.current;
        const scope = draftScope();
        const draft = loadDraft(articleId, connHash, scope);
        if (!draft) {
            return;
        }
        const serverBodyHash = hashContent(initialHtml);
        const serverContentHash = String(expectedContentHash ?? '').trim() || serverBodyHash;
        const serverBlocksFromJson = blocksFromEditorDocumentEnvelope(initialEditorDocument, initialHtml);
        const serverBlocks = enrichBlocksWithPostImages(
            serverBlocksFromJson
                ?? parseHtmlToBlocks(stripLeadingH1FromHtml(initialHtml)),
            postImagesRef.current,
        );
        const serverState = {
            content_hash: serverBodyHash || serverContentHash,
            expected_content_hash: serverContentHash,
            site_id: scope.siteId,
            updated_at: expectedUpdatedAt || null,
            content: initialHtml,
            version: serverContentHash,
        };
        if (!draftOffersManualChoice(draft, serverState)) {
            return;
        }
        setDraftRestoreOffer({ draft, serverBlocks });
        setDraftChoiceModalOpen(false);
    }, [
        sessionReadOnly,
        articleId,
        draftRestoreOffer,
        draftScope,
        initialHtml,
        initialEditorDocument,
        expectedContentHash,
        expectedUpdatedAt,
    ]);

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent(ARTICLE_EDITOR_DRAFT_ALERT_EVENT, {
                detail: {
                    visible: Boolean(draftRestoreOffer),
                    title: t('editor_draft_choice_button_hint'),
                },
            }),
        );

        return () => {
            window.dispatchEvent(
                new CustomEvent(ARTICLE_EDITOR_DRAFT_ALERT_EVENT, {
                    detail: { visible: false },
                }),
            );
        };
    }, [draftRestoreOffer]);

    useEffect(() => {
        const onOpenDraftChoice = () => {
            if (!draftRestoreOffer) {
                return;
            }
            setDraftChoiceModalOpen(true);
        };

        window.addEventListener(ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT, onOpenDraftChoice);

        return () => {
            window.removeEventListener(ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT, onOpenDraftChoice);
        };
    }, [draftRestoreOffer]);

    const applyDraftRestore = useCallback(() => {
        if (!draftRestoreOffer) return;
        if (!canMutateEditor()) {
            assertWritableEditorSession('editor_read_only');
            return;
        }
        const restoredBlocks = enrichBlocksWithPostImages(
            parseHtmlToBlocks(stripLeadingH1FromHtml(String(draftRestoreOffer.draft?.content ?? ''))),
            postImagesRef.current,
        );
        setBlocks(restoredBlocks);
        setDraftChoiceModalOpen(false);
        setDraftRestoreOffer(null);
        setSeoStale(true);
    }, [draftRestoreOffer]);

    const discardDraftRestore = useCallback(() => {
        clearDraft(articleId, connectionHashRef.current, draftScope());
        if (draftRestoreOffer?.serverBlocks) {
            setBlocks(draftRestoreOffer.serverBlocks);
            writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                content: exportBlocksToHtml(draftRestoreOffer.serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: String(expectedContentHash ?? '').trim(),
                version: String(expectedContentHash ?? '').trim(),
            }));
        }
        setDraftChoiceModalOpen(false);
        setDraftRestoreOffer(null);
    }, [articleId, draftRestoreOffer, draftScope, expectedUpdatedAt, expectedContentHash, withDraftSite]);

    const keepServerOverDraft = useCallback(() => {
        clearDraft(articleId, connectionHashRef.current, draftScope());
        if (draftRestoreOffer?.serverBlocks) {
            setBlocks(draftRestoreOffer.serverBlocks);
            writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                content: exportBlocksToHtml(draftRestoreOffer.serverBlocks),
                base_updated_at: expectedUpdatedAt || null,
                base_content_hash: String(expectedContentHash ?? '').trim(),
                version: String(expectedContentHash ?? '').trim(),
            }));
        }
        setDraftChoiceModalOpen(false);
        setDraftRestoreOffer(null);
    }, [articleId, draftRestoreOffer, draftScope, expectedUpdatedAt, expectedContentHash, withDraftSite]);

    useEffect(() => {
        if (!initialSeo || hasHydratedSeoFromServerRef.current) {
            return;
        }

        hasHydratedSeoFromServerRef.current = true;
        seoApi.adopt(initialSeo.analysis ?? null, initialSeo.focus_keyword ?? '');
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
    }, [initialSeo]);

    const reconcileImagesTabWithBlocks = useCallback((nextBlocks) => {
        mediaActions.setSupplementalImages((prev) => reconcileSupplementalImagesWithBlocks(prev, nextBlocks));
        setImagesReloadKey((key) => key + 1);
    }, []);

    return { applyDraftRestore, discardDraftRestore, draftChoiceModalOpen, draftRestoreOffer, keepServerOverDraft, reconcileImagesTabWithBlocks };
}
