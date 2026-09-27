import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';
import { Copy, Loader2, Send } from 'lucide-react';
import { buildProjectItems, scopePayload, switchProject } from '../projects/projectCatalog.js';
import { ResponseView } from '../response/ResponseBlocks.jsx';
import './agent-runtime.css';

function readRoot() {
    return document.getElementById('agent-runtime-root');
}

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

function AgentApp({ projectsUrl, turnUrl, copyUrl, csrf }) {
    const [projects, setProjects] = useState(buildProjectItems([]));
    const [selectedKey, setSelectedKey] = useState('global');
    const [draft, setDraft] = useState('');
    const [messages, setMessages] = useState([]);
    const [busy, setBusy] = useState(false);
    const [copyState, setCopyState] = useState('');
    const [error, setError] = useState('');
    const [diagnostics, setDiagnostics] = useState(false);
    const [lastCopy, setLastCopy] = useState(null);

    useEffect(() => {
        let cancelled = false;
        fetch(projectsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((payload) => {
                if (cancelled) {
                    return;
                }
                const rows = payload?.data?.projects || [];
                const sites = rows
                    .filter((row) => row.type === 'site')
                    .map((row) => ({ id: row.siteId, domain: row.label }));
                setProjects(buildProjectItems(sites));
            })
            .catch(() => {
                if (!cancelled) {
                    setError('Could not load sites.');
                }
            });
        return () => {
            cancelled = true;
        };
    }, [projectsUrl]);

    const selected = switchProject(projects, selectedKey);
    const globalUnsupported = selected.retrieval === 'unsupported';

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
            const payload = await postJson(copyUrl, csrf, {
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
        try {
            const payload = await postJson(turnUrl, csrf, {
                scope: scopePayload(selected),
                message,
                history,
            });
            const response = payload?.data?.response ?? payload?.data;
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

    return (
        <div className="agent-shell">
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
                                    setMessages([]);
                                    setLastCopy(null);
                                    setError('');
                                }}
                            >
                                <span>{project.label}</span>
                                {project.retrieval === 'unsupported' ? <small>No global API</small> : null}
                            </button>
                        </li>
                    ))}
                </ul>
                <label className="agent-diagnostics">
                    <input type="checkbox" checked={diagnostics} onChange={(event) => setDiagnostics(event.target.checked)} />
                    Diagnostics
                </label>
            </aside>
            <section className="agent-workspace">
                <header>
                    <h1>{selected.label}</h1>
                    {globalUnsupported ? (
                        <p className="agent-warning">All Sites retrieval is unsupported until a global SEO Access API is agreed. Sending a message will not scan every site.</p>
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
                        {diagnostics && lastCopy?.routing ? (
                            <button type="button" onClick={() => copyText(lastCopy.routing)}>Copy routing input</button>
                        ) : null}
                        <button type="button" onClick={onCopy} disabled={busy || draft.trim() === ''}>
                            <Copy size={16} />
                            {copyState || 'Copy'}
                        </button>
                        <button type="submit" disabled={busy || draft.trim() === ''} className={busy ? 'is-busy' : ''}>
                            {busy ? <Loader2 size={16} className="agent-spin" /> : <Send size={16} />}
                            Send
                        </button>
                    </div>
                </form>
            </section>
        </div>
    );
}

const root = readRoot();
if (root) {
    createRoot(root).render(
        <AgentApp
            projectsUrl={root.dataset.projectsUrl}
            turnUrl={root.dataset.turnUrl}
            copyUrl={root.dataset.copyUrl}
            csrf={root.dataset.csrf || ''}
        />,
    );
}
