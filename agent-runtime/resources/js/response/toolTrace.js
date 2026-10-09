const TRACE_ORDER = [
    'seo_audit.worst_articles',
    'keywords.landscape',
    'keywords.relationship',
    'articles.inventory',
    'content_projects.read',
    'gsc.performance',
    'links.internal',
    'links.external',
    'site.knowledge',
];

const TRACE_META = {
    'seo_audit.worst_articles': { icon: 'ScanSearch', color: 'red', label: 'SEO Audit – Worst Articles' },
    'keywords.landscape': { icon: 'Tags', color: 'blue', label: 'Keyword Landscape' },
    'keywords.relationship': { icon: 'Network', color: 'purple', label: 'Keyword Relationship' },
    'articles.inventory': { icon: 'FileText', color: 'cyan', label: 'Articles Inventory' },
    'content_projects.read': { icon: 'FolderKanban', color: 'amber', label: 'Content Projects' },
    'gsc.performance': { icon: 'ChartNoAxesCombined', color: 'green', label: 'GSC Performance' },
    'links.internal': { icon: 'Link', color: 'orange', label: 'Internal Links' },
    'links.external': { icon: 'ExternalLink', color: 'teal', label: 'External Links' },
    'site.knowledge': { icon: 'Globe', color: 'indigo', label: 'Site Knowledge' },
    external_answer_model: { icon: 'BrainCircuit', color: 'pink', label: 'External Answer Model' },
};

const DIRECT_SOURCES = {
    content_projects: 'content_projects.read',
    gsc: 'gsc.performance',
    internal_links: 'links.internal',
    external_links: 'links.external',
    site: 'site.knowledge',
};

function executionOf(response) {
    if (response?.execution && typeof response.execution === 'object') {
        return response.execution;
    }
    const nested = response?.model_diagnostics?.execution;
    return nested && typeof nested === 'object' ? nested : null;
}

function isAuditSource(row) {
    return row.name === 'articles' && /seoaudit|seo_audit/i.test(row.request);
}

function isFailedStatus(status) {
    const value = String(status || 'ok').toLowerCase();
    return value !== 'ok' && value !== 'empty';
}

function sourceRows(response, execution) {
    const raw = Array.isArray(response?.sources) ? response.sources : [];
    const rows = [];
    for (const source of raw) {
        if (!source || typeof source !== 'object') {
            continue;
        }
        const name = String(source.name || source.resource || '').trim();
        if (name === '' || name === 'gsc_fallback_policy') {
            continue;
        }
        rows.push({
            name,
            status: String(source.status || 'ok'),
            request: String(source.request || source.endpoint || ''),
        });
    }
    if (rows.length > 0 || !Array.isArray(execution?.tools)) {
        return rows;
    }
    for (const tool of execution.tools) {
        const name = typeof tool === 'string' ? tool : String(tool?.name || '');
        if (name === '' || name === 'gsc_fallback_policy') {
            continue;
        }
        rows.push({
            name,
            status: typeof tool === 'object' ? String(tool.status || 'ok') : 'ok',
            request: typeof tool === 'object' ? String(tool.request || '') : '',
        });
    }
    return rows;
}

function capabilityList(execution) {
    if (!Array.isArray(execution?.capabilities)) {
        return [];
    }
    return execution.capabilities.filter((key) => typeof key === 'string' && TRACE_META[key]);
}

function rowsFor(capability, rows, capabilities) {
    if (capability === 'seo_audit.worst_articles') {
        const audit = rows.filter(isAuditSource);
        if (audit.length > 0) {
            return audit;
        }
        if (!capabilities.includes('articles.inventory')) {
            return rows.filter((row) => row.name === 'articles');
        }
        return [];
    }
    if (capability === 'articles.inventory') {
        return rows.filter((row) => row.name === 'articles' && !isAuditSource(row));
    }
    if (capability === 'keywords.relationship') {
        const topicRows = rows.filter((row) => row.name === 'topics' || row.name === 'topic_groups');
        const keywordRows = capabilities.includes('keywords.landscape')
            ? []
            : rows.filter((row) => row.name === 'keywords');
        return [...topicRows, ...keywordRows];
    }
    if (capability === 'keywords.landscape') {
        return rows.filter((row) => row.name === 'keywords');
    }
    const sourceName = Object.entries(DIRECT_SOURCES).find(([, key]) => key === capability)?.[0];
    return sourceName ? rows.filter((row) => row.name === sourceName) : [];
}

