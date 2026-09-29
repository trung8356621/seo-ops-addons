import { useCallback, useEffect, useState } from 'react';
import { Bug, Copy, History, Loader2, Plus, Send, Sparkles, X } from 'lucide-react';
import { buildProjectItems, scopePayload, switchProject } from '../projects/projectCatalog.js';
import { normalizeHostContext } from '../host/hostContext.js';
import { ResponseView } from '../response/ResponseBlocks.jsx';
import { ModelDebugModal } from './ModelDebugModal.jsx';
import {
    clearStoredThreadUlid,
    formatTimeAgo,
    getStoredThreadUlid,
    setStoredThreadUlid,
} from './agentThreadState.js';
import '../app/agent-runtime.css';

async function postJson(url, csrf, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(payload.message || 'Request failed');
    }
    return payload;
}

/**
 * Canonical Agent React widget.
 * Decoupled from SEO navigation/page logic and embeddable in any host
 * (standalone harness, SEO Ops drawer, WordPress, or other addon surfaces).
 */
export function AgentWidget({
    hostContext: rawHostContext,
    endpoints: rawEndpoints,
    projectsUrl: propProjectsUrl,
    turnUrl: propTurnUrl,
    threadsUrl: propThreadsUrl,
    copyUrl: propCopyUrl,
    modelDebugStartUrl: propModelDebugStartUrl,
    modelDebugApplyUrl: propModelDebugApplyUrl,
    csrf = '',
    mode = 'standalone',
    onClose = null,
    className = '',
}) {
    const hostContext = normalizeHostContext(rawHostContext);
    const endpoints = {
        projectsUrl: propProjectsUrl || rawEndpoints?.projectsUrl || '/agent-runtime/projects',
        turnUrl: propTurnUrl || rawEndpoints?.turnUrl || '/agent-runtime/turns',
        threadsUrl: propThreadsUrl || rawEndpoints?.threadsUrl || '/agent-runtime/threads',
        copyUrl: propCopyUrl || rawEndpoints?.copyUrl || '/agent-runtime/model-input',
        modelDebugStartUrl: propModelDebugStartUrl || rawEndpoints?.modelDebugStartUrl || '/agent-runtime/model-debug/start',
        modelDebugApplyUrl: propModelDebugApplyUrl || rawEndpoints?.modelDebugApplyUrl || '/agent-runtime/model-debug/apply',
    };

    // Initialize initial scope based on hostContext or fallback to global
    const initialScope = hostContext.scope;
    const initialKey = initialScope?.ref || (initialScope?.siteId ? `site:${initialScope.siteId}` : 'global');

    const [projects, setProjects] = useState(() => {
        if (initialScope && initialScope.type === 'site' && initialScope.siteId) {
            return buildProjectItems([{ id: initialScope.siteId, domain: initialScope.label }]);
        }
        return buildProjectItems([]);
    });

    const [selectedKey, setSelectedKey] = useState(initialKey);
    const [activeThreadUlid, setActiveThreadUlid] = useState(null);
    const [threads, setThreads] = useState([]);
    const [showHistory, setShowHistory] = useState(false);
    const [loadingThreads, setLoadingThreads] = useState(false);

    const [draft, setDraft] = useState('');
    const [messages, setMessages] = useState([]);
    const [busy, setBusy] = useState(false);
    const [copyState, setCopyState] = useState('');
    const [error, setError] = useState('');
    const [diagnostics, setDiagnostics] = useState(false);
    const [lastCopy, setLastCopy] = useState(null);

    // Model Debug State
    const [debugOpen, setDebugOpen] = useState(false);
    const [debugBusy, setDebugBusy] = useState(false);
    const [debugStage, setDebugStage] = useState('decision');
    const [debugUserMessage, setDebugUserMessage] = useState('');

    const [debugDecisionPrompt, setDebugDecisionPrompt] = useState('');
    const [debugDecisionPromptSize, setDebugDecisionPromptSize] = useState(0);
    const [debugDecisionAssumedModel, setDebugDecisionAssumedModel] = useState(null);
    const [debugDecisionManualResult, setDebugDecisionManualResult] = useState('');
    const [debugDecisionParseSuccess, setDebugDecisionParseSuccess] = useState(false);
    const [debugDecisionParserError, setDebugDecisionParserError] = useState('');
    const [debugDecisionApplied, setDebugDecisionApplied] = useState(false);

    const [debugRetrievalTrace, setDebugRetrievalTrace] = useState([]);
    const [debugParsedDecision, setDebugParsedDecision] = useState(null);

    const [debugAnswerPrompt, setDebugAnswerPrompt] = useState('');
    const [debugAnswerPromptSize, setDebugAnswerPromptSize] = useState(0);
    const [debugAnswerAssumedModel, setDebugAnswerAssumedModel] = useState(null);
    const [debugAnswerManualResult, setDebugAnswerManualResult] = useState('');
    const [debugAnswerParseSuccess, setDebugAnswerParseSuccess] = useState(false);
    const [debugAnswerParserError, setDebugAnswerParserError] = useState('');
    const [debugAnswerApplied, setDebugAnswerApplied] = useState(false);

    const [debugCanonicalResponse, setDebugCanonicalResponse] = useState(null);

    // Fetch projects catalog if in standalone mode or projectsUrl provided
    useEffect(() => {
        let cancelled = false;
        if (!endpoints.projectsUrl) {
            return;
        }

        fetch(endpoints.projectsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((payload) => {
                if (cancelled) {
                    return;
                }
                const rows = payload?.data?.projects || [];
                const sites = rows
                    .filter((row) => row.type === 'site')
                    .map((row) => ({ id: row.siteId, domain: row.label }));
                const items = buildProjectItems(sites);
                setProjects(items);

                // If host supplied a specific scope, ensure it remains selected
                if (initialKey && items.some((item) => item.key === initialKey)) {
                    setSelectedKey(initialKey);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setError('Could not load sites.');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [endpoints.projectsUrl, initialKey]);

    const selected = switchProject(projects, selectedKey);
    const currentScopeRef = selected.ref || (selected.siteId ? `site:${selected.siteId}` : 'global');
    const globalUnsupported = selected.retrieval === 'unsupported';
    const isDrawer = mode === 'drawer';
    const showSidebar = !isDrawer && (mode !== 'embedded' || !initialScope || initialScope.type === 'global');

    // Fetch recent threads list for current scope
    const fetchThreads = useCallback(async (scopeRef) => {
        if (!endpoints.threadsUrl) {
            return;
        }
        setLoadingThreads(true);
        try {
            const url = new URL(endpoints.threadsUrl, window.location.origin);
            url.searchParams.set('appKey', hostContext.appKey || 'seo-ops');
            if (scopeRef) {
                url.searchParams.set('scope_ref', scopeRef);
            }
            const res = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) {
                return;
            }
            const payload = await res.json();
            const list = Array.isArray(payload?.data)
                ? payload.data
                : (Array.isArray(payload) ? payload : []);
            setThreads(list);
        } catch {
            // Ignore list fetch network errors
        } finally {
            setLoadingThreads(false);
        }
    }, [endpoints.threadsUrl, hostContext.appKey]);

    // Hydrate thread messages from DB without running any model
    const loadThread = useCallback(async (ulid, scopeRef) => {
        if (!endpoints.threadsUrl || !ulid) {
            return;
        }
        setBusy(true);
        setError('');
        try {
            const res = await fetch(`${endpoints.threadsUrl}/${ulid}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(payload.message || 'Could not load conversation.');
            }
            const thread = payload?.data;
            if (!thread) {
                return;
            }

            setActiveThreadUlid(thread.ulid);
            const targetScope = scopeRef || thread.scope_ref || currentScopeRef;
            setStoredThreadUlid(hostContext.appKey, targetScope, thread.ulid);

            const mapped = (thread.messages || []).map((m) => {
                if (m.role === 'assistant') {
                    return {
                        role: 'assistant',
                        content: m.content || '',
                        response: m.response_payload || {
                            message: m.content || '',
                            blocks: [],
                            actions: [],
                            sources: [],
                        },
                    };
                }
                return {
                    role: 'user',
                    content: m.content || '',
                };
            });
            setMessages(mapped);
        } catch (err) {
            setError(err.message);
        } finally {
            setBusy(false);
        }
    }, [endpoints.threadsUrl, hostContext.appKey, currentScopeRef]);

    const resetDebugState = useCallback(() => {
        setDebugStage('decision');
        setDebugUserMessage('');
        setDebugDecisionPrompt('');
        setDebugDecisionPromptSize(0);
        setDebugDecisionAssumedModel(null);
        setDebugDecisionManualResult('');
        setDebugDecisionParseSuccess(false);
        setDebugDecisionParserError('');
        setDebugDecisionApplied(false);
        setDebugRetrievalTrace([]);
        setDebugParsedDecision(null);
        setDebugAnswerPrompt('');
        setDebugAnswerPromptSize(0);
        setDebugAnswerAssumedModel(null);
        setDebugAnswerManualResult('');
        setDebugAnswerParseSuccess(false);
        setDebugAnswerParserError('');
        setDebugAnswerApplied(false);
        setDebugCanonicalResponse(null);
        setDebugBusy(false);
    }, []);

    // New conversation action: clears active thread, messages, and saved state
    const onNewConversation = useCallback(() => {
        setActiveThreadUlid(null);
        setMessages([]);
        setError('');
        setLastCopy(null);
        setDraft('');
        clearStoredThreadUlid(hostContext.appKey, currentScopeRef);
        setShowHistory(false);
        resetDebugState();
        setDebugOpen(false);
    }, [hostContext.appKey, currentScopeRef, resetDebugState]);

    const startModelDebug = useCallback(async (messageOverride) => {
        const message = (messageOverride ?? draft).trim();
        if (busy || debugBusy || message === '') {
            return;
        }
        if (globalUnsupported) {
            setError('All Sites retrieval is unsupported until a global SEO Access API is available. Select a site project.');
            return;
        }
        setDebugBusy(true);
        setError('');
        try {
            const payload = await postJson(endpoints.modelDebugStartUrl, csrf, {
                hostContext: {
                    appKey: hostContext.appKey,
                    scope: scopePayload(selected),
                    capabilities: hostContext.capabilities,
                },
                scope: scopePayload(selected),
                message,
                history: messages.map((m) => ({ role: m.role, content: m.content })),
            });
            const data = payload?.data || {};
            setDebugUserMessage(message);
            setDebugDecisionPrompt(data.full_prompt || '');
            setDebugDecisionPromptSize(data.prompt_size || (data.full_prompt ? data.full_prompt.length : 0));
            setDebugDecisionAssumedModel(data.assumed_model || null);
            setDebugDecisionManualResult('');
            setDebugDecisionParseSuccess(false);
            setDebugDecisionParserError('');
            setDebugDecisionApplied(false);
            setDebugRetrievalTrace([]);
            setDebugParsedDecision(null);
            setDebugAnswerPrompt('');
            setDebugAnswerPromptSize(0);
            setDebugAnswerAssumedModel(null);
            setDebugAnswerManualResult('');
            setDebugAnswerParseSuccess(false);
            setDebugAnswerParserError('');
            setDebugAnswerApplied(false);
            setDebugCanonicalResponse(null);
            setDebugStage('decision');
            setDebugOpen(true);
        } catch (caught) {
            setError(caught.message);
        } finally {
            setDebugBusy(false);
        }
    }, [busy, debugBusy, draft, globalUnsupported, endpoints.modelDebugStartUrl, csrf, hostContext, selected, messages]);

    const onResetDebug = useCallback(async () => {
        const message = debugUserMessage || draft.trim();
        resetDebugState();
        if (message) {
            await startModelDebug(message);
        }
    }, [debugUserMessage, draft, resetDebugState, startModelDebug]);

    async function onApplyDecision() {
        if (debugBusy || !debugDecisionManualResult.trim()) {
            return;
        }
        setDebugBusy(true);
        setDebugDecisionParserError('');
        try {
            const payload = await postJson(endpoints.modelDebugApplyUrl, csrf, {
                stage: 'decision',
                manual_result: debugDecisionManualResult,
                hostContext: {
                    appKey: hostContext.appKey,
                    scope: scopePayload(selected),
                    capabilities: hostContext.capabilities,
                },
                scope: scopePayload(selected),
                message: debugUserMessage,
                history: messages.map((m) => ({ role: m.role, content: m.content })),
            });
            const data = payload?.data || {};
            if (data.status === 'error' || !data.parse_success) {
                setDebugDecisionParseSuccess(false);
                setDebugDecisionParserError(data.error || 'Failed to parse decision JSON.');
                setDebugDecisionApplied(true);
                return;
            }
            setDebugDecisionParseSuccess(true);
            setDebugDecisionParserError('');
            setDebugDecisionApplied(true);
            setDebugParsedDecision(data.parsed_decision || null);
            setDebugRetrievalTrace(data.retrieval_trace || []);

            if (data.next_stage === 'answer') {
                setDebugAnswerPrompt(data.full_prompt || '');
                setDebugAnswerPromptSize(data.prompt_size || (data.full_prompt ? data.full_prompt.length : 0));
                setDebugAnswerAssumedModel(data.assumed_model || null);
                setDebugStage('answer');
            }
        } catch (caught) {
            setDebugDecisionParserError(caught.message);
            setDebugDecisionParseSuccess(false);
            setDebugDecisionApplied(true);
        } finally {
            setDebugBusy(false);
        }
    }

    async function onApplyAnswer() {
        if (debugBusy || !debugAnswerManualResult.trim()) {
            return;
        }
        setDebugBusy(true);
        setDebugAnswerParserError('');
        try {
            const payload = await postJson(endpoints.modelDebugApplyUrl, csrf, {
                stage: 'answer',
                manual_result: debugAnswerManualResult,
                raw_decision: debugDecisionManualResult,
                hostContext: {
                    appKey: hostContext.appKey,
                    scope: scopePayload(selected),
                    capabilities: hostContext.capabilities,
                },
                scope: scopePayload(selected),
                message: debugUserMessage,
                history: messages.map((m) => ({ role: m.role, content: m.content })),
            });
            const data = payload?.data || {};
            if (data.status === 'error' || !data.parse_success) {
                setDebugAnswerParseSuccess(false);
                setDebugAnswerParserError(data.error || 'Failed to parse answer response.');
                setDebugAnswerApplied(true);
                return;
            }
            setDebugAnswerParseSuccess(true);
            setDebugAnswerParserError('');
            setDebugAnswerApplied(true);
            setDebugCanonicalResponse(data.canonical_response || null);
            setDebugStage('done');
        } catch (caught) {
            setDebugAnswerParserError(caught.message);
            setDebugAnswerParseSuccess(false);
            setDebugAnswerApplied(true);
        } finally {
            setDebugBusy(false);
        }
    }

    // Scope change / initial mount effect: sync threads list and restore stored active thread
    useEffect(() => {
        fetchThreads(currentScopeRef);

        const storedUlid = getStoredThreadUlid(hostContext.appKey, currentScopeRef);
        if (storedUlid) {
            loadThread(storedUlid, currentScopeRef);
        } else {
            setActiveThreadUlid(null);
            setMessages([]);
        }
        resetDebugState();
        setDebugOpen(false);
    }, [currentScopeRef, fetchThreads, loadThread, hostContext.appKey, resetDebugState]);

    async function copyText(text) {
        await navigator.clipboard.writeText(text);
        setCopyState('Copied');
        window.setTimeout(() => setCopyState(''), 1200);
    }

    async function onCopy() {
        if (busy || draft.trim() === '') {
            return;
        }
        if (globalUnsupported) {
            setError('All Sites retrieval is unsupported until a global SEO Access API is available. Select a site project.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            const payload = await postJson(endpoints.copyUrl, csrf, {
                hostContext: {
                    appKey: hostContext.appKey,
                    scope: scopePayload(selected),
                    capabilities: hostContext.capabilities,
                },
                scope: scopePayload(selected),
                message: draft,
                history: messages.map((message) => ({ role: message.role, content: message.content })),
            });
            const text = payload?.data?.copy?.answer || payload?.data?.copy || '';
            setLastCopy(payload?.data?.copy || null);
            await copyText(text);
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusy(false);
        }
    }

    async function onSend() {
        const message = draft.trim();
        if (busy || message === '') {
            return;
        }
        if (globalUnsupported) {
            setError('All Sites retrieval is unsupported until a global SEO Access API is available. Select a site project to send requests.');
            return;
        }
        setBusy(true);
        setError('');
        setDraft('');
        const history = messages.map((item) => ({ role: item.role, content: item.content }));
        setMessages((current) => [...current, { role: 'user', content: message }]);

        // Thread continuity:
        // If activeThreadUlid exists, use POST /threads/{ulid}/turns to append.
        // Otherwise, use POST /turns which creates a new thread.
        const sendUrl = activeThreadUlid
            ? `${endpoints.threadsUrl}/${activeThreadUlid}/turns`
            : endpoints.turnUrl;

        try {
            const payload = await postJson(sendUrl, csrf, {
                hostContext: {
                    appKey: hostContext.appKey,
                    scope: scopePayload(selected),
                    capabilities: hostContext.capabilities,
                },
                scope: scopePayload(selected),
                message,
                history,
            });
            const response = payload?.data?.response ?? payload?.data;
            const returnedUlid = payload?.data?.thread_ulid || response?.thread_ulid;

            if (returnedUlid && returnedUlid !== activeThreadUlid) {
                setActiveThreadUlid(returnedUlid);
                setStoredThreadUlid(hostContext.appKey, currentScopeRef, returnedUlid);
                fetchThreads(currentScopeRef);
            }

            setLastCopy(payload?.data?.copy || null);
            setMessages((current) => [...current, {
                role: 'assistant',
                content: response?.message || '',
                response,
            }]);
        } catch (caught) {
            setError(caught.message);
        } finally {
            setBusy(false);
        }
    }

    const shellClass = [
        'agent-shell',
        mode === 'embedded' ? 'agent-shell--embedded' : '',
        isDrawer ? 'agent-shell--drawer' : '',
        className,
    ].filter(Boolean).join(' ');

    return (
        <div className={shellClass}>
            {isDrawer ? (
                <div className="agent-drawer-header">
                    <div className="agent-drawer-header__title">
                        <Sparkles size={18} className="agent-sparkles-icon" />
                        <span>AI Agent</span>
                    </div>
                    <div className="agent-drawer-header__controls">
                        <button
                            type="button"
                            className="agent-header-btn agent-new-btn"
                            onClick={onNewConversation}
                            title="New conversation"
                            aria-label="New conversation"
                        >
                            <Plus size={14} />
                            <span className="agent-btn-text">New</span>
                        </button>
                        <button
                            type="button"
                            className={`agent-header-btn agent-history-btn ${showHistory ? 'is-active' : ''}`}
                            onClick={() => setShowHistory((prev) => !prev)}
                            title="Conversation history"
                            aria-label="Conversation history"
                            aria-expanded={showHistory}
                        >
                            <History size={14} />
                            <span className="agent-btn-text">History</span>
                        </button>
                        <select
                            className="agent-project-select"
                            value={selected.key}
                            onChange={(event) => {
                                const nextKey = event.target.value;
                                const next = switchProject(projects, nextKey);
                                setSelectedKey(next.key);
                                setError('');
                                setLastCopy(null);
                                setShowHistory(false);
                                resetDebugState();
                                setDebugOpen(false);
                            }}
                            aria-label="Select Project"
                        >
                            {projects.map((project) => (
                                <option key={project.key} value={project.key}>
                                    {project.label} {project.retrieval === 'unsupported' ? '(No global API)' : ''}
                                </option>
                            ))}
                        </select>
                        {onClose ? (
                            <button
                                type="button"
                                className="agent-drawer-close-btn"
                                onClick={onClose}
                                aria-label="Close Agent drawer"
                                title="Close"
                            >
                                <X size={18} />
                            </button>
                        ) : null}
                    </div>
                </div>
            ) : null}

            {/* Compact History Popover */}
            {showHistory ? (
                <div className="agent-history-popover" role="dialog" aria-label="Conversation History">
                    <div className="agent-history-popover__header">
                        <span className="agent-history-popover__title">History · {selected.label}</span>
                        <div className="agent-history-popover__actions">
                            <button
                                type="button"
                                className="agent-history-new-btn"
                                onClick={onNewConversation}
                            >
                                <Plus size={13} />
                                <span>New</span>
                            </button>
                            <button
                                type="button"
                                className="agent-history-close-btn"
                                onClick={() => setShowHistory(false)}
                                aria-label="Close history"
                            >
                                <X size={14} />
                            </button>
                        </div>
                    </div>
                    <div className="agent-history-popover__body">
                        {loadingThreads ? (
                            <div className="agent-history-empty">
                                <Loader2 size={16} className="agent-spin" />
                                <span>Loading conversations...</span>
                            </div>
                        ) : threads.length === 0 ? (
                            <p className="agent-history-empty">No conversations for this site yet.</p>
                        ) : (
                            <ul className="agent-history-list">
                                {threads.map((t) => (
                                    <li key={t.ulid}>
                                        <button
                                            type="button"
                                            className={`agent-history-item ${t.ulid === activeThreadUlid ? 'is-active' : ''}`}
                                            onClick={() => {
                                                loadThread(t.ulid, currentScopeRef);
                                                setShowHistory(false);
                                            }}
                                        >
                                            <span className="agent-history-item__title">{t.title || 'Untitled conversation'}</span>
                                            <span className="agent-history-item__meta">
                                                {formatTimeAgo(t.last_message_at || t.created_at)}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            ) : null}

            {showSidebar ? (
                <aside className="agent-projects">
                    <p className="agent-kicker">Projects</p>
                    <ul>
                        {projects.map((project) => (
                            <li key={project.key}>
                                <button
                                    type="button"
                                    className={project.key === selected.key ? 'is-active' : ''}
                                    onClick={() => {
                                        const next = switchProject(projects, project.key);
                                        setSelectedKey(next.key);
                                        setError('');
                                        setLastCopy(null);
                                        setShowHistory(false);
                                        resetDebugState();
                                        setDebugOpen(false);
                                    }}
                                >
                                    <span>{project.label}</span>
                                    {project.retrieval === 'unsupported' ? <small>No global API</small> : null}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <label className="agent-diagnostics">
                        <input
                            type="checkbox"
                            checked={diagnostics}
                            onChange={(event) => setDiagnostics(event.target.checked)}
                        />
                        Diagnostics
                    </label>
                </aside>
            ) : null}

            <section className="agent-workspace">
                <header className="agent-workspace-header">
                    {!isDrawer ? (
                        <div className="agent-workspace-header__top">
                            <h1>{selected.label}</h1>
                            <div className="agent-workspace-actions">
                                <button
                                    type="button"
                                    className="agent-header-btn agent-new-btn"
                                    onClick={onNewConversation}
                                    title="New conversation"
                                >
                                    <Plus size={14} />
                                    <span>New</span>
                                </button>
                                <button
                                    type="button"
                                    className={`agent-header-btn agent-history-btn ${showHistory ? 'is-active' : ''}`}
                                    onClick={() => setShowHistory((prev) => !prev)}
                                    title="Conversation history"
                                    aria-expanded={showHistory}
                                >
                                    <History size={14} />
                                    <span>History</span>
                                </button>
                            </div>
                        </div>
                    ) : null}
                    {globalUnsupported ? (
                        <p className="agent-warning">
                            All Sites retrieval is unsupported until a global SEO Access API is agreed. Select a site project to send requests.
                        </p>
                    ) : null}
                </header>
                <div className="agent-messages">
                    {messages.length === 0 ? <p className="agent-empty">Ask about this project in plain language.</p> : null}
                    {messages.map((message, index) => (
                        <article key={index} className={message.role === 'user' ? 'is-user' : 'is-assistant'}>
                            {message.role === 'assistant'
                                ? <ResponseView response={message.response} />
                                : <p>{message.content}</p>}
                        </article>
                    ))}
                </div>
                {error ? <p className="agent-warning">{error}</p> : null}
                <form
                    className="agent-composer"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSend();
                    }}
                >
                    <textarea
                        value={draft}
                        placeholder="Tháng 9 traffic có vấn đề gì và nên viết thêm gì?"
                        onChange={(event) => setDraft(event.target.value)}
                    />
                    <div className="agent-composer__actions">
                        {isDrawer ? (
                            <label className="agent-diagnostics agent-diagnostics--drawer">
                                <input
                                    type="checkbox"
                                    checked={diagnostics}
                                    onChange={(event) => setDiagnostics(event.target.checked)}
                                />
                                Diag
                            </label>
                        ) : null}
                        {diagnostics && lastCopy?.routing ? (
                            <button type="button" onClick={() => copyText(lastCopy.routing)}>
                                Copy routing input
                            </button>
                        ) : null}
                        <button
                            type="button"
                            className="agent-model-debug-btn"
                            onClick={() => startModelDebug()}
                            disabled={busy || debugBusy || draft.trim() === ''}
                            title="Open Model Debug simulator"
                        >
                            <Bug size={16} />
                            <span>Model Debug</span>
                        </button>
                        <button type="submit" disabled={busy || draft.trim() === ''} className={busy ? 'is-busy' : ''}>
                            {busy ? <Loader2 size={16} className="agent-spin" /> : <Send size={16} />}
                            Send
                        </button>
                    </div>
                </form>
            </section>

            <ModelDebugModal
                isOpen={debugOpen}
                onClose={() => setDebugOpen(false)}
                onReset={onResetDebug}
                scopeLabel={selected.label}
                userMessage={debugUserMessage}
                decisionPrompt={debugDecisionPrompt}
                decisionPromptSize={debugDecisionPromptSize}
                decisionAssumedModel={debugDecisionAssumedModel}
                decisionManualResult={debugDecisionManualResult}
                onDecisionManualResultChange={setDebugDecisionManualResult}
                onApplyDecision={onApplyDecision}
                isApplyingDecision={debugBusy}
                decisionParseSuccess={debugDecisionParseSuccess}
                decisionParserError={debugDecisionParserError}
                decisionApplied={debugDecisionApplied}
                retrievalTrace={debugRetrievalTrace}
                parsedDecision={debugParsedDecision}
                answerPrompt={debugAnswerPrompt}
                answerPromptSize={debugAnswerPromptSize}
                answerAssumedModel={debugAnswerAssumedModel}
                answerManualResult={debugAnswerManualResult}
                onAnswerManualResultChange={setDebugAnswerManualResult}
                onApplyAnswer={onApplyAnswer}
                isApplyingAnswer={debugBusy}
                answerParseSuccess={debugAnswerParseSuccess}
                answerParserError={debugAnswerParserError}
                answerApplied={debugAnswerApplied}
                canonicalResponse={debugCanonicalResponse}
            />
        </div>
    );
}

export default AgentWidget;
