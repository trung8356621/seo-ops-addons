export const NODE_WIDTH = 220;
export const NODE_HEIGHT = 88;

export const NODE_META = {
    trigger: { label: 'Trigger', color: '#6366f1', branches: ['always'] },
    action: { label: 'Action', color: '#0ea5e9', branches: ['success', 'failure'] },
    condition: { label: 'Condition', color: '#f59e0b', branches: ['true', 'false'] },
    delay: { label: 'Delay', color: '#8b5cf6', branches: ['always'] },
    dispatch_event: { label: 'Dispatch Event', color: '#10b981', branches: ['always'] },
    end: { label: 'End', color: '#64748b', branches: [] },
};

export const BRANCH_LABELS = {
    always: 'Always',
    success: 'Success',
    failure: 'Failure',
    true: 'True',
    false: 'False',
};

export const CONDITION_OPERATORS = [
    { value: 'equals', label: 'Equals' },
    { value: 'not_equals', label: 'Not equals' },
    { value: 'in', label: 'In' },
    { value: 'not_in', label: 'Not in' },
    { value: 'exists', label: 'Exists' },
    { value: 'not_exists', label: 'Not exists' },
    { value: 'contains', label: 'Contains' },
    { value: 'greater_than', label: 'Greater than' },
    { value: 'less_than', label: 'Less than' },
    { value: 'is_true', label: 'Is true' },
    { value: 'is_false', label: 'Is false' },
];

export const VALUELESS_OPERATORS = new Set(['exists', 'not_exists', 'is_true', 'is_false']);

export function allowedBranches(nodeType) {
    return NODE_META[nodeType]?.branches ?? [];
}

export function createNodeKey(type) {
    return `${type}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
}

export function defaultNode(type, registry, rule) {
    const key = createNodeKey(type);
    const base = {
        node_key: key,
        node_type: type,
        name: NODE_META[type]?.label ?? type,
        is_enabled: true,
        ui_position: { x: 120 + Math.random() * 80, y: 120 + Math.random() * 80 },
    };

    switch (type) {
        case 'trigger':
            return {
                ...base,
                name: rule?.event_name ?? 'Trigger',
                settings: { trigger_type: rule?.trigger_type ?? 'event' },
            };
        case 'action': {
            const firstAction = registry?.actions?.[0];
            return {
                ...base,
                action_code: firstAction?.code ?? '',
                input_mapping: {},
                settings: {},
                config: { retry: { max_attempts: 1, backoff_seconds: [] } },
            };
        }
        case 'condition':
            return {
                ...base,
                config: { conditions: { all: [{ field: 'payload.', operator: 'exists', value: '' }] } },
            };
        case 'delay':
            return { ...base, config: { seconds: 10 } };
        case 'dispatch_event': {
            const firstEvent = registry?.events?.[0];
            return { ...base, settings: { event_name: firstEvent?.name ?? '' } };
        }
        case 'end':
            return { ...base, name: 'End' };
        default:
            return base;
    }
}

export function cloneGraphState(nodes, edges) {
    return {
        nodes: JSON.parse(JSON.stringify(nodes)),
        edges: JSON.parse(JSON.stringify(edges)),
    };
}

export function graphToPayload(nodes, edges) {
    return {
        nodes: nodes.map((node, index) => ({
            node_key: node.node_key,
            node_type: node.node_type,
            name: node.name ?? null,
            action_code: node.action_code ?? null,
            position: index,
            config: node.config ?? null,
            input_mapping: node.input_mapping ?? null,
            settings: node.settings ?? null,
            ui_position: node.ui_position ?? null,
            is_enabled: node.is_enabled !== false,
        })),
        edges: edges.map((edge) => ({
            from_node_key: edge.from_node_key,
            to_node_key: edge.to_node_key,
            branch: edge.branch ?? 'always',
            priority: edge.priority ?? 100,
            condition: edge.condition ?? null,
        })),
    };
}
