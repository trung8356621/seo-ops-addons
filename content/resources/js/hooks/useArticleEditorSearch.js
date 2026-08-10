import { countKeywordInSectionBlocks, normalizeImageSrcKey, replaceTextInHtmlContent } from '../utils/contentDocumentHelpers';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { t } from '../utils/i18n';
import { useCallback, useEffect } from 'react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';

/**
 * useArticleEditorSearch - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorSearch({ blockById, clearTempMerge, commitActiveBlock, editorSections, featuredSnippetTargetRef, insertFeaturedSnippetAsNewSectionAfter, publishEditorImagesCatalogRef, quickReplaceFind, quickReplaceValue, setBlocks, setCollapsedSectionIds, setEditorSearchMatchCount, setFeaturedSnippetGenerating, setFeaturedSnippetPreviewHtml, setImagesReloadKey, tempMergeRef }) {
    useEffect(() => {
        const onFeaturedSnippetGenerated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const html = String(detail.html ?? '').trim();
            const pending = featuredSnippetTargetRef.current;

            if (!html) {
                featuredSnippetTargetRef.current = null;
                setFeaturedSnippetGenerating(false);
                return;
            }

            if (pending?.mode === 'prompt-preview') {
                featuredSnippetTargetRef.current = {
                    ...pending,
                    mode: 'prompt-insert',
                    previewHtml: html,
                };
                setFeaturedSnippetPreviewHtml(html);
                setFeaturedSnippetGenerating(false);
                return;
            }

            if (pending?.mode !== 'new-section-after') {
                featuredSnippetTargetRef.current = null;
                setFeaturedSnippetGenerating(false);
                return;
            }

            featuredSnippetTargetRef.current = null;

            void insertFeaturedSnippetAsNewSectionAfter(pending, html).finally(() => {
                setFeaturedSnippetGenerating(false);
            });
        };

        window.addEventListener('article-featured-snippet-generated', onFeaturedSnippetGenerated);

        return () => {
            window.removeEventListener('article-featured-snippet-generated', onFeaturedSnippetGenerated);
        };
    }, [insertFeaturedSnippetAsNewSectionAfter]);

    useEffect(() => {
        const normalizeSelectedImage = (payload = {}) => {
            const src = String(payload.url || payload.src || '').trim();
            if (!src) {
                return null;
            }

            const mode = String(payload.mode || '').trim();
            const wpAttachmentId = Number(payload.wpAttachmentId ?? payload.wp_attachment_id ?? 0);
            const seoMediaId = Number(payload.seoMediaId ?? payload.seo_media_id ?? 0);
            const slug = String(payload.slug || '').trim();
            const alt = String(payload.alt || '').trim();
            const isLocal = src.includes('/storage/uploads/seo_media/');

            return {
                key:
                    wpAttachmentId > 0
                        ? `wp_${wpAttachmentId}`
                        : seoMediaId > 0
                          ? `seo_${seoMediaId}`
                          : `src_${src}`,
                block_id: '',
                wp_attachment_id: wpAttachmentId > 0 ? wpAttachmentId : null,
                seo_media_id: seoMediaId > 0 ? seoMediaId : null,
                src,
                wp_url: !isLocal ? src : '',
                local_src: isLocal ? src : '',
                slug,
                alt,
                title: alt,
                caption: '',
                align: 'none',
                origin: mode === 'gallery' ? 'gallery' : 'featured',
                origin_label: mode === 'gallery' ? t('editor_product_album') : t('editor_featured_image'),
            };
        };

        const imageIdentity = (row) => {
            const wpId = Number(row?.wp_attachment_id ?? row?.wpAttachmentId ?? 0);
            if (wpId > 0) {
                return `wp:${wpId}`;
            }

            const seoId = Number(row?.seo_media_id ?? row?.seoMediaId ?? 0);
            if (seoId > 0) {
                return `seo:${seoId}`;
            }

            return `src:${String(row?.src || '').trim()}`;
        };

        const onSelected = (event) => {
            const normalized = normalizeSelectedImage(event.detail ?? {});
            if (!normalized) {
                return;
            }

            mediaActions.setSupplementalImages((prev) => {
                const identity = imageIdentity(normalized);
                const mode = String(normalized.origin || '');
                let next = Array.isArray(prev) ? [...prev] : [];

                if (mode === 'featured') {
                    next = next.filter((row) => String(row?.origin || '') !== 'featured');
                }

                next = next.filter((row) => imageIdentity(row) !== identity);
                next.unshift(normalized);

                return next;
            });

            if (String(event.detail?.pickerTab ?? '').trim() === 'original') {
                queueMicrotask(() => {
                    publishEditorImagesCatalogRef.current?.();
                    setImagesReloadKey((key) => key + 1);
                });
            }
        };

        const onRemoved = (event) => {
            const detail = event.detail ?? {};
            const url = String(detail.url || '').trim();
            const urlKey = url ? normalizeImageSrcKey(url) : '';
            const seoId = Number(detail.seo_media_id ?? detail.seoMediaId ?? 0);
            const wpId = Number(detail.wp_attachment_id ?? detail.wpAttachmentId ?? 0);
            if (!urlKey && seoId <= 0 && wpId <= 0) {
                return;
            }

            mediaActions.setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter((row) => {
                    const rowSeo = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                    if (seoId > 0 && rowSeo > 0 && seoId === rowSeo) {
                        return false;
                    }
                    const rowWp = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
                    if (wpId > 0 && rowWp > 0 && wpId === rowWp) {
                        return false;
                    }
                    if (!urlKey) {
                        return true;
                    }
                    const candidates = [
                        row?.src,
                        row?.localSrc,
                        row?.local_src,
                        row?.wpSrc,
                        row?.wp_url,
                    ];
                    return !candidates.some(
                        (candidate) => normalizeImageSrcKey(String(candidate || '').trim()) === urlKey,
                    );
                }),
            );
            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        };

        window.addEventListener('article-media-selected', onSelected);
        window.addEventListener('article-media-removed', onRemoved);

        return () => {
            window.removeEventListener('article-media-selected', onSelected);
            window.removeEventListener('article-media-removed', onRemoved);
        };
    }, []);

    const applyEditorSectionSearch = useCallback(
        (options = {}) => {
            const { silent = false } = options;
            const needle = String(quickReplaceFind ?? '').trim();

            if (!needle) {
                setCollapsedSectionIds({});
                setEditorSearchMatchCount(null);
                return;
            }

            if (tempMergeRef.current) {
                clearTempMerge();
            }
            commitActiveBlock();

            const nextCollapsed = {};
            let totalMatches = 0;
            let sectionsWithMatches = 0;

            for (const section of editorSections) {
                const sectionCount = countKeywordInSectionBlocks(section, blockById, needle);
                totalMatches += sectionCount;
                if (sectionCount > 0) {
                    sectionsWithMatches += 1;
                } else {
                    nextCollapsed[section.id] = true;
                }
            }

            setCollapsedSectionIds(nextCollapsed);
            setEditorSearchMatchCount(totalMatches);

            if (silent) {
                return;
            }

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title:
                            totalMatches > 0
                                ? t('editor_search_found_title')
                                : t('editor_search_not_found_title'),
                        body:
                            totalMatches > 0
                                ? t('editor_search_found_body', {
                                      count: totalMatches,
                                      sections: sectionsWithMatches,
                                  })
                                : t('editor_search_not_found_body'),
                        status: totalMatches > 0 ? 'success' : 'warning',
                    },
                }),
            );
        },
        [quickReplaceFind, editorSections, blockById, clearTempMerge, commitActiveBlock],
    );

    const applyQuickReplaceAllSections = useCallback(() => {
        const needle = String(quickReplaceFind ?? '').trim();
        if (!needle) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_search_missing_title'),
                        body: t('editor_search_missing_body'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        if (tempMergeRef.current) {
            clearTempMerge();
        }
        commitActiveBlock();

        let affectedBlocks = 0;
        let totalReplacements = 0;

        setBlocks((prev) =>
            prev.map((block) => {
                if (typeof block.content !== 'string' || block.content === '') {
                    return block;
                }

                const replaced = replaceTextInHtmlContent(block.content, needle, quickReplaceValue);
                if (replaced.replacements <= 0) {
                    return block;
                }

                affectedBlocks += 1;
                totalReplacements += replaced.replacements;

                return {
                    ...block,
                    content: replaced.html,
                };
            }),
        );

        setEditorSearchMatchCount(totalReplacements > 0 ? totalReplacements : 0);

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: affectedBlocks > 0 ? t('editor_replace_success') : t('editor_replace_not_found'),
                    body:
                        affectedBlocks > 0
                            ? t('editor_replace_success_body', { totalReplacements, affectedBlocks })
                            : t('editor_replace_not_found_body'),
                    status: affectedBlocks > 0 ? 'success' : 'warning',
                },
            }),
        );
    }, [quickReplaceFind, quickReplaceValue, clearTempMerge, commitActiveBlock]);

    const handleEditorSearchAction = useCallback(() => {
        const replaceValue = String(quickReplaceValue ?? '').trim();
        if (replaceValue !== '') {
            applyQuickReplaceAllSections();
            return;
        }

        applyEditorSectionSearch();
    }, [applyEditorSectionSearch, applyQuickReplaceAllSections, quickReplaceValue]);

    const { debounced: debouncedEditorSectionSearch } = useDebouncedCallback(() => {
        if (String(quickReplaceValue ?? '').trim() !== '') {
            return;
        }
        applyEditorSectionSearch({ silent: true });
    }, 350);

    useEffect(() => {
        debouncedEditorSectionSearch();
    }, [quickReplaceFind, quickReplaceValue, debouncedEditorSectionSearch]);

    return { handleEditorSearchAction };
}
