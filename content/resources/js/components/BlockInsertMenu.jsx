import React, { useEffect, useRef, useState } from 'react';
import { ArrowDown, ArrowUp, ChevronsDown, ChevronsUp, FileText, HelpCircle, Image as ImageIcon, Plus } from 'lucide-react';
import { openPanel } from '../editor/runtime/editorRuntimeNavigation';
import { t } from '../utils/i18n';

/**
 * @param {'before'|'after'} position
 * @param {boolean} open
 * @param {() => void} onToggle
 * @param {() => void} [onMovePrevSection]
 * @param {() => void} [onMoveNextSection]
 * @param {() => void} [onMoveUpWithinSection]
 * @param {() => void} [onMoveDownWithinSection]
 * @param {boolean} [canMovePrevSection]
 * @param {boolean} [canMoveNextSection]
 * @param {boolean} [canMoveUpWithinSection]
 * @param {boolean} [canMoveDownWithinSection]
 * @param {boolean} [showMoveButtons]
 */
export function BlockInsertBar({
    position,
    open,
    onToggle,
    onMovePrevSection,
    onMoveNextSection,
    onMoveUpWithinSection,
    onMoveDownWithinSection,
    canMovePrevSection = false,
    canMoveNextSection = false,
    canMoveUpWithinSection = false,
    canMoveDownWithinSection = false,
    showMoveButtons = true,
}) {
    return (
        <div
            className={`seo-block-insert-bar seo-block-insert-bar--${position}${showMoveButtons ? '' : ' seo-block-insert-bar--insert-only'}`}
            onMouseDown={(e) => e.stopPropagation()}
        >
            {showMoveButtons ? (
                <>
                    <button
                        type="button"
                        className="seo-block-insert-btn seo-block-move-btn seo-block-move-btn--section"
                        disabled={!canMovePrevSection}
                        onMouseDown={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            if (canMovePrevSection) {
                                onMovePrevSection?.();
                            }
                        }}
                        title={t('editor_move_block_prev_section')}
                        aria-label={t('editor_move_block_prev_section')}
                    >
                        <ChevronsUp size={16} strokeWidth={2.5} />
                    </button>
                    <button
                        type="button"
                        className="seo-block-insert-btn seo-block-move-btn seo-block-move-btn--within"
                        disabled={!canMoveUpWithinSection}
                        onMouseDown={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            if (canMoveUpWithinSection) {
                                onMoveUpWithinSection?.();
                            }
                        }}
                        title={t('editor_move_block_up_within_section')}
                        aria-label={t('editor_move_block_up_within_section')}
                    >
                        <ArrowUp size={16} strokeWidth={2.5} />
                    </button>
                </>
            ) : null}
            <button
                type="button"
                className={`seo-block-insert-btn ${open ? 'is-open' : ''}`}
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    onToggle?.();
                }}
                title={position === 'before' ? 'Insert content above' : 'Insert content below'}
                aria-expanded={open}
                aria-label={position === 'before' ? 'Insert above' : 'Insert below'}
            >
                <Plus size={16} strokeWidth={2.5} />
            </button>
            {showMoveButtons ? (
                <>
                    <button
                        type="button"
                        className="seo-block-insert-btn seo-block-move-btn seo-block-move-btn--within"
                        disabled={!canMoveDownWithinSection}
                        onMouseDown={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            if (canMoveDownWithinSection) {
                                onMoveDownWithinSection?.();
                            }
                        }}
                        title={t('editor_move_block_down_within_section')}
                        aria-label={t('editor_move_block_down_within_section')}
                    >
                        <ArrowDown size={16} strokeWidth={2.5} />
                    </button>
                    <button
                        type="button"
                        className="seo-block-insert-btn seo-block-move-btn seo-block-move-btn--section"
                        disabled={!canMoveNextSection}
                        onMouseDown={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            if (canMoveNextSection) {
                                onMoveNextSection?.();
                            }
                        }}
                        title={t('editor_move_block_next_section')}
                        aria-label={t('editor_move_block_next_section')}
                    >
                        <ChevronsDown size={16} strokeWidth={2.5} />
                    </button>
                </>
            ) : null}
        </div>
    );
}

/** @deprecated Dùng BlockInsertBar */
export function BlockInsertTrigger(props) {
    return <BlockInsertBar {...props} />;
}

/**
 * @param {() => void} onClose
 * @param {(type: 'text'|'image'|'faq') => void} onInsert
 * @param {boolean} [faqShortcodeDisabled]
 * @param {boolean} [imageInsertDisabled]
 */
