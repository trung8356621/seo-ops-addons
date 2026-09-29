import { useCallback, useEffect, useState } from 'react';
import { Copy, History, Loader2, Plus, RotateCcw, Send, Sparkles, X } from 'lucide-react';
import { buildProjectItems, scopePayload, switchProject } from '../projects/projectCatalog.js';
import { normalizeHostContext } from '../host/hostContext.js';
import { ResponseView } from '../response/ResponseBlocks.jsx';
import { ModelDebugModal } from './ModelDebugModal.jsx';
import { copyPlainText } from './clipboard.js';
import { responseToPlainText } from '../response/responseText.js';
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

function waitingForManualModel(modelCall) {
    const stage = String(modelCall?.key || 'model');
    return `Waiting for manual ${stage.charAt(0).toUpperCase()}${stage.slice(1)} result…`;
}

function groupConversation(messages) {
    const turns = [];
    let latestTurn = null;
    for (const message of messages) {
        if (message.role === 'user') {
            latestTurn = { ...message, versions: [] };
            turns.push(latestTurn);
            continue;
        }
        const target = message.originUserMessageId
            ? turns.find((turn) => String(turn.id) === String(message.originUserMessageId))
            : latestTurn;
        if (target) target.versions.push(message);
    }
    return turns;
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

    const [debugMode, setDebugMode] = useState(false);
    const [debugOpen, setDebugOpen] = useState(false);
    const [debugBusy, setDebugBusy] = useState(false);
    const [debugRunUlid, setDebugRunUlid] = useState('');
    const [debugCall, setDebugCall] = useState(null);
    const [debugManualResult, setDebugManualResult] = useState('');
    const [debugParserError, setDebugParserError] = useState('');
    const [processingStatus, setProcessingStatus] = useState(null);
    const [selectedVersions, setSelectedVersions] = useState({});

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
                    const response = m.response_payload || {
                        message: m.content || '',
                        blocks: [],
                        actions: [],
                        sources: [],
                    };
                    return {
                        role: 'assistant',
                        id: m.id,
                        originUserMessageId: m.run?.user_message_id,
                        content: m.content || '',
                        response: {
                            ...response,
                            answer_diagnostics: m.run?.retrieval_summary?.answer_diagnostics || response.answer_diagnostics,
                        },
                    };
                }
                return {
                    role: 'user',
                    id: m.id,
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
        setDebugRunUlid('');
        setDebugCall(null);
        setDebugManualResult('');
        setDebugParserError('');
        setDebugBusy(false);
        setProcessingStatus(null);
        setSelectedVersions({});
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

    async function onApplyDebugResult() {
        if (debugBusy || !debugManualResult.trim() || !debugRunUlid) {
            return;
        }
        setDebugBusy(true);
        setDebugParserError('');
        setDebugOpen(false);
        setProcessingStatus('Thinking…');
        try {
            const payload = await postJson(endpoints.modelDebugApplyUrl, csrf, {
                run_ulid: debugRunUlid,
                manual_result: debugManualResult,
            });
            const data = payload?.data || {};
            if (data.status === 'paused') {
                setDebugCall(data.model_call || null);
                setDebugManualResult('');
                setProcessingStatus(waitingForManualModel(data.model_call));
                setDebugOpen(true);
                return;
            }
            setMessages((current) => [...current, {
                role: 'assistant',
                id: data.assistant_message_id,
                originUserMessageId: data.user_message_id,
                content: data.message || '',
                response: data,
            }]);
            setSelectedVersions((current) => ({ ...current, [data.user_message_id]: Number.MAX_SAFE_INTEGER }));
            resetDebugState();
            fetchThreads(currentScopeRef);
        } catch (caught) {
            setDebugParserError(caught.message);
            setProcessingStatus(null);
            setDebugOpen(true);
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
        await copyPlainText(text);
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
        setProcessingStatus('Thinking…');
        setDraft('');
        const history = groupConversation(messages).flatMap((turn) => {
            const latest = turn.versions.at(-1);
            return latest
                ? [{ role: 'user', content: turn.content }, { role: 'assistant', content: latest.content }]
                : [{ role: 'user', content: turn.content }];
        });
        const clientKey = `pending:${Date.now()}`;
        setMessages((current) => [...current, { role: 'user', clientKey, content: message }]);

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
                debug_mode: debugMode,
                diagnostics,
            });
            const data = payload?.data || {};
            if (data.user_message_id) {
                setMessages((current) => current.map((item) => item.clientKey === clientKey
                    ? { ...item, id: data.user_message_id }
                    : item));
            }
            if (data.status === 'paused') {
                setDebugRunUlid(data.run_ulid || '');
                setDebugCall(data.model_call || null);
                setDebugManualResult('');
                setDebugParserError('');
                setProcessingStatus(waitingForManualModel(data.model_call));
                setDebugOpen(true);
                const pausedThreadUlid = data.thread_ulid;
                if (pausedThreadUlid && pausedThreadUlid !== activeThreadUlid) {
                    setActiveThreadUlid(pausedThreadUlid);
                    setStoredThreadUlid(hostContext.appKey, currentScopeRef, pausedThreadUlid);
                }
                return;
            }
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
                id: data.assistant_message_id,
                originUserMessageId: data.user_message_id,
                content: response?.message || '',
                response,
            }]);
            setProcessingStatus(null);
        } catch (caught) {
            setError(caught.message);
            setProcessingStatus(null);
        } finally {
            setBusy(false);
        }
    }

    async function onRerun(userMessageId) {
        if (busy || !activeThreadUlid || !userMessageId) return;
        setBusy(true);
        setError('');
        setProcessingStatus('Thinking…');
        try {
            const payload = await postJson(`${endpoints.threadsUrl}/${activeThreadUlid}/messages/${userMessageId}/rerun`, csrf, {
                debug_mode: debugMode,
                diagnostics,
            });
            const data = payload?.data || {};
            if (data.status === 'paused') {
                setDebugRunUlid(data.run_ulid || '');
                setDebugCall(data.model_call || null);
                setDebugManualResult('');
                setDebugParserError('');
                setProcessingStatus(waitingForManualModel(data.model_call));
                setDebugOpen(true);
                return;
            }
            setMessages((current) => [...current, {
                role: 'assistant',
                id: data.assistant_message_id,
                originUserMessageId: data.user_message_id,
                content: data.message || '',
                response: data,
            }]);
            setSelectedVersions((current) => ({ ...current, [data.user_message_id]: Number.MAX_SAFE_INTEGER }));
            setProcessingStatus(null);
            fetchThreads(currentScopeRef);
        } catch (caught) {
            setError(caught.message);
            setProcessingStatus(null);
        } finally {
            setBusy(false);
        }
    }

    const conversationTurns = groupConversation(messages);

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
                        <label className="agent-debug-switch" title="Debug mode">
                            <span>Debug</span>
                            <input
                                type="checkbox"
                                checked={debugMode}
                                onChange={(event) => setDebugMode(event.target.checked)}
                                disabled={busy || debugBusy || debugOpen}
                                aria-label="Debug mode"
                            />
                            <span className="agent-debug-switch__track" aria-hidden="true" />
                        </label>
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
                                <label className="agent-debug-switch" title="Debug mode">
                                    <span>Debug</span>
                                    <input
                                        type="checkbox"
                                        checked={debugMode}
                                        onChange={(event) => setDebugMode(event.target.checked)}
                                        disabled={busy || debugBusy || debugOpen}
                                        aria-label="Debug mode"
                                    />
                                    <span className="agent-debug-switch__track" aria-hidden="true" />
                                </label>
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
                    {conversationTurns.length === 0 ? <p className="agent-empty">Ask about this project in plain language.</p> : null}
                    {conversationTurns.map((turn, turnIndex) => {
                        const turnKey = turn.id || turn.clientKey || turnIndex;
                        const requestedIndex = selectedVersions[turnKey];
                        const versionIndex = Math.min(
                            requestedIndex ?? turn.versions.length - 1,
                            turn.versions.length - 1,
                        );
                        const version = versionIndex >= 0 ? turn.versions[versionIndex] : null;
                        return (
                            <div key={turnKey} className="agent-conversation-turn">
                                <article className="is-user">
                                    <p>{turn.content}</p>
                                    <div className="agent-message-actions">
                                        <button type="button" onClick={() => copyText(turn.content)} title="Copy question" aria-label="Copy question"><Copy size={14} /></button>
                                    </div>
                                </article>
                                {version ? (
                                    <article className="is-assistant">
                                        <ResponseView response={version.response} />
                                        {diagnostics && version.response?.answer_diagnostics ? (
                                            <details className="agent-answer-diagnostics">
                                                <summary>Answer diagnostics</summary>
                                                <p><strong>Parser rejection:</strong></p>
                                                <pre>{version.response.answer_diagnostics.parser_error}</pre>
                                                <p><strong>Raw model completion:</strong></p>
                                                <pre>{version.response.answer_diagnostics.raw_completion}</pre>
                                            </details>
                                        ) : null}
                                        <div className="agent-message-actions agent-message-actions--assistant">
                                            <button type="button" onClick={() => copyText(responseToPlainText(version.response))} title="Copy answer" aria-label="Copy answer"><Copy size={14} /></button>
                                            <button type="button" onClick={() => onRerun(turn.id)} disabled={busy || !turn.id} title="Rerun" aria-label="Rerun"><RotateCcw size={14} /></button>
                                            {turn.versions.length > 1 ? (
                                                <span className="agent-version-nav" aria-label="Answer versions">
                                                    <button
                                                        type="button"
                                                        aria-label="Previous answer"
                                                        disabled={versionIndex <= 0}
                                                        onClick={() => setSelectedVersions((current) => ({ ...current, [turnKey]: versionIndex - 1 }))}
                                                    >‹</button>
                                                    <span>{versionIndex + 1} / {turn.versions.length}</span>
                                                    <button
                                                        type="button"
                                                        aria-label="Next answer"
                                                        disabled={versionIndex >= turn.versions.length - 1}
                                                        onClick={() => setSelectedVersions((current) => ({ ...current, [turnKey]: versionIndex + 1 }))}
                                                    >›</button>
                                                </span>
                                            ) : null}
                                        </div>
                                    </article>
                                ) : null}
                            </div>
                        );
                    })}
                    {processingStatus ? (
                        <article className="is-assistant agent-processing-status" role="status" aria-live="polite">
                            <Loader2 size={16} className="agent-spin" />
                            <span>{processingStatus}</span>
                        </article>
                    ) : null}
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
                        <button type="submit" disabled={busy || draft.trim() === ''} className={busy ? 'is-busy' : ''}>
                            {busy ? <Loader2 size={16} className="agent-spin" /> : <Send size={16} />}
                            Send
                        </button>
                    </div>
                </form>
            </section>

            <ModelDebugModal
                isOpen={debugOpen}
                scopeLabel={selected.label}
                modelCall={debugCall}
                manualResult={debugManualResult}
                onManualResultChange={setDebugManualResult}
                onApply={onApplyDebugResult}
                isApplying={debugBusy}
                parserError={debugParserError}
            />
        </div>
    );
}

export default AgentWidget;
