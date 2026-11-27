import React, { useCallback, useMemo, useState } from 'react';
import {
    Copy,
    Loader2,
    Maximize2,
    Pencil,
    Sparkles,
    Star,
    Trash2,
} from 'lucide-react';
import { t } from '../utils/i18n';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import {
    extractSingleTableHtml,
    isPrimaryTableHtml,
    tableBlockMetrics,
} from '../utils/articleTableManager';
import { resolveFeaturedSnippetTableScore } from '@seo-addon/utils/seoContentBonus.js';
import { executeEditorCommand } from '../utils/editorCommands';
import TableEditorModal from './TableEditorModal';

function OptimizePreviewModal({ open, preview, generating, onClose, onApply }) {
    if (!open) {
        return null;
    }

    return (
        <div className="seo-table-manager-modal" role="dialog" aria-modal="true">
            <div className="seo-table-manager-modal__backdrop" onClick={onClose} />
            <div className="seo-table-manager-modal__panel">
                <div className="seo-table-manager-modal__head">
                    <h3>{t('table_manager_optimize_preview')}</h3>
                    <button type="button" onClick={onClose}>{t('close')}</button>
                </div>
                <div className="seo-table-manager-modal__body space-y-3 text-sm">
                    {generating ? (
                        <p className="inline-flex items-center gap-2">
                            <Loader2 size={14} className="animate-spin" />
                            {t('table_manager_optimizing')}
                        </p>
                    ) : null}
                    {preview?.message ? (
                        <p className={preview.success ? 'text-emerald-700' : 'text-rose-600'}>{preview.message}</p>
                    ) : null}
                    {preview?.original_score?.message ? (
                        <p className="text-xs text-gray-500">
                            {t('table_manager_score_before')}: {preview.original_score.message}
                        </p>
                    ) : null}
                    {preview?.optimized_score?.message ? (
                        <p className="text-xs text-gray-500">
                            {t('table_manager_score_after')}: {preview.optimized_score.message}
                        </p>
                    ) : null}
                    {preview?.optimized_html ? (
                        <div
                            className="prose prose-sm max-w-none overflow-auto rounded border p-2"
                            dangerouslySetInnerHTML={{ __html: preview.optimized_html }}
                        />
                    ) : null}
                </div>
                <div className="seo-table-manager-modal__actions flex justify-end gap-2">
                    <button type="button" className="rounded px-3 py-1.5 text-sm" onClick={onClose}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="rounded bg-emerald-600 px-3 py-1.5 text-sm text-white disabled:opacity-50"
                        disabled={!preview?.success || !preview?.optimized_html || generating}
                        onClick={onApply}
                    >
                        {t('table_manager_apply_optimize')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function ExpandPreviewModal({ open, html, onClose }) {
    if (!open) {
        return null;
    }

    return (
        <div className="seo-table-manager-modal" role="dialog" aria-modal="true">
            <div className="seo-table-manager-modal__backdrop" onClick={onClose} />
            <div className="seo-table-manager-modal__panel seo-table-expand-modal">
                <div className="seo-table-manager-modal__head">
                    <h3>{t('table_block_expand_preview')}</h3>
                    <button type="button" onClick={onClose}>{t('close')}</button>
                </div>
                <div
                    className="seo-table-manager-modal__body prose prose-sm max-w-none overflow-auto seo-wp-content"
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </div>
        </div>
    );
}

function notify(title, body, status = 'info') {
    window.dispatchEvent(
        new CustomEvent('seo-article-editor-notify', {
            detail: { title, body, status },
        }),
    );
}

/**
 * Independent Table Block — read-only by default; floating toolbar when active.
 */
export default function TableBlockEditor({
    block,
    isActive,
    isHiddenInMerge,
    canShiftMerge,
    onActivate,
    onShiftMerge,
    onUpdate,
    onDelete,
    canDeleteBlock,
    onArmOutsideClickGuard = null,
    onSetPrimary = null,
    canOptimize = false,
    featuredSnippetThresholds = null,
    onMarkSeoStale = null,
}) {
    const [editing, setEditing] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const [optimizeOpen, setOptimizeOpen] = useState(false);
    const [optimizePreview, setOptimizePreview] = useState(null);
    const [optimizing, setOptimizing] = useState(false);

    const tableHtml = useMemo(
        () => extractSingleTableHtml(block.content) || String(block.content ?? ''),
        [block.content],
    );
    const metrics = useMemo(() => tableBlockMetrics(tableHtml), [tableHtml]);
    const isPrimary = metrics.isPrimary || isPrimaryTableHtml(tableHtml);

    const seoScore = useMemo(() => {
        if (!featuredSnippetThresholds) {
            return null;
        }
        return resolveFeaturedSnippetTableScore(tableHtml, featuredSnippetThresholds);
    }, [featuredSnippetThresholds, tableHtml]);

    const handlePreviewClick = (e) => {
        if (e.target.closest('a')) {
            e.preventDefault();
        }
        if (e.shiftKey && canShiftMerge) {
            e.preventDefault();
            e.stopPropagation();
            onShiftMerge(block.id);
            return;
        }
        onArmOutsideClickGuard?.(360);
        onActivate();
    };

    const handleDuplicate = useCallback(() => {
        const result = executeEditorCommand('duplicate_block', { blockId: block.id });
        if (result && result.ok === false) {
            notify(t('table_block_label'), String(result.message || result.code || 'duplicate_failed'), 'warning');
        }
    }, [block.id]);

    const handleTogglePrimary = useCallback(() => {
        onSetPrimary?.(block.id, !isPrimary);
        onMarkSeoStale?.();
        notify(
            t('table_block_label'),
            isPrimary ? t('table_block_primary_unset') : t('table_manager_primary_set'),
            'success',
        );
    }, [block.id, isPrimary, onMarkSeoStale, onSetPrimary]);

    const handleOptimize = useCallback(async () => {
        if (!canOptimize) {
            notify(t('table_block_label'), t('table_manager_optimize_no_prompt'), 'warning');
            return;
        }
        setOptimizeOpen(true);
        setOptimizePreview(null);
        setOptimizing(true);
        try {
            const result = await callEditArticleLivewire(
                'optimizeFeaturedSnippetTableFromEditor',
                tableHtml,
            );
            const payload = result && typeof result === 'object' ? result : {};
            setOptimizePreview(payload);
            if (!payload.success) {
                notify(t('table_block_label'), payload.message || t('table_manager_optimize_failed'), 'danger');
            }
        } catch (error) {
            setOptimizePreview({
                success: false,
                message: error?.message || t('table_manager_optimize_failed'),
            });
            notify(t('table_block_label'), error?.message || t('table_manager_optimize_failed'), 'danger');
        } finally {
            setOptimizing(false);
        }
    }, [canOptimize, tableHtml]);

    const handleApplyOptimize = useCallback(() => {
        if (!optimizePreview?.optimized_html) {
            return;
        }
        const next = extractSingleTableHtml(optimizePreview.optimized_html);
        if (!next) {
            return;
        }
        onUpdate(next);
        setOptimizeOpen(false);
        setOptimizePreview(null);
        onMarkSeoStale?.();
        notify(t('table_block_label'), t('table_manager_optimize_applied'), 'success');
    }, [onMarkSeoStale, onUpdate, optimizePreview]);

    if (isHiddenInMerge) {
        return null;
    }

    const statusBadges = (
        <div className="seo-table-block-status">
            {isPrimary ? (
                <span className="seo-table-block-badge seo-table-block-badge--primary">
                    <Star size={12} /> {t('table_manager_primary_badge')}
                </span>
            ) : null}
            {seoScore?.message ? (
                <span
                    className={`seo-table-block-badge seo-table-block-badge--score${seoScore.passed ? ' is-pass' : ''}`}
                    title={seoScore.message}
                >
                    {seoScore.tier || 'none'} · {metrics.data_rows}×{metrics.columns}
                </span>
            ) : (
                <span className="seo-table-block-badge">
                    {metrics.data_rows}×{metrics.columns}
                </span>
            )}
        </div>
    );

    if (!isActive) {
        return (
            <div
                className="seo-block-preview seo-wp-content tiptap-editor-content seo-block-table-preview-wrap p-3 -mx-1 rounded border border-transparent hover:border-gray-200 dark:hover:border-slate-600 hover:bg-gray-50/80 dark:hover:bg-slate-800/40 transition-all cursor-pointer"
                onClick={handlePreviewClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        if (e.shiftKey && canShiftMerge) {
                            onShiftMerge(block.id);
                        } else {
                            onActivate();
                        }
                    }
                }}
                role="button"
                tabIndex={0}
                title={t('table_block_click_to_select')}
            >
                {statusBadges}
                <div dangerouslySetInnerHTML={{ __html: tableHtml }} />
            </div>
        );
    }

    return (
        <div
            className="block-table-active"
            onMouseDown={(e) => {
                if (!(e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement)) {
                    e.stopPropagation();
                }
            }}
        >
            <span className="block-editor-badge">{t('table_block_label')}</span>
            <button
                type="button"
                className="block-image-delete"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => canDeleteBlock && onDelete?.()}
                disabled={!canDeleteBlock}
                title={canDeleteBlock ? t('table_block_delete') : t('cannot_delete_last_block')}
            >
                <Trash2 size={16} />
            </button>

            <div className="seo-block-table-stage seo-wp-content tiptap-editor-content">
                <div className="seo-block-table-edit-wrap">
                    <div className="seo-image-toolbar seo-image-toolbar--inline seo-table-toolbar">
                        <button
                            type="button"
                            className="seo-image-toolbar-btn"
                            title={t('table_manager_edit')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => setEditing(true)}
                        >
                            <Pencil size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className={`seo-image-toolbar-btn${isPrimary ? ' is-active' : ''}`}
                            title={isPrimary ? t('table_block_unset_primary') : t('table_manager_make_primary')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={handleTogglePrimary}
                        >
                            <Star size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn"
                            title={t('table_manager_optimize')}
                            disabled={!canOptimize || optimizing}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => void handleOptimize()}
                        >
                            {optimizing ? <Loader2 size={18} className="animate-spin" /> : <Sparkles size={18} strokeWidth={1.75} />}
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn"
                            title={t('table_block_expand_preview')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => setExpanded(true)}
                        >
                            <Maximize2 size={18} strokeWidth={1.75} />
                        </button>
                        <span className="seo-image-toolbar-sep" />
                        <button
                            type="button"
                            className="seo-image-toolbar-btn"
                            title={t('table_block_duplicate')}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={handleDuplicate}
                        >
                            <Copy size={18} strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            className="seo-image-toolbar-btn is-danger"
                            title={t('table_block_delete')}
                            disabled={!canDeleteBlock}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => canDeleteBlock && onDelete?.()}
                        >
                            <Trash2 size={18} strokeWidth={1.75} />
                        </button>
                    </div>
                    {statusBadges}
                    <div
                        className="seo-block-table-figure-host"
                        dangerouslySetInnerHTML={{ __html: tableHtml }}
                    />
                </div>
            </div>

            <TableEditorModal
                open={editing}
                html={tableHtml}
                onClose={() => setEditing(false)}
                onSave={(nextHtml) => {
                    onUpdate(nextHtml);
                    setEditing(false);
                    onMarkSeoStale?.();
                    notify(t('table_block_label'), t('table_manager_edit_saved'), 'success');
                }}
            />
            <OptimizePreviewModal
                open={optimizeOpen}
                preview={optimizePreview}
                generating={optimizing}
                onClose={() => {
                    setOptimizeOpen(false);
                    setOptimizePreview(null);
                }}
                onApply={handleApplyOptimize}
            />
            <ExpandPreviewModal
                open={expanded}
                html={tableHtml}
                onClose={() => setExpanded(false)}
            />
        </div>
    );
}
