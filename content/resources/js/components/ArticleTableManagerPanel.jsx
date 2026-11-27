import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Loader2, Sparkles, Star, Table2, Pencil } from 'lucide-react';
import { t } from '../utils/i18n';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import {
    gridToTableHtml,
    listArticleTablesFromBlocks,
    replaceTableInBlocks,
    setPrimaryTableInBlocks,
    tableHtmlToGrid,
} from '../utils/articleTableManager';

function TableEditModal({ open, table, onClose, onSave }) {
    const [grid, setGrid] = useState(() => tableHtmlToGrid(table?.html ?? ''));

    useEffect(() => {
        if (open) {
            setGrid(tableHtmlToGrid(table?.html ?? ''));
        }
    }, [open, table?.html, table?.index]);

    if (!open || !table) {
        return null;
    }

    const cols = Math.max(
        grid.headers?.length || 0,
        ...(grid.rows || []).map((r) => (Array.isArray(r) ? r.length : 0)),
        1,
    );

    const updateCell = (rowIndex, colIndex, value) => {
        setGrid((prev) => {
            if (rowIndex === -1) {
                const headers = [...(prev.headers || [])];
                while (headers.length < cols) headers.push('');
                headers[colIndex] = value;
                return { ...prev, headers };
            }
            const rows = (prev.rows || []).map((row, i) => {
                if (i !== rowIndex) return row;
                const next = [...(Array.isArray(row) ? row : [])];
                while (next.length < cols) next.push('');
                next[colIndex] = value;
                return next;
            });
            return { ...prev, rows };
        });
    };

    const addRow = () => {
        setGrid((prev) => ({
            ...prev,
            rows: [...(prev.rows || []), Array.from({ length: cols }, () => '')],
        }));
    };

    const addColumn = () => {
        setGrid((prev) => ({
            headers: [...(prev.headers || []), `Col ${(prev.headers || []).length + 1}`],
            rows: (prev.rows || []).map((row) => [...(Array.isArray(row) ? row : []), '']),
            hasHeader: prev.hasHeader,
        }));
    };

    return (
        <div className="seo-table-manager-modal" role="dialog" aria-modal="true">
            <div className="seo-table-manager-modal__backdrop" onClick={onClose} />
            <div className="seo-table-manager-modal__panel">
                <div className="seo-table-manager-modal__head">
                    <h3>{t('table_manager_edit_title')}</h3>
                    <button type="button" onClick={onClose}>{t('close')}</button>
                </div>
                <div className="seo-table-manager-modal__body overflow-auto">
                    <table className="seo-table-manager-grid w-full text-sm">
                        <thead>
                            <tr>
                                {Array.from({ length: cols }).map((_, colIndex) => (
                                    <th key={`h-${colIndex}`}>
                                        <input
                                            className="w-full rounded border px-1 py-0.5"
                                            value={grid.headers?.[colIndex] ?? ''}
                                            onChange={(e) => updateCell(-1, colIndex, e.target.value)}
                                        />
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {(grid.rows || []).map((row, rowIndex) => (
                                <tr key={`r-${rowIndex}`}>
                                    {Array.from({ length: cols }).map((_, colIndex) => (
                                        <td key={`c-${rowIndex}-${colIndex}`}>
                                            <input
                                                className="w-full rounded border px-1 py-0.5"
                                                value={row?.[colIndex] ?? ''}
                                                onChange={(e) => updateCell(rowIndex, colIndex, e.target.value)}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-2 flex gap-2">
                        <button type="button" className="rounded border px-2 py-1 text-xs" onClick={addRow}>
                            {t('table_manager_add_row')}
                        </button>
                        <button type="button" className="rounded border px-2 py-1 text-xs" onClick={addColumn}>
                            {t('table_manager_add_column')}
                        </button>
                    </div>
                </div>
                <div className="seo-table-manager-modal__actions flex justify-end gap-2">
                    <button type="button" className="rounded px-3 py-1.5 text-sm" onClick={onClose}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="rounded bg-primary-600 px-3 py-1.5 text-sm text-white"
                        onClick={() => onSave(gridToTableHtml(grid, { isPrimary: table.isPrimary }))}
                    >
                        {t('table_manager_save_edit')}
                    </button>
                </div>
            </div>
        </div>
    );
}

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

/**
 * Table Manager — list / edit / primary / AI optimize.
 */
export default function ArticleTableManagerPanel({
    blocks = [],
    canOptimize = false,
    syncRequired = false,
    onReplaceBlocks,
    onMarkSeoStale,
}) {
    const tables = useMemo(() => listArticleTablesFromBlocks(blocks), [blocks]);
    const [editTable, setEditTable] = useState(null);
    const [optimizeIndex, setOptimizeIndex] = useState(null);
    const [optimizePreview, setOptimizePreview] = useState(null);
    const [optimizing, setOptimizing] = useState(false);

    const notify = useCallback((title, body, status = 'info') => {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: { title, body, status },
            }),
        );
    }, []);

    const handleMakePrimary = useCallback((index) => {
        if (typeof onReplaceBlocks !== 'function') {
            return;
        }
        onReplaceBlocks((prev) => setPrimaryTableInBlocks(prev, index));
        onMarkSeoStale?.();
        notify(t('table_manager_title'), t('table_manager_primary_set'), 'success');
    }, [notify, onMarkSeoStale, onReplaceBlocks]);

    const handleSaveEdit = useCallback((html) => {
        if (editTable == null || typeof onReplaceBlocks !== 'function') {
            return;
        }
        onReplaceBlocks((prev) => replaceTableInBlocks(prev, editTable.index, html));
        setEditTable(null);
        onMarkSeoStale?.();
        notify(t('table_manager_title'), t('table_manager_edit_saved'), 'success');
    }, [editTable, notify, onMarkSeoStale, onReplaceBlocks]);

    const handleOptimize = useCallback(async (table) => {
        if (!canOptimize || syncRequired) {
            notify(t('table_manager_title'), t('table_manager_optimize_no_prompt'), 'warning');
            return;
        }
        setOptimizeIndex(table.index);
        setOptimizePreview(null);
        setOptimizing(true);
        try {
            const result = await callEditArticleLivewire(
                'optimizeFeaturedSnippetTableFromEditor',
                table.html,
            );
            const payload = result && typeof result === 'object' ? result : {};
            setOptimizePreview(payload);
            if (!payload.success) {
                notify(t('table_manager_title'), payload.message || t('table_manager_optimize_failed'), 'danger');
            }
        } catch (error) {
            setOptimizePreview({
                success: false,
                message: error?.message || t('table_manager_optimize_failed'),
            });
            notify(t('table_manager_title'), error?.message || t('table_manager_optimize_failed'), 'danger');
        } finally {
            setOptimizing(false);
        }
    }, [canOptimize, notify, syncRequired]);

    const handleApplyOptimize = useCallback(() => {
        if (optimizeIndex == null || !optimizePreview?.optimized_html || typeof onReplaceBlocks !== 'function') {
            return;
        }
        onReplaceBlocks((prev) => replaceTableInBlocks(prev, optimizeIndex, optimizePreview.optimized_html));
        setOptimizeIndex(null);
        setOptimizePreview(null);
        onMarkSeoStale?.();
        notify(t('table_manager_title'), t('table_manager_optimize_applied'), 'success');
    }, [notify, onMarkSeoStale, onReplaceBlocks, optimizeIndex, optimizePreview]);

    if (syncRequired) {
        return null;
    }

    return (
        <div className="seo-table-manager mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
            <div className="mb-2 flex items-center gap-1.5 text-sm font-semibold">
                <Table2 size={14} />
                {t('table_manager_title')}
            </div>
            {tables.length === 0 ? (
                <p className="text-xs text-gray-500">{t('table_manager_empty')}</p>
            ) : (
                <ul className="space-y-2">
                    {tables.map((table) => (
                        <li
                            key={`tbl-${table.index}-${table.blockId}`}
                            className="rounded border border-gray-200 p-2 text-xs dark:border-white/15"
                        >
                            <div className="mb-1 flex items-start justify-between gap-2">
                                <div>
                                    <div className="font-medium">
                                        {t('table_manager_table_n', { n: table.index + 1 })}
                                        {table.isPrimary ? (
                                            <span className="ml-1 inline-flex items-center gap-0.5 text-amber-600">
                                                <Star size={12} /> {t('table_manager_primary_badge')}
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="text-gray-500">
                                        {table.data_rows}×{table.columns} — {table.preview}
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1">
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded border px-2 py-1"
                                    onClick={() => setEditTable(table)}
                                >
                                    <Pencil size={12} /> {t('table_manager_edit')}
                                </button>
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded border px-2 py-1 disabled:opacity-50"
                                    disabled={table.isPrimary}
                                    onClick={() => handleMakePrimary(table.index)}
                                >
                                    <Star size={12} /> {t('table_manager_make_primary')}
                                </button>
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded border px-2 py-1 disabled:opacity-50"
                                    disabled={!canOptimize || optimizing}
                                    onClick={() => void handleOptimize(table)}
                                >
                                    <Sparkles size={12} /> {t('table_manager_optimize')}
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <TableEditModal
                open={Boolean(editTable)}
                table={editTable}
                onClose={() => setEditTable(null)}
                onSave={handleSaveEdit}
            />
            <OptimizePreviewModal
                open={optimizeIndex != null}
                preview={optimizePreview}
                generating={optimizing}
                onClose={() => {
                    setOptimizeIndex(null);
                    setOptimizePreview(null);
                }}
                onApply={handleApplyOptimize}
            />
        </div>
    );
}