export function BlockInsertMenuBar({
    onClose,
    onInsert,
    faqShortcodeDisabled = false,
    imageInsertDisabled = false,
}) {
    const ref = useRef(null);

    useEffect(() => {
        const onMouseDown = (e) => {
            if (ref.current?.contains(e.target)) return;
            onClose();
        };
        document.addEventListener('mousedown', onMouseDown);
        return () => document.removeEventListener('mousedown', onMouseDown);
    }, [onClose]);

    const handleInsert = (type) => {
        onInsert(type);
        onClose();
    };

    return (
        <div ref={ref} className="seo-block-insert-menu" onMouseDown={(e) => e.stopPropagation()}>
            <button
                type="button"
                className="seo-block-insert-menu__item"
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    handleInsert('text');
                }}
            >
                <FileText size={18} strokeWidth={1.75} />
                <span>{t('editor_add_paragraph')}</span>
            </button>
            {!imageInsertDisabled ? (
                <button
                    type="button"
                    className="seo-block-insert-menu__item"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={(e) => {
                        e.stopPropagation();
                        handleInsert('image');
                    }}
                >
                    <ImageIcon size={18} strokeWidth={1.75} />
                    <span>{t('image_block_label')}</span>
                </button>
            ) : null}
            <button
                type="button"
                className={`seo-block-insert-menu__item${faqShortcodeDisabled ? ' is-disabled' : ''}`}
                disabled={faqShortcodeDisabled}
                title={
                    faqShortcodeDisabled
                        ? 'FAQ shortcode already exists [omi_faq]'
                        : 'Insert FAQ shortcode [omi_faq]'
                }
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.stopPropagation();
                    if (!faqShortcodeDisabled) {
                        handleInsert('faq');
                    }
                }}
            >
                <HelpCircle size={18} strokeWidth={1.75} />
                <span>Shortcode FAQ</span>
            </button>
        </div>
    );
}

/**
 * Box chọn / tạo / tải nhanh ảnh cho block ảnh trống.
 *
 * @param {string} [blockId]
 * @param {() => void} onOpenMediaLibrary
 * @param {(url: string, options?: { randomFilename?: boolean }) => void|Promise<void>} [onImportFromUrl]
 * @param {boolean} [importLoading]
 */
export function ImageBlockPickerBox({
    blockId = '',
    onOpenMediaLibrary,
    onImportFromUrl,
    importLoading = false,
    interactionReady = true,
}) {
    const [mode, setMode] = useState('actions');
    const [importUrl, setImportUrl] = useState('');
    const importInputRef = useRef(null);
    const actionsDisabled = !interactionReady || importLoading;

    const stopPickerPointer = (event) => {
        event.stopPropagation();
        if (!interactionReady) {
            event.preventDefault();
        }
    };

    const openAiChat = () => {
        openPanel('ai-chat', {
            source: 'block_insert_menu',
            detail: {
                blockId: String(blockId ?? '').trim(),
                focusInput: true,
            },
        });
    };

    useEffect(() => {
        if (!interactionReady || mode !== 'import') {
            return;
        }

        importInputRef.current?.focus();
    }, [interactionReady, mode]);

    if (mode === 'import') {
        return (
            <div
                className="seo-image-block-picker"
                onMouseDown={stopPickerPointer}
                onPointerDown={stopPickerPointer}
            >
                <button type="button" className="seo-image-block-picker__back" onMouseDown={(e) => e.stopPropagation()} onClick={() => setMode('actions')}>
                    ← Back
                </button>
                <div className="seo-image-block-picker__url-row">
                    <input
                        ref={importInputRef}
                        type="url"
                        className="seo-image-block-picker__input"
                        value={importUrl}
                        onChange={(e) => setImportUrl(e.target.value)}
                        placeholder="https://example.com/image.jpg"
                        disabled={importLoading}
                        onMouseDown={(e) => {
                            e.stopPropagation();
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && importUrl.trim() && !importLoading) {
                                e.preventDefault();
                                onImportFromUrl?.(importUrl.trim(), { randomFilename: true });
                            }
                        }}
                    />
                    <button
                        type="button"
                        className="seo-image-block-picker__choice"
                        disabled={!importUrl.trim() || importLoading || !onImportFromUrl}
                        onMouseDown={(e) => e.stopPropagation()}
                        onClick={() => onImportFromUrl?.(importUrl.trim(), { randomFilename: true })}
                    >
                        {importLoading ? t('processing') : 'Import'}
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div
            className={`seo-image-block-picker seo-image-block-picker--row${actionsDisabled ? ' is-booting' : ''}`}
            onMouseDown={stopPickerPointer}
            onPointerDown={stopPickerPointer}
        >
            <button
                type="button"
                className="seo-image-block-picker__choice"
                disabled={actionsDisabled}
                onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
                onClick={(e) => {
                    if (actionsDisabled) {
                        return;
                    }

                    e.preventDefault();
                    e.stopPropagation();
                    onOpenMediaLibrary(e);
                }}
            >
                {t('image_block_label')}/{t('generate_video')}
            </button>
            <button
                type="button"
                className="seo-image-block-picker__choice is-secondary"
                disabled={actionsDisabled}
                onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
                onClick={(e) => {
                    if (actionsDisabled) {
                        return;
                    }

                    e.stopPropagation();
                    openAiChat();
                }}
            >
                {t('generate_image')}/{t('generate_video')}
            </button>
            <button
                type="button"
                className="seo-image-block-picker__choice is-secondary"
                disabled={actionsDisabled}
                onMouseDown={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                }}
                onClick={(e) => {
                    if (actionsDisabled) {
                        return;
                    }

                    e.stopPropagation();
                    setMode('import');
                }}
            >
                Quick download
            </button>
        </div>
    );
}
