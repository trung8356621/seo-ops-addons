import { useEffect, useMemo, useRef, useState } from 'react';
import * as echarts from 'echarts';
import {
    BrainCircuit,
    ChartNoAxesCombined,
    ExternalLink,
    FileText,
    FolderKanban,
    Globe,
    Link,
    Network,
    ScanSearch,
    Tags,
} from 'lucide-react';
import { modelMarkdownToHtml, resolveWarningClass } from './markdownPresentation.js';
import { toolTraceItems } from './toolTrace.js';

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
import { eligibleRows, intakeItems, isCompleteSuccess, selectAllIds, selectedCount, statusLabel, toggleId } from './actionableSelection.js';

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
            <table>
                <thead>
                    <tr>
                        {(block.columns || []).map((column) => (
                            <th key={column.key}>{column.label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {(block.rows || []).map((row, index) => (
                        <tr key={index}>
                            {(block.columns || []).map((column) => (
                                <td key={column.key}>{renderCell(row[column.key])}</td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ActionableTable({ block, draftIntakeUrl, csrf, siteId, selectionKey, renderCell }) {
    const storageKey = selectionKey ? `agent-actionable:${selectionKey}` : '';
    const [selected, setSelected] = useState(() => new Set());
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const rows = block.rows || [];
    const eligible = useMemo(() => eligibleRows(block), [block]);
    const count = selectedCount(selected, rows);

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

    return (
        <div className="agent-table-wrap agent-actionable">
            <div className="agent-actionable__bar">
                <button type="button" onClick={() => setSelected(selectAllIds(rows))}>Chọn tất cả</button>
                <button type="button" onClick={() => setSelected(new Set())}>Bỏ chọn</button>
                <span>Đã chọn {count}</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th />
                        {(block.columns || []).map((column) => (
                            <th key={column.key}>{column.label}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => {
                        const id = row.item?.id;
                        const submittedIndex = id ? (result?.submitted_ids || []).indexOf(id) : -1;
                        const outcome = submittedIndex >= 0 ? result.items?.[submittedIndex] : null;
                        return (
                            <tr key={id || row.n}>
                                <td>
                                    {id ? (
                                        <input
                                            type="checkbox"
                                            checked={selected.has(id)}
                                            disabled={busy}
                                            onChange={() => setSelected((current) => toggleId(current, id))}
                                        />
                                    ) : null}
                                </td>
                                {(block.columns || []).map((column) => (
                                    <td key={column.key}>
                                        {renderCell(row[column.key])}
                                        {column.key === 'title' && row.item?.reasons?.length ? (
                                            <div className="agent-actionable__reasons">{row.item.reasons.join(' · ')}</div>
                                        ) : null}
                                        {column.key === 'title' && outcome ? (
                                            <div className="agent-actionable__status">{statusLabel(outcome.status, outcome.message)}</div>
                                        ) : null}
                                    </td>
                                ))}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <div className="agent-actionable__footer">
                <button type="button" disabled={count === 0 || busy || eligible.length === 0} onClick={submit}>
                    {busy ? 'Đang đưa vào Draft…' : `Đưa ${count} mục vào Draft`}
                </button>
                {result?.draft_url ? <a href={result.draft_url}>Mở Draft</a> : null}
                {result ? (
                    <span>
                        {isCompleteSuccess(result) ? 'Đã xử lý toàn bộ mục đã chọn.' : `Thêm ${result.added || 0}, đã có ${result.already_in_draft || 0}, lỗi ${result.failed || 0}.`}
                    </span>
                ) : null}
                {error ? <span className="agent-actionable__error">{error}</span> : null}
            </div>
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
            <ToolTrace response={response} debug={debug} />
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
            <SourcesBlock sources={response.sources} />
        </div>
    );
}
