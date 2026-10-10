import { useEffect, useMemo, useRef, useState } from 'react';
import * as echarts from 'echarts';
import {
    BrainCircuit,
    ChartNoAxesCombined,
    CheckCheck,
    ExternalLink,
    FileText,
    FolderKanban,
    FolderPlus,
    Globe,
    Link,
    LoaderCircle,
    Network,
    ScanSearch,
    Tags,
    TriangleAlert,
    X,
} from 'lucide-react';
import { modelMarkdownToHtml, resolveWarningClass } from './markdownPresentation.js';
import { toolTraceItems } from './toolTrace.js';
import { eligibleRows, intakeItems, isCompleteSuccess, selectAllIds, selectedCount, statusLabel, toggleId } from './actionableSelection.js';
import { articleRef, columnKind, displayColumns, formatSeoScore, issueCountLabel, rowReasons, statusTone } from './actionableTable.js';

const TRACE_ICONS = {
    ScanSearch,
    Tags,
    Network,
    FileText,
    FolderKanban,
    ChartNoAxesCombined,
    Link,
    ExternalLink,
    Globe,
    BrainCircuit,
};

export function ToolTrace({ response, debug = false }) {
    const items = toolTraceItems(response, { debug });
    if (items.length === 0) {
        return null;
    }
    return (
        <div className="agent-tool-trace" aria-label="Tool trace">
            {items.map((item) => {
                const Icon = TRACE_ICONS[item.icon];
                if (!Icon) {
                    return null;
                }
                return (
                    <span
                        key={item.key}
                        className={`agent-tool-trace__item is-${item.color}${item.status === 'failed' ? ' is-failed' : ''}`}
                        title={item.title}
                    >
                        <Icon size={15} aria-hidden="true" />
                        <span className="agent-tool-trace__label">{item.title}</span>
                    </span>
                );
            })}
        </div>
    );
}
function MarkdownBlock({ text }) {
    return <div className="agent-md" dangerouslySetInnerHTML={{ __html: modelMarkdownToHtml(text || '') }} />;
}

function ChartBlock({ block }) {
    const node = useRef(null);

    useEffect(() => {
        if (!node.current) {
            return undefined;
        }
        const chart = echarts.init(node.current);
        const xKey = block.x_key;
        chart.setOption({
            title: { text: block.title || '', left: 0, textStyle: { fontSize: 14 } },
            tooltip: { trigger: 'axis' },
            legend: { top: 28 },
            grid: { left: 48, right: 16, top: 64, bottom: 32 },
            xAxis: {
                type: 'category',
                data: (block.data || []).map((row) => row[xKey]),
            },
            yAxis: { type: 'value' },
            series: (block.series || []).map((series) => ({
                name: series.label,
                type: block.chart === 'bar' ? 'bar' : 'line',
                data: (block.data || []).map((row) => row[series.key]),
            })),
        });
        const onResize = () => chart.resize();
        window.addEventListener('resize', onResize);
        return () => {
            window.removeEventListener('resize', onResize);
            chart.dispose();
        };
    }, [block]);

    return <div ref={node} className="agent-chart" />;
}

function TableBlock({ block, draftIntakeUrl, csrf, siteId, selectionKey }) {
    const actionable = eligibleRows(block);
    const renderCell = (value) => value && typeof value === 'object' && value.label && value.href
        ? <a href={value.href} target="_blank" rel="noopener noreferrer">{value.label}</a>
        : (value ?? '');
    if (actionable.length === 0 && block.actionable?.action !== 'content_project.draft.intake') {
        return <ReadOnlyTable block={block} renderCell={renderCell} />;
    }
    return (
        <ActionableTable
            block={block}
            draftIntakeUrl={draftIntakeUrl}
            csrf={csrf}
            siteId={siteId}
            selectionKey={selectionKey}
            renderCell={renderCell}
        />
    );
}

