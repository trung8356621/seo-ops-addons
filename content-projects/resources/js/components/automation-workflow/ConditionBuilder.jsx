import React from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import { CONDITION_OPERATORS, VALUELESS_OPERATORS } from './constants';

function normalizeConditions(config) {
    const raw = config?.conditions;
    if (!raw || typeof raw !== 'object') {
        return { mode: 'all', clauses: [{ field: '', operator: 'equals', value: '' }] };
    }
    if (Array.isArray(raw.all)) {
        return { mode: 'all', clauses: raw.all.length ? raw.all : [{ field: '', operator: 'equals', value: '' }] };
    }
    if (Array.isArray(raw.any)) {
        return { mode: 'any', clauses: raw.any.length ? raw.any : [{ field: '', operator: 'equals', value: '' }] };
    }
    return { mode: 'all', clauses: [raw] };
}

export default function ConditionBuilder({ config, onChange, disabled }) {
    const { mode, clauses } = normalizeConditions(config);

    const emit = (nextMode, nextClauses) => {
        const cleaned = nextClauses.map((c) => ({
            field: c.field ?? '',
            operator: c.operator ?? 'equals',
            value: c.value ?? '',
        }));
        onChange({
            ...(config ?? {}),
            conditions: { [nextMode]: cleaned },
        });
    };

    const updateClause = (index, patch) => {
        const next = clauses.map((clause, i) => (i === index ? { ...clause, ...patch } : clause));
        emit(mode, next);
    };

    const addClause = () => emit(mode, [...clauses, { field: '', operator: 'equals', value: '' }]);

    const removeClause = (index) => {
        const next = clauses.filter((_, i) => i !== index);
        emit(mode, next.length ? next : [{ field: '', operator: 'equals', value: '' }]);
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Match</span>
                <SeoSelect
                    value={mode}
                    onChange={(e) => emit(e.target.value, clauses)}
                    disabled={disabled}
                    className="min-w-[100px]"
                >
                    <option value="all">ALL</option>
                    <option value="any">ANY</option>
                </SeoSelect>
                <span className="text-xs text-slate-500">clauses</span>
            </div>

            {clauses.map((clause, index) => (
                <div key={index} className="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/40">
                    <div className="grid gap-2">
                        <input
                            type="text"
                            className="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-800"
                            placeholder="field (e.g. subject.post_type)"
                            value={clause.field ?? ''}
                            disabled={disabled}
                            onChange={(e) => updateClause(index, { field: e.target.value })}
                        />
                        <SeoSelect
                            value={clause.operator ?? 'equals'}
                            onChange={(e) => updateClause(index, { operator: e.target.value })}
                            disabled={disabled}
                        >
                            {CONDITION_OPERATORS.map((op) => (
                                <option key={op.value} value={op.value}>{op.label}</option>
                            ))}
                        </SeoSelect>
                        {!VALUELESS_OPERATORS.has(clause.operator ?? '') && (
                            <input
                                type="text"
                                className="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-800"
                                placeholder="value"
                                value={clause.value ?? ''}
                                disabled={disabled}
                                onChange={(e) => updateClause(index, { value: e.target.value })}
                            />
                        )}
                    </div>
                    {!disabled && clauses.length > 1 && (
                        <button
                            type="button"
                            className="mt-2 text-xs font-medium text-rose-600 hover:text-rose-700"
                            onClick={() => removeClause(index)}
                        >
                            Remove clause
                        </button>
                    )}
                </div>
            ))}

            {!disabled && (
                <button
                    type="button"
                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                    onClick={addClause}
                >
                    + Add clause
                </button>
            )}
        </div>
    );
}
