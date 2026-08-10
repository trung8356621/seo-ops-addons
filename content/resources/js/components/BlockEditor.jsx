import React from 'react';
import ImageBlockEditor from '@media-addon/components/ImageBlockEditor.jsx';
import { isFaqPlaceholderHtml } from '../utils/editorHtmlUtils';
import { blockHasOutlineHeading } from '../utils/contentDocumentHelpers';
import { Trash2 } from 'lucide-react';
import { t } from '../utils/i18n';
import FaqAccordionPreview from './FaqAccordionPreview';
import ActiveBlockEditor from './ActiveBlockEditor';
import OutlineLockedHeadingBlock from './OutlineLockedHeadingBlock';

/**
 * Block dispatcher (image / FAQ shortcode / outline-locked heading / text)
 * extracted from SeoArticleEditor.jsx (Task 7 frontend extraction).
 * Mechanical move - no behavior change.
 */
export default
function BlockEditor({
    block,
    sectionId = null,
    isActive,
    isHiddenInMerge,
    canShiftMerge,
    onActivate,
    onShiftMerge,
    displayContent,
    suppressBlockUpdate,
    onUpdate,
    onRegisterFlush,
    onRegisterEditor,
    setGlobalEditor,
    onDelete,
    canDeleteBlock,
    articleId,
    siteId,
    editable = true,
    supportsProductGallery = false,
    panelFaqs,
    faqCount = null,
    canGenerateFaq = false,
    onEditFaq,
    onCreateFaq,
    introImagesLocked = false,
    outlineHeadingsLocked = false,
    isSectionHeadingBlock = false,
    onOutlineHeadingCommand,
    onArmOutsideClickGuard,
}) {
    const blockHtml = displayContent ?? block.content;
    const isFaqShortcodeBlock = block.type === 'text' && isFaqPlaceholderHtml(blockHtml);
    const isOutlineHeadingLocked = outlineHeadingsLocked && blockHasOutlineHeading(block);

    if (block.type === 'image') {
        return (
            <ImageBlockEditor
                block={block}
                isActive={isActive}
                isHiddenInMerge={isHiddenInMerge}
                canShiftMerge={canShiftMerge}
                onActivate={onActivate}
                onShiftMerge={onShiftMerge}
                onUpdate={onUpdate}
                onDelete={onDelete}
                canDeleteBlock={canDeleteBlock}
                articleId={articleId}
                siteId={siteId}
                supportsProductGallery={supportsProductGallery}
                imagesLocked={introImagesLocked}
                onArmOutsideClickGuard={onArmOutsideClickGuard}
            />
        );
    }

    const handlePreviewClick = (e) => {
        if (e.target.closest('a')) {
            e.preventDefault();
        }
        if (e.target.closest('figure, img, .wp-block-image')) {
            e.preventDefault();
        }
        if (e.shiftKey && canShiftMerge) {
            e.preventDefault();
            e.stopPropagation();
            onShiftMerge(block.id);
            return;
        }
        onActivate();
    };

    if (isHiddenInMerge) {
        return null;
    }

    if (isFaqShortcodeBlock) {
        return (
            <div className={`seo-faq-shortcode-block${isActive ? ' is-active' : ''}`}>
                {isActive ? (
                    <div className="seo-block-toolbar seo-faq-shortcode-toolbar">
                        <span className="block-editor-badge">FAQ Shortcode</span>
                        <button
                            type="button"
                            className={`seo-toolbar-btn seo-toolbar-delete${!canDeleteBlock ? ' is-disabled' : ''}`}
                            disabled={!canDeleteBlock}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={(e) => {
                                e.stopPropagation();
                                if (canDeleteBlock) {
                                    onDelete?.();
                                }
                            }}
                            title={
                                canDeleteBlock
                                    ? t('toolbar_delete_paragraph')
                                    : t('toolbar_cannot_delete_last')
                            }
                        >
                            <Trash2 size={16} />
                        </button>
                    </div>
                ) : null}
                <div
                    className="seo-faq-shortcode-block__body"
                    onClick={() => {
                        onActivate();
                        onEditFaq?.();
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            onActivate();
                            onEditFaq?.();
                        }
                    }}
                    role="button"
                    tabIndex={0}
                    title={t('editor_faq_shortcode_hint')}
                >
                    <FaqAccordionPreview
                        faqs={panelFaqs}
                        faqCount={faqCount}
                        canGenerateFaq={canGenerateFaq}
                        onEditFaq={onEditFaq}
                        onCreateFaq={onCreateFaq}
                    />
                </div>
            </div>
        );
    }

    if (isOutlineHeadingLocked) {
        return (
            <OutlineLockedHeadingBlock
                block={block}
                isSectionHeading={isSectionHeadingBlock}
                onActivate={onActivate}
                onOutlineHeadingCommand={onOutlineHeadingCommand}
            />
        );
    }

    if (!isActive) {
        return (
            <div
                className="seo-block-preview seo-wp-content p-3 -mx-1 rounded border border-transparent hover:border-gray-200 dark:hover:border-slate-600 hover:bg-gray-50/80 dark:hover:bg-slate-800/40 transition-all cursor-text prose prose-slate max-w-none dark:prose-invert"
                dangerouslySetInnerHTML={{
                    __html: block.content || `<p class="text-gray-400 italic">${t('editor_click_to_edit')}</p>`,
                }}
                onClick={handlePreviewClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        if (e.shiftKey && canShiftMerge) {
                            e.preventDefault();
                            onShiftMerge(block.id);
                        } else {
                            onActivate();
                        }
                    }
                }}
                role="button"
                tabIndex={0}
                title={
                    canShiftMerge
                        ? t('editor_click_to_edit_shift_merge')
                        : t('editor_click_to_edit_paragraph')
                }
            />
        );
    }

    return (
        <ActiveBlockEditor
            key={`${block.id}-${suppressBlockUpdate ? 'merge' : 'edit'}`}
            block={block}
            sectionId={sectionId}
            displayContent={displayContent}
            suppressBlockUpdate={suppressBlockUpdate}
            onUpdate={onUpdate}
            onRegisterFlush={onRegisterFlush}
            onRegisterEditor={onRegisterEditor}
            setGlobalEditor={setGlobalEditor}
            onDelete={onDelete}
            canDeleteBlock={canDeleteBlock}
            articleId={articleId}
            siteId={siteId}
            editable={editable}
        />
    );
}