function ReadOnlyTable({ block, renderCell }) {
    return (
        <div className="agent-table-wrap">
            {block.title ? <h3>{block.title}</h3> : null}
            <div className="agent-table-scroll">
                <table>
                    <thead>
                        <tr>
                            {(block.columns || []).map((column) => (
                                <th key={column.key} className={`is-${columnKind(column.key)}`}>{column.label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {(block.rows || []).map((row, index) => (
                            <tr key={index}>
                                {(block.columns || []).map((column) => (
                                    <td key={column.key} className={`is-${columnKind(column.key)}`}>{renderCell(row[column.key])}</td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function SeoScoreValue({ value }) {
    const score = formatSeoScore(value);
    return <span className={`agent-score ${score.tone}`}>{score.text}</span>;
}

function IssueDisclosure({ rowId, reasons, open, onToggle }) {
    const label = issueCountLabel(reasons.length);
    if (!label) {
        return null;
    }
    return (
        <span className="agent-actionable__issues">
            <button
                type="button"
                className="agent-actionable__issues-btn"
                aria-expanded={open}
                onClick={() => onToggle(rowId)}
            >
                <TriangleAlert size={14} aria-hidden="true" />
                {label}
            </button>
        </span>
    );
}

function ArticleCell({ row, outcome, issueOpen, onToggleIssue }) {
    const title = String(row.title ?? '');
    const ref = articleRef(row);
    const reasons = rowReasons(row);
    const rowId = row.item?.id || ref || String(row.n ?? title);
    return (
        <div className="agent-article-cell" data-issue-root="">
            {title ? <div className="agent-article-cell__title" title={title}>{title}</div> : null}
            {ref || reasons.length ? (
                <div className="agent-article-cell__meta">
                    {ref ? <span className="agent-article-cell__ref">{ref}</span> : null}
                    {ref && reasons.length ? <span aria-hidden="true">·</span> : null}
                    <IssueDisclosure rowId={rowId} reasons={reasons} open={issueOpen} onToggle={onToggleIssue} />
                </div>
            ) : null}
            {issueOpen && reasons.length ? (
                <ul className="agent-actionable__reasons">
                    {reasons.map((reason, index) => (
                        <li key={`${rowId}-${index}`}>{reason}</li>
                    ))}
                </ul>
            ) : null}
            {outcome ? (
                <div className={`agent-actionable__status ${statusTone(outcome.status)}`}>{statusLabel(outcome.status, outcome.message)}</div>
            ) : null}
        </div>
    );
}

function ActionableTable({ block, draftIntakeUrl, csrf, siteId, selectionKey, renderCell }) {
    const storageKey = selectionKey ? `agent-actionable:${selectionKey}` : '';
    const [selected, setSelected] = useState(() => new Set());
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [openIssueId, setOpenIssueId] = useState('');
    const rows = block.rows || [];
    const columns = useMemo(() => displayColumns(block.columns), [block.columns]);
    const eligible = useMemo(() => eligibleRows(block), [block]);
    const eligibleIds = useMemo(() => new Set(eligible.map((row) => row.item.id)), [eligible]);
    const count = selectedCount(selected, rows);
    const draftDisabled = count === 0 || busy || eligible.length === 0;

    useEffect(() => {
        if (!storageKey) return;
        try {
            const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
            if (Array.isArray(saved?.ids)) setSelected(new Set(saved.ids));
            if (saved?.result) setResult(saved.result);
        } catch {
            /* keep the empty selection */
        }
    }, [storageKey]);

    useEffect(() => {
        if (!storageKey) return;
        sessionStorage.setItem(storageKey, JSON.stringify({ ids: [...selected], result }));
    }, [storageKey, selected, result]);

    useEffect(() => {
        if (!openIssueId) return undefined;
        const close = (event) => {
            if (event.target?.closest?.('[data-issue-root]')) return;
            setOpenIssueId('');
        };
        const onKey = (event) => {
            if (event.key === 'Escape') setOpenIssueId('');
        };
        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [openIssueId]);

    function toggleIssue(rowId) {
        setOpenIssueId((current) => (current === rowId ? '' : rowId));
    }

    async function submit() {
        const items = intakeItems(rows, selected);
        if (items.length === 0 || busy || !draftIntakeUrl || !siteId) return;
        setBusy(true);
        setError('');
        try {
            const response = await fetch(draftIntakeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf || '',
                },
                body: JSON.stringify({ site_id: siteId, items }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                setError(payload.message || 'Không đưa được vào Draft.');
                return;
            }
            const data = payload.data || payload;
            setResult({ ...data, submitted_ids: items.map((item) => item.id) });
        } catch (err) {
            setError(err.message || 'Không đưa được vào Draft.');
        } finally {
            setBusy(false);
        }
    }

    const showSummary = Boolean(result || error);

    return (
        <div className="agent-table-wrap agent-actionable">
            <div className="agent-actionable__heading">
                {block.title ? <h3>{block.title}</h3> : null}
                <span>{rows.length} kết quả</span>
            </div>
            <div className="agent-actionable__toolbar">
                <div className="agent-actionable__toolbar-start">
                    <button type="button" className="agent-actionable__btn" disabled={busy || eligible.length === 0} onClick={() => setSelected(selectAllIds(rows))}>
                        <CheckCheck size={14} aria-hidden="true" />
                        Chọn tất cả
                    </button>
                    <button type="button" className="agent-actionable__btn" disabled={busy || count === 0} onClick={() => setSelected(new Set())}>
                        <X size={14} aria-hidden="true" />
                        Bỏ chọn
                    </button>
                    <span className="agent-actionable__count">Đã chọn {count}</span>
                </div>
                <button type="button" className="agent-actionable__btn is-primary" disabled={draftDisabled} onClick={submit}>
                    {busy ? <LoaderCircle size={14} className="agent-spin" aria-hidden="true" /> : <FolderPlus size={14} aria-hidden="true" />}
                    {busy ? 'Đang đưa vào Draft…' : `Đưa ${count} mục vào Draft`}
                </button>
            </div>
            <div className="agent-table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th className="is-check" />
                            {columns.map((column) => (
                                <th key={column.key} className={`is-${columnKind(column.key)}`}>{column.label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const id = row.item?.id;
                            const canSelect = Boolean(id) && eligibleIds.has(id);
                            const submittedIndex = id ? (result?.submitted_ids || []).indexOf(id) : -1;
                            const outcome = submittedIndex >= 0 ? result.items?.[submittedIndex] : null;
                            const issueId = id || articleRef(row) || String(row.n ?? '');
                            const hasTitle = columns.some((column) => column.key === 'title');
                            return (
                                <tr key={id || row.n}>
                                    <td className="is-check">
                                        {canSelect ? (
                                            <input
                                                type="checkbox"
                                                checked={selected.has(id)}
                                                disabled={busy}
                                                aria-label={row.title ? `Chọn ${row.title}` : `Chọn ${id}`}
                                                onChange={() => setSelected((current) => toggleId(current, id))}
                                            />
                                        ) : null}
                                    </td>
                                    {columns.map((column, columnIndex) => (
                                        <td key={column.key} className={`is-${columnKind(column.key)}`}>
                                            {column.key === 'title' ? (
                                                <ArticleCell
                                                    row={row}
                                                    outcome={outcome}
                                                    issueOpen={openIssueId === issueId}
                                                    onToggleIssue={toggleIssue}
                                                />
                                            ) : column.key === 'seo_score' ? (
                                                <SeoScoreValue value={row.seo_score} />
                                            ) : renderCell(row[column.key])}
                                            {!hasTitle && columnIndex === 0 && outcome ? (
                                                <div className={`agent-actionable__status ${statusTone(outcome.status)}`}>{statusLabel(outcome.status, outcome.message)}</div>
                                            ) : null}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {showSummary ? (
                <div className="agent-actionable__footer">
                    {result?.draft_url ? <a className="agent-actionable__draft-link" href={result.draft_url}>Mở Draft</a> : null}
                    {result ? (
                        <span>
                            {isCompleteSuccess(result) ? 'Đã xử lý toàn bộ mục đã chọn.' : `Thêm ${result.added || 0}, đã có ${result.already_in_draft || 0}, lỗi ${result.failed || 0}.`}
                        </span>
                    ) : null}
                    {error ? <span className="agent-actionable__error">{error}</span> : null}
                </div>
            ) : null}
        </div>
    );
}

function ActionsBlock({ actions, onAction, busy }) {
    if (!actions || actions.length === 0) {
        return null;
    }
    return (
        <div className="agent-actions">
            {actions.map((action, index) => action.type === 'confirmation' || action.type === 'gsc_continuation' ? (
                action.href ? (
                    <a key={index} className="agent-confirmation-action is-connect" href={action.href}>{action.label || action.action}</a>
                ) : (
                    <button
                        key={index}
                        type="button"
                        className={`agent-confirmation-action is-${action.action}`}
                        disabled={busy}
                        onClick={() => onAction?.(action)}
                    >
                        {busy ? 'Đang xử lý…' : (action.label || action.action)}
                    </button>
                )
            ) : (
                <div key={index} className="agent-action-item">
                    <span className="agent-action-label">{action.label || action.action}</span>
                    {action.status ? <span className="agent-action-status">({action.status})</span> : null}
                </div>
            ))}
        </div>
    );
}

function SourcesBlock({ sources }) {
    if (!sources || sources.length === 0) {
        return null;
    }
    return (
        <div className="agent-sources">
            <p className="agent-sources__title">Sources</p>
            <ul>
                {sources.map((source, index) => (
                    <li key={index} className={`agent-source-item is-${source.status || 'ok'}`}>
                        <span className="agent-source-resource">{source.name || source.resource}</span>
                        {(source.request || source.endpoint) ? (
                            <code className="agent-source-endpoint">{source.request || source.endpoint}</code>
                        ) : null}
                        {source.status === 'unavailable' && source.reason ? (
                            <span className="agent-source-reason">({source.reason})</span>
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function ResponseView({ response, onAction, actionsBusy = false, draftIntakeUrl, csrf, siteId, selectionKey, debug = false }) {
    if (!response) {
        return null;
    }

    const blocks = Array.isArray(response.blocks) ? response.blocks : [];
    const hasMessageInBlocks = blocks.some(
        (b) => b.type === 'markdown' && b.text && b.text.trim() === (response.message || '').trim()
    );

    return (
        <div className="agent-response">
            {response.message && !hasMessageInBlocks ? (
                <MarkdownBlock text={response.message} />
            ) : null}

            {blocks.map((block, index) => {
                if (block.type === 'warning') {
                    const text = String(block.text || '');
                    const warningClass = resolveWarningClass(block, response.sources);
                    return <div key={index} className={warningClass}>{text}</div>;
                }
                if (block.type === 'chart') {
                    return <ChartBlock key={index} block={block} />;
                }
                if (block.type === 'table') {
                    return (
                        <TableBlock
                            key={index}
                            block={block}
                            draftIntakeUrl={draftIntakeUrl}
                            csrf={csrf}
                            siteId={siteId}
                            selectionKey={selectionKey ? `${selectionKey}:${index}` : ''}
                        />
                    );
                }
                return <MarkdownBlock key={index} text={block.text || ''} />;
            })}

            {(!response.message && blocks.length === 0) ? (
                <p className="agent-empty">No response content.</p>
            ) : null}

            <ActionsBlock actions={response.actions} onAction={onAction} busy={actionsBusy} />
            <ToolTrace response={response} debug={debug} />
            <SourcesBlock sources={response.sources} />
        </div>
    );
}
