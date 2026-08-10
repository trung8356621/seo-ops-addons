import React from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import ConditionBuilder from './ConditionBuilder';
import { NODE_META } from './constants';

function JsonField({ label, value, onChange, disabled }) {
    const text = typeof value === 'string' ? value : JSON.stringify(value ?? {}, null, 2);

    return (
        <label className="block space-y-1">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</span>
            <textarea
                rows={4}
                className="w-full rounded-md border border-slate-300 px-2 py-1.5 font-mono text-xs dark:border-slate-600 dark:bg-slate-800"
                value={text}
                disabled={disabled}
                onChange={(e) => {
                    try {
                        onChange(JSON.parse(e.target.value || '{}'));
                    } catch {
                        onChange(e.target.value);
                    }
                }}
            />
        </label>
    );
}

export default function ConfigPanel({
    node,
    registry,
    permissions,
    onUpdate,
    onDelete,
    onDuplicate,
}) {
    if (!node) {
        return (
            <div className="flex h-full items-center justify-center p-6 text-sm text-slate-500">
                Select a node to configure
            </div>
        );
    }

    const canEdit = permissions?.edit ?? false;
    const meta = NODE_META[node.node_type] ?? {};

    const patch = (updates) => onUpdate({ ...node, ...updates });

    const patchConfig = (config) => patch({ config: { ...(node.config ?? {}), ...config } });
    const patchSettings = (settings) => patch({ settings: { ...(node.settings ?? {}), ...settings } });

    return (
        <div className="flex h-full flex-col overflow-hidden border-l border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{meta.label ?? node.node_type}</p>
                <input
                    type="text"
                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm font-semibold dark:border-slate-600 dark:bg-slate-800"
                    value={node.name ?? ''}
                    disabled={!canEdit}
                    onChange={(e) => patch({ name: e.target.value })}
                />
            </div>

            <div className="flex-1 space-y-4 overflow-y-auto p-4 text-sm">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={node.is_enabled !== false}
                        disabled={!canEdit}
                        onChange={(e) => patch({ is_enabled: e.target.checked })}
                    />
                    <span>Enabled</span>
                </label>

                {node.node_type === 'action' && (
                    <>
                        <label className="block space-y-1">
                            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Action</span>
                            <SeoSelect
                                value={node.action_code ?? ''}
                                disabled={!canEdit}
                                onChange={(e) => patch({ action_code: e.target.value })}
                            >
                                <option value="">Select action</option>
                                {(registry?.actions ?? []).map((action) => (
                                    <option key={action.code} value={action.code}>{action.code}</option>
                                ))}
                            </SeoSelect>
                        </label>
                        <JsonField
                            label="Input mapping"
                            value={node.input_mapping ?? {}}
                            disabled={!canEdit}
                            onChange={(value) => patch({ input_mapping: typeof value === 'string' ? node.input_mapping : value })}
                        />
                        <JsonField
                            label="Settings"
                            value={node.settings ?? {}}
                            disabled={!canEdit}
                            onChange={(value) => patch({ settings: typeof value === 'string' ? node.settings : value })}
                        />
                        <JsonField
                            label="Config (retry)"
                            value={node.config ?? {}}
                            disabled={!canEdit}
                            onChange={(value) => patch({ config: typeof value === 'string' ? node.config : value })}
                        />
                    </>
                )}

                {node.node_type === 'condition' && (
                    <ConditionBuilder
                        config={node.config ?? {}}
                        disabled={!canEdit}
                        onChange={(config) => patch({ config })}
                    />
                )}

                {node.node_type === 'delay' && (
                    <label className="block space-y-1">
                        <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Seconds</span>
                        <input
                            type="number"
                            min={1}
                            className="w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-800"
                            value={node.config?.seconds ?? 10}
                            disabled={!canEdit}
                            onChange={(e) => patchConfig({ seconds: Math.max(1, parseInt(e.target.value, 10) || 1) })}
                        />
                    </label>
                )}

                {node.node_type === 'dispatch_event' && (
                    <label className="block space-y-1">
                        <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Event name</span>
                        <SeoSelect
                            value={node.settings?.event_name ?? ''}
                            disabled={!canEdit}
                            onChange={(e) => patchSettings({ event_name: e.target.value })}
                        >
                            <option value="">Select event</option>
                            {(registry?.events ?? []).map((event) => (
                                <option key={event.name} value={event.name}>{event.name}</option>
                            ))}
                        </SeoSelect>
                    </label>
                )}

                {node.node_type === 'trigger' && (
                    <p className="text-xs text-slate-500">
                        Trigger inherits rule event/settings. Edit rule metadata in Filament form.
                    </p>
                )}
            </div>

            {canEdit && (
                <div className="flex gap-2 border-t border-slate-200 p-4 dark:border-slate-700">
                    <button
                        type="button"
                        className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800"
                        onClick={() => onDuplicate(node.node_key)}
                    >
                        Duplicate
                    </button>
                    {node.node_type !== 'trigger' && (
                        <button
                            type="button"
                            className="flex-1 rounded-md bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700"
                            onClick={() => onDelete(node.node_key)}
                        >
                            Delete
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