function inferredCapabilities(rows) {
    const keys = [];
    if (rows.some(isAuditSource)) {
        keys.push('seo_audit.worst_articles');
    }
    if (rows.some((row) => row.name === 'articles' && !isAuditSource(row))) {
        keys.push('articles.inventory');
    }
    const hasTopics = rows.some((row) => row.name === 'topics');
    const hasTopicGroups = rows.some((row) => row.name === 'topic_groups');
    const hasArticles = rows.some((row) => row.name === 'articles');
    if (hasTopics || (hasTopicGroups && !hasArticles)) {
        keys.push('keywords.relationship');
    } else if (rows.some((row) => row.name === 'keywords')) {
        keys.push('keywords.landscape');
    }
    for (const [name, key] of Object.entries(DIRECT_SOURCES)) {
        if (rows.some((row) => row.name === name)) {
            keys.push(key);
        }
    }
    return keys;
}

function statusOf(rows) {
    return rows.some((row) => isFailedStatus(row.status)) ? 'failed' : 'ok';
}

function titleFor(meta, status, debug, capability, rows, execution) {
        const statusLabel = status === 'failed' ? 'Failed' : (status === 'awaiting' ? 'Awaiting' : 'Executed');
    if (!debug) {
        return `${meta.label} · ${statusLabel}`;
    }
    const sources = [...new Set(rows.map((row) => row.name))].join(', ') || 'none';
    const router = [execution?.router, execution?.outcome].filter(Boolean).join(' / ');
    return [
        `${meta.label} · ${statusLabel}`,
        capability,
        `source ${sources}`,
        router,
    ].filter(Boolean).join(' · ');
}

export function toolTraceItems(response, { debug = false } = {}) {
    if (!response || typeof response !== 'object') {
        return [];
    }
    const execution = executionOf(response);
    const rows = sourceRows(response, execution);
    const recorded = capabilityList(execution);
    const capabilities = recorded.length > 0 ? recorded : inferredCapabilities(rows);
    const items = [];

    for (const capability of TRACE_ORDER) {
        if (!capabilities.includes(capability)) {
            continue;
        }
        const matched = rowsFor(capability, rows, capabilities);
        if (matched.length === 0) {
            continue;
        }
        const meta = TRACE_META[capability];
        const status = statusOf(matched);
        items.push({
            key: capability,
            icon: meta.icon,
            color: meta.color,
            status,
            title: titleFor(meta, status, debug, capability, matched, execution),
        });
    }

    const calls = Number(execution?.external_model_calls || 0);
    const answerStatus = String(response?.answer_diagnostics?.status || execution?.answer_status || '');
    const modelCalled = calls > 0 || response.answer_model_called === true || answerStatus === 'awaiting' || answerStatus === 'rejected';
    if (modelCalled) {
        const meta = TRACE_META.external_answer_model;
        const status = answerStatus === 'awaiting'
            ? 'awaiting'
            : (answerStatus === 'rejected' || isFailedStatus(answerStatus) ? 'failed' : 'ok');
        const modelName = typeof execution?.external_model === 'string' && execution.external_model !== ''
            ? execution.external_model
            : meta.label;
        items.push({
            key: 'external_answer_model',
            icon: meta.icon,
            color: meta.color,
            status,
            title: debug
                ? `${modelName} · ${status === 'awaiting' ? 'Awaiting' : (status === 'failed' ? 'Failed' : 'Executed')} · external_answer_model · calls ${calls}`
                : `${modelName} · ${status === 'awaiting' ? 'Awaiting' : (status === 'failed' ? 'Failed' : 'Executed')}`,
        });
    }

    return items;
}
