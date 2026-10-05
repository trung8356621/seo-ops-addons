import { useEffect, useRef } from 'react';
import * as echarts from 'echarts';
import { modelMarkdownToHtml, resolveWarningClass } from './markdownPresentation.js';

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

function TableBlock({ block }) {
    const renderCell = (value) => value && typeof value === 'object' && value.label && value.href
        ? <a href={value.href} target="_blank" rel="noopener noreferrer">{value.label}</a>
        : (value ?? '');

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

function ActionsBlock({ actions, onAction, busy }) {
    if (!actions || actions.length === 0) {
        return null;
    }
    return (
        <div className="agent-actions">
            {actions.map((action, index) => action.type === 'confirmation' ? (
                <button
                    key={index}
                    type="button"
                    className={`agent-confirmation-action is-${action.action}`}
                    disabled={busy}
                    onClick={() => onAction?.(action)}
                >
                    {busy ? 'Đang xử lý…' : (action.label || action.action)}
                </button>
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

export function ResponseView({ response, onAction, actionsBusy = false }) {
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
                    return <TableBlock key={index} block={block} />;
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
