import { useCallback, useEffect, useRef, useState } from 'react';
import { Archive, Copy, History, ImageIcon, Loader2, Plus, RotateCcw, Send, Sparkles, Trash2, Video, Vote } from 'lucide-react';
import { WelcomeAccordion } from '../welcome/WelcomeAccordion.jsx';
import { draftAfterSuggestion, userQuestionsPayload, WELCOME_MODULES } from '../welcome/welcomeQuestions.js';
import { buildProjectItems, scopePayload, switchProject } from '../projects/projectCatalog.js';
import { executedModelsText } from '../projects/testModelPresentation.js';
import { normalizeHostContext } from '../host/hostContext.js';
import { ResponseView } from '../response/ResponseBlocks.jsx';
import { ModelDebugModal } from './ModelDebugModal.jsx';
import { copyPlainText } from './clipboard.js';
import { responseToPlainText } from '../response/responseText.js';
import { flushFeedback, queueRoutingReview, readReviewSelections } from './feedbackQueue.js';
import { completedReviewRun, defaultReviewChoice, findReviewResponse, resolveReviewIndicator, reviewCandidates, soleAgentChoice, toggleReviewRun } from './reviewIndicator.js';
import { archiveThreadLocally, prependThread, threadTitleFromMessage } from './threadSidebar.js';
import { scopeEntryAction, threadLoadIsCurrent } from './agentLoadingState.js';
import {
    clearStoredThreadUlid,
    formatTimeAgo,
    getStoredThreadUlid,
    setStoredThreadUlid,
} from './agentThreadState.js';
import {
    DEV_MODE_NORMAL,
    DEV_MODE_DEBUG,
    DEV_MODE_DIAG,
    getStoredDeveloperMode,
    setStoredDeveloperMode,
} from './agentDevMode.js';
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
        const err = new Error(payload.message || 'Request failed');
        err.status = response.status;
        err.payload = payload;
        throw err;
    }
    return payload;
}

function waitingForManualModel(modelCall) {
    const stage = String(modelCall?.key || 'model');
    const label = `${stage.charAt(0).toUpperCase()}${stage.slice(1)}`;
    const modelName = modelCall?.assumed_model?.display_name || modelCall?.assumed_model?.model;
    if (modelName) {
        return `Waiting for manual ${label} result · ${modelName}…`;
    }
    return `Waiting for manual ${label} result…`;
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

function MediaTypeIcon({ outputType, size = 15 }) {
    if (outputType === 'image') return <ImageIcon size={size} aria-label="Image output" />;
    if (outputType === 'video') return <Video size={size} aria-label="Video output" />;
    return null;
}

function TestTargetPicker({ targets, value, onChange, loading }) {
    const selectedTarget = targets.find((target) => `${target.type}:${target.id}` === value) || null;

    return (
        <details className="agent-test-target-picker">
            <summary className="agent-test-target-picker__summary">
                <span>{selectedTarget?.label || (loading ? 'Loading targets…' : 'Select a Prompt or Task')}</span>
                {selectedTarget ? <MediaTypeIcon outputType={selectedTarget.output_type} /> : null}
            </summary>
            <div className="agent-test-target-picker__menu" role="listbox" aria-label="Test target">
                {targets.map((target) => {
                    const key = `${target.type}:${target.id}`;
                    return (
                        <button
                            key={key}
                            type="button"
                            role="option"
                            aria-selected={key === value}
                            className={key === value ? 'is-selected' : ''}
                            onClick={(event) => {
                                onChange(key);
                                event.currentTarget.closest('details')?.removeAttribute('open');
                            }}
                        >
                            <span>{target.label}</span>
                            <MediaTypeIcon outputType={target.output_type} />
                        </button>
                    );
                })}
                {!loading && targets.length === 0 ? <p>No test targets available.</p> : null}
            </div>
        </details>
    );
}

const I18N = {
    vi: {
        welcomeTitle: 'Trợ lý AI SEO Operations',
        welcomeDesc: (label) => `Sẵn sàng hỗ trợ kiểm tra SEO, liên kết còn thiếu, kế hoạch nội dung hoặc dữ liệu hiệu suất cho ${label}.`,
        suggestion1: 'Kiểm tra liên kết nội bộ còn thiếu',
        suggestion2: 'Gợi ý từ khóa & kế hoạch bài viết mới',
        suggestion3: 'Đánh giá hiệu suất và cơ hội tăng trưởng SEO',
        placeholder: 'Hỏi về hiệu suất SEO, từ khóa hoặc ý tưởng nội dung…',
        placeholderUnsupported: 'Chọn một website dự án để bắt đầu hỏi đáp…',
        copyPrompt: 'Sao chép',
        copied: 'Đã chép',
        noConversations: 'Chưa có cuộc trò chuyện nào cho website này.',
        noArchived: 'Chưa có cuộc trò chuyện nào được lưu trữ cho website này.',
        archivedBanner: 'Cuộc trò chuyện này đã được lưu trữ và chỉ có thể đọc.',
        startNewChat: 'Bắt đầu chat mới',
        deleteConfirm: 'Xóa hội thoại này?',
    },
    en: {
        welcomeTitle: 'SEO Operations Agent',
        welcomeDesc: (label) => `Ask about SEO audits, missing links, content projects, or performance data for ${label}.`,
        suggestion1: 'Check missing internal links',
        suggestion2: 'Suggest keywords & content plan',
        suggestion3: 'Audit SEO performance of this site',
        placeholder: 'Ask about SEO performance, keywords, or content ideas…',
        placeholderUnsupported: 'Select a site project to ask questions…',
        copyPrompt: 'Copy',
        copied: 'Copied',
        noConversations: 'No conversations for this site yet.',
        noArchived: 'No archived conversations for this site.',
        archivedBanner: 'This conversation is archived and read-only.',
        startNewChat: 'Start new chat',
        deleteConfirm: 'Delete this conversation?',
    },
};

function resolveLocale(hostContext) {
    if (hostContext?.locale) {
        const l = String(hostContext.locale).toLowerCase();
        if (l.startsWith('vi')) return 'vi';
        if (l.startsWith('en')) return 'en';
    }
    if (typeof window !== 'undefined' && window.__SEO_I18N_LOCALE__) {
        const l = String(window.__SEO_I18N_LOCALE__).toLowerCase();
        if (l.startsWith('vi')) return 'vi';
        if (l.startsWith('en')) return 'en';
    }
    if (typeof document !== 'undefined') {
        const lang = document.documentElement?.getAttribute('lang') || '';
        if (lang.toLowerCase().startsWith('vi')) return 'vi';
    }
    return 'en';
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
    draftIntakeUrl: propDraftIntakeUrl,
    testCatalogUrl: propTestCatalogUrl,
    csrf = '',
    mode = 'standalone',
    onClose = null,
    className = '',
}) {
    const hostContext = normalizeHostContext(rawHostContext);
    const locale = resolveLocale(hostContext);
    const t = I18N[locale] || I18N.en;
    const endpoints = {
        projectsUrl: propProjectsUrl || rawEndpoints?.projectsUrl || '/agent-runtime/projects',
        turnUrl: propTurnUrl || rawEndpoints?.turnUrl || '/agent-runtime/turns',
        threadsUrl: propThreadsUrl || rawEndpoints?.threadsUrl || '/agent-runtime/threads',
        copyUrl: propCopyUrl || rawEndpoints?.copyUrl || '/agent-runtime/model-input',
        modelDebugApplyUrl: propModelDebugApplyUrl || rawEndpoints?.modelDebugApplyUrl || '/agent-runtime/model-debug/apply',
        draftIntakeUrl: propDraftIntakeUrl || rawEndpoints?.draftIntakeUrl || '/agent-runtime/draft-intake',
        testCatalogUrl: propTestCatalogUrl || rawEndpoints?.testCatalogUrl || '/agent-runtime/test-catalog',
        testArticlesUrl: rawEndpoints?.testArticlesUrl || '/agent-runtime/test-articles',
        testRunUrl: rawEndpoints?.testRunUrl || '/agent-runtime/test-runs',
        welcomeQuestionsUrl: rawEndpoints?.welcomeQuestionsUrl || '/agent-runtime/welcome-questions',
        feedbackUrl: rawEndpoints?.feedbackUrl || '/agent-runtime/feedback',
    };

    const storedReviewSelections = typeof window === 'undefined' ? {} : readReviewSelections();
    const [reviewSelections, setReviewSelections] = useState(storedReviewSelections);
    const [confirmedReviews, setConfirmedReviews] = useState(storedReviewSelections);
    const [openReviewRunUlid, setOpenReviewRunUlid] = useState(null);

    useEffect(() => {
        const deliver = () => flushFeedback(endpoints.feedbackUrl, csrf).catch(() => {});
        deliver();
        const interval = window.setInterval(deliver, 90000);
        return () => window.clearInterval(interval);
    }, [endpoints.feedbackUrl, csrf]);

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
    const [viewingThreadUlid, setViewingThreadUlid] = useState(null);
    const [isViewingArchived, setIsViewingArchived] = useState(false);

    const [activeThreads, setActiveThreads] = useState([]);
    const [archivedThreads, setArchivedThreads] = useState([]);
    const [historyTab, setHistoryTab] = useState(null); // null | 'chats' | 'archived'
    const visibleHistoryTab = historyTab ?? 'chats';
    const [showHistoryMobile, setShowHistoryMobile] = useState(false);
    const [loadingThreads, setLoadingThreads] = useState(false);

    const [draft, setDraft] = useState('');
    const [welcomeModules, setWelcomeModules] = useState(WELCOME_MODULES);
    const [welcomeOpenId, setWelcomeOpenId] = useState('seo_audit');
    const [suggestionsOpen, setSuggestionsOpen] = useState(false);

    useEffect(() => {
        let cancelled = false;
        fetch(endpoints.welcomeQuestionsUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => response.ok ? response.json() : null)
            .then((payload) => {
                if (cancelled || !Array.isArray(payload?.data?.modules)) return;
                const icons = Object.fromEntries(WELCOME_MODULES.map((module) => [module.id, module.icon]));
                setWelcomeModules(payload.data.modules.map((module) => ({ ...module, icon: icons[module.id] })));
            })
            .catch(() => {});
        return () => { cancelled = true; };
    }, [endpoints.welcomeQuestionsUrl]);

    const persistWelcome = useCallback((modules) => {
        setWelcomeModules(modules);
        fetch(endpoints.welcomeQuestionsUrl, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ modules: userQuestionsPayload(modules) }),
        }).catch(() => {});
    }, [csrf, endpoints.welcomeQuestionsUrl]);

    const suggestRef = useRef(null);

    useEffect(() => {
        if (!suggestionsOpen) return undefined;
        const onPointer = (event) => {
            if (suggestRef.current && !suggestRef.current.contains(event.target)) {
                setSuggestionsOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key !== 'Escape') return;
            event.stopPropagation();
            setSuggestionsOpen(false);
        };
        document.addEventListener('pointerdown', onPointer);
        window.addEventListener('keydown', onKey, true);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            window.removeEventListener('keydown', onKey, true);
        };
    }, [suggestionsOpen]);

    const pickWelcomeQuestion = (text) => {
        setDraft((current) => draftAfterSuggestion(current, text, isViewingArchived));
        setSuggestionsOpen(false);
        if (!isViewingArchived) textareaRef.current?.focus();
    };

    const renderSuggestions = () => (
        <WelcomeAccordion
            modules={welcomeModules}
            openId={welcomeOpenId}
            onToggle={(id) => setWelcomeOpenId((current) => (current === id ? '' : id))}
            onPick={pickWelcomeQuestion}
            onChangeModules={persistWelcome}
        />
    );
    const [messages, setMessages] = useState([]);
    const [busy, setBusy] = useState(false);
    const [confirmationBusyRunUlid, setConfirmationBusyRunUlid] = useState('');
    const [copyState, setCopyState] = useState('');
    const [error, setError] = useState('');
    const [testTargets, setTestTargets] = useState([]);
    const [testCatalogLoading, setTestCatalogLoading] = useState(false);
    const [testTargetKey, setTestTargetKey] = useState('');
    const [testSiteId, setTestSiteId] = useState('');
    const [testInputSource, setTestInputSource] = useState('article');
    const [testArticleSearch, setTestArticleSearch] = useState('');
    const [testArticleId, setTestArticleId] = useState('');
    const [testArticleResults, setTestArticleResults] = useState([]);
    const [testArticlesLoading, setTestArticlesLoading] = useState(false);
    const [testArticleTitle, setTestArticleTitle] = useState('');
    const [testArticleKeyword, setTestArticleKeyword] = useState('');
    const [testRawInput, setTestRawInput] = useState('');
    const [testRunning, setTestRunning] = useState(false);
    const [testResult, setTestResult] = useState(null);
    const textareaRef = useRef(null);

    useEffect(() => {
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            if (draft) {
                textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 180)}px`;
            }
        }
    }, [draft]);

    const [developerMode, setDeveloperMode] = useState(() => getStoredDeveloperMode(hostContext.appKey));
    const [lastCopy, setLastCopy] = useState(null);

    const [debugOpen, setDebugOpen] = useState(false);
    const [debugBusy, setDebugBusy] = useState(false);
    const [debugRunUlid, setDebugRunUlid] = useState('');
    const [debugCall, setDebugCall] = useState(null);
    const [debugManualResult, setDebugManualResult] = useState('');
    const [debugParserError, setDebugParserError] = useState('');
    const [processingStatus, setProcessingStatus] = useState(null);
    const [selectedVersions, setSelectedVersions] = useState({});
    const [threadLoadingUlid, setThreadLoadingUlid] = useState('');
    const [threadLoadError, setThreadLoadError] = useState('');
    const [rerunTargetId, setRerunTargetId] = useState(null);
    const [rerunNotice, setRerunNotice] = useState('');
    const [rerunErrorByTurn, setRerunErrorByTurn] = useState(null);

    const isDebugMode = developerMode === DEV_MODE_DEBUG;
    const isDiagnostics = developerMode === DEV_MODE_DIAG;
    const isDevModeDisabled = busy || debugBusy || debugOpen || (processingStatus !== null) || Boolean(threadLoadingUlid) || Boolean(rerunTargetId);

    const updateDevMode = (newMode) => {
        setDeveloperMode(newMode);
        setStoredDeveloperMode(hostContext.appKey, newMode);
        if (newMode === DEV_MODE_DEBUG && String(debugCall?.full_prompt || '').trim() !== '') {
            setProcessingStatus(waitingForManualModel(debugCall));
            setDebugOpen(true);
            return;
        }
        if (newMode !== DEV_MODE_DEBUG) {
            setDebugOpen(false);
        }
    };

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
    const isTestMode = selected?.type === 'utility' && selected?.key === 'test';
    const siteProjects = projects.filter((project) => project.type === 'site');
    const currentScopeRef = isTestMode
        ? (testSiteId ? `site:${testSiteId}` : null)
        : (selected.ref || (selected.siteId ? `site:${selected.siteId}` : 'global'));
    const messagesRef = useRef(null);
    const threadLoadSeq = useRef(0);
    const threadAbortRef = useRef(null);
    const liveScopeRef = useRef(currentScopeRef);
    const lastScopeRef = useRef(null);
    liveScopeRef.current = currentScopeRef;
    const globalUnsupported = selected.retrieval === 'unsupported';
    const isDrawer = mode === 'drawer';
    const showSidebar = !isDrawer && (mode !== 'embedded' || !initialScope || initialScope.type === 'global');

    useEffect(() => {
        if (!isTestMode || !endpoints.testCatalogUrl || testTargets.length > 0) return;
        let cancelled = false;
        setTestCatalogLoading(true);
        fetch(endpoints.testCatalogUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) throw new Error('Could not load Test catalog.');
                return response.json();
            })
            .then((payload) => {
                if (!cancelled) setTestTargets(Array.isArray(payload?.data?.targets) ? payload.data.targets : []);
            })
            .catch((caught) => {
                if (!cancelled) setError(caught.message || 'Could not load Test catalog.');
            })
            .finally(() => {
                if (!cancelled) setTestCatalogLoading(false);
            });
        return () => { cancelled = true; };
    }, [isTestMode, endpoints.testCatalogUrl, testTargets.length]);

    useEffect(() => {
        if (!isTestMode || testInputSource !== 'article' || !testSiteId || testArticleId || !endpoints.testArticlesUrl) {
            setTestArticleResults([]);
            return undefined;
        }
        let cancelled = false;
        const timer = window.setTimeout(async () => {
            setTestArticlesLoading(true);
            try {
                const url = new URL(endpoints.testArticlesUrl, window.location.origin);
                url.searchParams.set('site_id', testSiteId);
                url.searchParams.set('q', testArticleSearch.trim());
                const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Could not search articles.');
                const payload = await response.json();
                if (!cancelled) setTestArticleResults(Array.isArray(payload?.data?.articles) ? payload.data.articles : []);
            } catch (caught) {
                if (!cancelled) setError(caught.message || 'Could not search articles.');
            } finally {
                if (!cancelled) setTestArticlesLoading(false);
            }
        }, 250);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [isTestMode, testInputSource, testSiteId, testArticleId, testArticleSearch, endpoints.testArticlesUrl]);

    // Fetch both active and archived threads list for current scope
    const fetchThreads = useCallback(async (scopeRef) => {
        if (!endpoints.threadsUrl) {
            return;
        }
        setLoadingThreads(true);
        try {
            // Fetch active threads
            const activeUrl = new URL(endpoints.threadsUrl, window.location.origin);
            activeUrl.searchParams.set('appKey', hostContext.appKey || 'seo-ops');
            activeUrl.searchParams.set('status', 'active');
            if (scopeRef) {
                activeUrl.searchParams.set('scope_ref', scopeRef);
            }
            const activeRes = await fetch(activeUrl.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (activeRes.ok) {
                const activeData = await activeRes.json();
                setActiveThreads(activeData.data || []);
            }

            // Fetch archived threads
            const archUrl = new URL(endpoints.threadsUrl, window.location.origin);
            archUrl.searchParams.set('appKey', hostContext.appKey || 'seo-ops');
            archUrl.searchParams.set('status', 'archived');
            if (scopeRef) {
                archUrl.searchParams.set('scope_ref', scopeRef);
            }
            const archRes = await fetch(archUrl.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (archRes.ok) {
                const archData = await archRes.json();
                setArchivedThreads(archData.data || []);
            }
        } catch {
            // Non-critical background fetch failure
        } finally {
            setLoadingThreads(false);
        }
    }, [endpoints.threadsUrl, hostContext.appKey]);

    const resetDebugState = useCallback(() => {
        setDebugRunUlid('');
        setDebugCall(null);
        setDebugManualResult('');
        setDebugParserError('');
        setDebugBusy(false);
        setProcessingStatus(null);
    }, []);

    const threadLoadCurrent = (seq, scopeRef) => threadLoadIsCurrent(seq, threadLoadSeq.current, scopeRef, liveScopeRef.current);

    // Load thread messages without triggering a model turn
    const loadThread = useCallback(async (ulid, scopeRef, options = {}) => {
        if (!endpoints.threadsUrl || !ulid) {
            return;
        }
        threadAbortRef.current?.abort();
        const controller = new AbortController();
        threadAbortRef.current = controller;
        const seq = ++threadLoadSeq.current;
        const archivedHint = Boolean(options.archived);
        setViewingThreadUlid(ulid);
        setIsViewingArchived(archivedHint);
        if (!archivedHint) {
            setActiveThreadUlid(ulid);
        }
        setMessages([]);
        setThreadLoadingUlid(ulid);
        setThreadLoadError('');
        setError('');
        setOpenReviewRunUlid(null);
        setRerunTargetId(null);
        setRerunNotice('');
        setRerunErrorByTurn(null);
        try {
            const res = await fetch(`${endpoints.threadsUrl}/${ulid}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!res.ok) {
                throw new Error('Could not load conversation.');
            }
            const payload = await res.json();
            const threadData = payload?.data || {};
            const rawMessages = threadData.messages || [];
            const mapped = rawMessages.map((msg) => {
                if (msg.role === 'user') {
                    return {
                        id: msg.id,
                        role: 'user',
                        content: msg.content,
                    };
                }
                const responsePayload = msg.response_payload || {};
                const retrievalSummary = msg.run?.retrieval_summary || {};
                const modelDiag = retrievalSummary.model_diagnostics || responsePayload.model_diagnostics || null;
                const answerDiag = retrievalSummary.answer_diagnostics || responsePayload.answer_diagnostics || null;
                return {
                    id: msg.id,
                    role: 'assistant',
                    originUserMessageId: msg.run?.user_message_id || null,
                    content: msg.content,
                    response: {
                        message: msg.content,
                        blocks: responsePayload.blocks || [],
                        actions: responsePayload.actions || [],
                        sources: responsePayload.sources || [],
                        model_diagnostics: modelDiag,
                        answer_diagnostics: answerDiag,
                        execution: retrievalSummary.model_diagnostics?.execution || responsePayload.execution || null,
                        run_ulid: msg.run?.ulid || null,
                    },
                };
            });
            if (!threadLoadCurrent(seq, scopeRef)) {
                return;
            }
            setMessages(mapped);
            setViewingThreadUlid(ulid);
            const threadIsArchived = threadData.status === 'archived';
            setIsViewingArchived(threadIsArchived);
            if (!threadIsArchived) {
                setActiveThreadUlid(ulid);
                setStoredThreadUlid(hostContext.appKey, scopeRef, ulid);
            }

            const pending = threadData.pending_model_call || null;
            const prompt = String(pending?.model_call?.full_prompt || '').trim();
            const strandedUlid = threadData.stranded_model_run?.run_ulid || (pending && prompt === '' ? pending.run_ulid : '');
            if (strandedUlid) {
                resetDebugState();
                setDebugOpen(false);
                if (!threadLoadCurrent(seq, scopeRef)) {
                    return;
                }
                await finishStrandedRun(strandedUlid, () => threadLoadCurrent(seq, scopeRef));
            } else if (pending && prompt !== '') {
                setDebugRunUlid(pending.run_ulid || '');
                setDebugCall(pending.model_call || null);
                setDebugManualResult('');
                setDebugParserError('');
                if (getStoredDeveloperMode(hostContext.appKey) === DEV_MODE_DEBUG) {
                    setProcessingStatus(waitingForManualModel(pending.model_call));
                    setDebugOpen(true);
                } else {
                    setProcessingStatus(null);
                    setDebugOpen(false);
                }
            } else {
                resetDebugState();
                setDebugOpen(false);
            }
        } catch (err) {
            if (err?.name === 'AbortError' || !threadLoadCurrent(seq, scopeRef)) {
                return;
            }
            setMessages([]);
            setThreadLoadError(err.message || 'Failed to load thread.');
        } finally {
            if (threadLoadCurrent(seq, scopeRef)) {
                setThreadLoadingUlid('');
            }
        }
    }, [endpoints.threadsUrl, endpoints.modelDebugApplyUrl, csrf, hostContext.appKey, resetDebugState]);

    // New conversation action
    const onNewConversation = useCallback(() => {
        setActiveThreadUlid(null);
        setViewingThreadUlid(null);
        setIsViewingArchived(false);
        setMessages([]);
        setDraft('');
        setError('');
        setThreadLoadingUlid('');
        setThreadLoadError('');
        setRerunTargetId(null);
        setRerunNotice('');
        setRerunErrorByTurn(null);
        clearStoredThreadUlid(hostContext.appKey, currentScopeRef);
        resetDebugState();
        setDebugOpen(false);
        setOpenReviewRunUlid(null);
    }, [hostContext.appKey, currentScopeRef, resetDebugState]);

    // Archive conversation action
    const onArchiveThread = useCallback(async (ulid) => {
        if (!ulid || !endpoints.threadsUrl || busy || debugBusy) {
            return;
        }
        try {
            const payload = await postJson(`${endpoints.threadsUrl}/${ulid}/archive`, csrf, {});
            if (payload?.data?.status === 'archived') {
                const next = archiveThreadLocally(activeThreads, archivedThreads, ulid);
                setActiveThreads(next.active);
                setArchivedThreads(next.archived);
                if (viewingThreadUlid === ulid || activeThreadUlid === ulid) {
                    onNewConversation();
                }
            }
        } catch (err) {
            setError(err.message || 'Could not archive conversation.');
        }
    }, [endpoints.threadsUrl, csrf, busy, debugBusy, viewingThreadUlid, activeThreadUlid, onNewConversation, activeThreads, archivedThreads]);

    // Delete conversation action
    const onDeleteThread = useCallback(async (ulid) => {
        if (!ulid || !endpoints.threadsUrl) {
            return;
        }
        if (!window.confirm(t.deleteConfirm)) {
            return;
        }
        const activeSnapshot = activeThreads;
        const archivedSnapshot = archivedThreads;
        setActiveThreads((current) => current.filter((thread) => thread.ulid !== ulid));
        setArchivedThreads((current) => current.filter((thread) => thread.ulid !== ulid));
        try {
            const res = await fetch(`${endpoints.threadsUrl}/${ulid}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                throw new Error(errData.message || 'Could not delete conversation.');
            }
            if (viewingThreadUlid === ulid || activeThreadUlid === ulid) {
                onNewConversation();
            }
        } catch (err) {
            setActiveThreads(activeSnapshot);
            setArchivedThreads(archivedSnapshot);
            setError(err.message || 'Could not delete conversation.');
        }
    }, [endpoints.threadsUrl, csrf, t.deleteConfirm, activeThreads, archivedThreads, viewingThreadUlid, activeThreadUlid, onNewConversation]);

    async function onApplyDebugResult() {
        if (debugBusy || !debugManualResult.trim() || !debugRunUlid) {
            return;
        }
        setDebugBusy(true);
        setDebugParserError('');
        setDebugOpen(false);
        if (rerunTargetId) {
            setRerunNotice('Thinking…');
        } else {
            setProcessingStatus('Thinking…');
        }
        try {
            const payload = await postJson(endpoints.modelDebugApplyUrl, csrf, {
                run_ulid: debugRunUlid,
                manual_result: debugManualResult,
            });
            const data = payload?.data || {};
            if (data.status === 'paused') {
                setDebugCall(data.model_call || null);
                setDebugManualResult('');
                if (rerunTargetId) {
                    setRerunNotice(waitingForManualModel(data.model_call));
                    setProcessingStatus(null);
                } else {
                    setProcessingStatus(waitingForManualModel(data.model_call));
                }
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
            setRerunTargetId(null);
            setRerunNotice('');
            resetDebugState();
            revealCompletedReview(data);
        } catch (caught) {
            const payload = caught.payload;
            if (caught.status === 422 && payload?.data?.status === 'paused') {
                setDebugParserError(payload.validation_error || payload.message || 'Answer result rejected');
                if (payload.data.model_call) {
                    setDebugCall(payload.data.model_call);
                }
                if (rerunTargetId) {
                    setRerunNotice(waitingForManualModel(payload.data.model_call));
                    setProcessingStatus(null);
                } else {
                    setProcessingStatus(waitingForManualModel(payload.data.model_call));
                }
                setDebugOpen(true);
            } else {
                setDebugParserError(caught.message);
                setProcessingStatus(null);
                setDebugOpen(true);
                if (rerunTargetId) {
                    setRerunErrorByTurn({ id: rerunTargetId, message: caught.message || 'Could not rerun message.' });
                    setRerunTargetId(null);
                    setRerunNotice('');
                }
            }
        } finally {
            setDebugBusy(false);
        }
    }

    async function finishStrandedRun(runUlid, stillCurrent = null) {
        if (!runUlid || !endpoints.modelDebugApplyUrl) {
            return;
        }
        const payload = await postJson(endpoints.modelDebugApplyUrl, csrf, {
            run_ulid: runUlid,
            recover_stranded: true,
        });
        if (stillCurrent && !stillCurrent()) {
            return;
        }
        const data = payload?.data || {};
        setMessages((current) => [...current, {
            role: 'assistant',
            id: data.assistant_message_id,
            originUserMessageId: data.user_message_id,
            content: data.message || '',
            response: data,
        }]);
        if (data.user_message_id) {
            setSelectedVersions((current) => ({ ...current, [data.user_message_id]: Number.MAX_SAFE_INTEGER }));
        }
    }

    // Scope change / initial mount effect: sync threads list and restore stored active thread
    useEffect(() => {
        if (!currentScopeRef) {
            return;
        }
        const storedUlid = getStoredThreadUlid(hostContext.appKey, currentScopeRef);
        const action = scopeEntryAction(lastScopeRef.current, currentScopeRef, storedUlid);
        if (action === 'ignore') {
            return;
        }
        lastScopeRef.current = currentScopeRef;
        threadAbortRef.current?.abort();
        threadLoadSeq.current += 1;
        fetchThreads(currentScopeRef);
        setThreadLoadingUlid('');
        setThreadLoadError('');
        setRerunTargetId(null);
        setRerunNotice('');
        setRerunErrorByTurn(null);
        setOpenReviewRunUlid(null);
        resetDebugState();
        setDebugOpen(false);
        if (action === 'restore') {
            loadThread(storedUlid, currentScopeRef);
            return;
        }
        setActiveThreadUlid(null);
        setViewingThreadUlid(null);
        setIsViewingArchived(false);
        setMessages([]);
    }, [currentScopeRef, fetchThreads, loadThread, hostContext.appKey, resetDebugState]);

    async function copyText(text) {
        await copyPlainText(text);
        setCopyState('Copied');
        window.setTimeout(() => setCopyState(''), 1200);
    }

    async function onCopy() {
        if (busy || draft.trim() === '' || isViewingArchived) {
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
                message: draft.trim(),
                scope: scopePayload(selected),
                hostContext,
                history: messages
                    .filter((msg) => msg.role === 'user' || msg.role === 'assistant')
                    .map((msg) => ({ role: msg.role, content: msg.content })),
            });
            const textToCopy = payload?.data?.copy?.answer || payload?.data?.copy?.routing || '';
            await copyText(textToCopy);
            setLastCopy(payload?.data?.copy || null);
        } catch (caught) {
            setError(caught.message || 'Could not prepare model input.');
        } finally {
            setBusy(false);
        }
    }

    async function onSend() {
        if (busy || draft.trim() === '' || isViewingArchived) {
            return;
        }
        if (globalUnsupported) {
            setError('All Sites retrieval is unsupported until a global SEO Access API is available. Select a site project.');
            return;
        }

        const userMessageText = draft.trim();
        setDraft('');
        setError('');
        setBusy(true);
        setProcessingStatus('Thinking…');
        setOpenReviewRunUlid(null);

        const tempUserId = `temp-${Date.now()}`;
        setMessages((current) => [...current, { role: 'user', id: tempUserId, content: userMessageText }]);

        try {
            const sendUrl = activeThreadUlid
                ? `${endpoints.threadsUrl}/${activeThreadUlid}/turns`
                : endpoints.turnUrl;

            const payload = await postJson(sendUrl, csrf, {
                message: userMessageText,
                scope: scopePayload(selected),
                hostContext,
                thread_ulid: activeThreadUlid,
                debug_mode: isDebugMode,
                diagnostics: isDiagnostics,
            });

            const data = payload?.data || {};
            const returnedUlid = data.thread_ulid;
            if (returnedUlid && !activeThreadUlid) {
                setActiveThreadUlid(returnedUlid);
                setViewingThreadUlid(returnedUlid);
                setStoredThreadUlid(hostContext.appKey, currentScopeRef, returnedUlid);
                setActiveThreads((current) => prependThread(current, {
                    ulid: returnedUlid,
                    title: data.title || threadTitleFromMessage(userMessageText),
                    last_message_at: new Date().toISOString(),
                    status: 'active',
                }));
            }

            if (data.user_message_id) {
                setMessages((current) =>
                    current.map((msg) =>
                        msg.id === tempUserId ? { ...msg, id: data.user_message_id } : msg
                    )
                );
            }

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
                originUserMessageId: data.user_message_id || tempUserId,
                content: data.message || '',
                response: data,
            }]);

            setSelectedVersions((current) => ({
                ...current,
                [data.user_message_id || tempUserId]: Number.MAX_SAFE_INTEGER,
            }));
            revealCompletedReview(data);
            setProcessingStatus(null);
        } catch (caught) {
            setError(caught.message);
            setProcessingStatus(null);
        } finally {
            setBusy(false);
        }
    }

    async function onConfirmationAction(action) {
        const runUlid = String(action?.run_ulid || '');
        const decision = action?.action === 'confirm'
            ? 'confirm'
            : action?.action === 'reject'
                ? 'reject'
                : action?.type === 'gsc_continuation' && action?.action === 'cached'
                    ? 'gsc-cached'
                    : '';
        if (!runUlid || !decision || busy || confirmationBusyRunUlid) return;

        setBusy(true);
        setConfirmationBusyRunUlid(runUlid);
        setError('');
        setProcessingStatus(decision === 'confirm' ? 'Đang xác nhận…' : decision === 'gsc-cached' ? 'Đang dùng dữ liệu GSC đã đồng bộ…' : 'Đang từ chối…');
        try {
            const payload = await postJson(`/agent-runtime/runs/${encodeURIComponent(runUlid)}/${decision}`, csrf, {});
            const data = payload?.data || {};
            setMessages((current) => current.flatMap((message) => {
                if (message?.response?.run_ulid !== runUlid) return message;
                if (data.status === 'paused') {
                    return [];
                }
                return {
                    ...message,
                    id: data.assistant_message_id || message.id,
                    content: data.message || '',
                    response: data,
                };
            }));

            if (data.status === 'paused') {
                setDebugRunUlid(data.run_ulid || '');
                setDebugCall(data.model_call || null);
                setDebugManualResult('');
                setDebugParserError('');
                setDebugOpen(true);
                setProcessingStatus(waitingForManualModel(data.model_call));
            } else {
                setProcessingStatus(null);
                revealCompletedReview(data);
            }
        } catch (caught) {
            setError(caught.message || 'Could not resolve confirmation.');
            setProcessingStatus(null);
        } finally {
            setConfirmationBusyRunUlid('');
            setBusy(false);
        }
    }

    async function onRunTest(event) {
        event.preventDefault();
        if (testRunning) return;
        const target = testTargets.find((item) => `${item.type}:${item.id}` === testTargetKey);
        if (!target || !testSiteId) {
            setError('Select a target and concrete site.');
            return;
        }
        setTestRunning(true);
        setTestResult(null);
        setError('');
        try {
            const payload = await postJson(endpoints.testRunUrl, csrf, {
                target_type: target.type,
                target_id: target.id,
                site_id: Number(testSiteId),
                input_source: testInputSource,
                article_id: testArticleId ? Number(testArticleId) : null,
                title: testArticleTitle.trim() || testArticleSearch.trim(),
                keyword: testArticleKeyword.trim(),
                raw_input: testRawInput,
                app_key: hostContext.appKey || 'seo-ops',
            });
            const result = payload?.data || null;
            setTestResult(result);
            if (result?.thread_ulid) {
                setActiveThreads((current) => prependThread(current, {
                    ulid: result.thread_ulid,
                    title: result.target_label ? `Test · ${result.target_label}` : 'Test',
                    last_message_at: new Date().toISOString(),
                    status: 'active',
                }));
            }
        } catch (caught) {
            setError(caught.message || 'Test execution failed.');
        } finally {
            setTestRunning(false);
        }
    }

    function onComposerSubmit(event) {
        event.preventDefault();
        void onSend();
    }

    useEffect(() => {
        if (!rerunTargetId || !messagesRef.current) {
            return;
        }
        const container = messagesRef.current;
        const node = container.querySelector('[data-rerun-placeholder="true"]');
        if (!node) {
            return;
        }
        const delta = node.getBoundingClientRect().top - container.getBoundingClientRect().top;
        container.scrollTop += delta - 12;
    }, [rerunTargetId]);

    async function onRerun(userMessageId) {
        if (busy || rerunTargetId || !activeThreadUlid || !userMessageId || isViewingArchived) {
            return;
        }
        setRerunTargetId(userMessageId);
        setRerunNotice('Thinking…');
        setRerunErrorByTurn(null);
        setError('');
        setOpenReviewRunUlid(null);

        try {
            const rerunUrl = `${endpoints.threadsUrl}/${activeThreadUlid}/messages/${userMessageId}/rerun`;
            const payload = await postJson(rerunUrl, csrf, {
                debug_mode: isDebugMode,
                diagnostics: isDiagnostics,
            });

            const data = payload?.data || {};

            if (data.status === 'paused') {
                setDebugRunUlid(data.run_ulid || '');
                setDebugCall(data.model_call || null);
                setDebugManualResult('');
                setDebugParserError('');
                setRerunNotice(waitingForManualModel(data.model_call));
                setDebugOpen(true);
                return;
            }

            setMessages((current) => [...current, {
                role: 'assistant',
                id: data.assistant_message_id,
                originUserMessageId: userMessageId,
                content: data.message || '',
                response: data,
            }]);

            setSelectedVersions((current) => ({
                ...current,
                [userMessageId]: Number.MAX_SAFE_INTEGER,
            }));
            setRerunTargetId(null);
            setRerunNotice('');
            revealCompletedReview(data);
        } catch (caught) {
            setRerunErrorByTurn({ id: userMessageId, message: caught.message || 'Could not rerun message.' });
            setRerunTargetId(null);
            setRerunNotice('');
        }
    }

    const conversationTurns = groupConversation(messages);

    function stageReviewChoice(response, runUlid) {
        setReviewSelections((current) => {
            const next = defaultReviewChoice(current[runUlid], reviewCandidates(response));
            if (!next || current[runUlid] === next) return current;
            return { ...current, [runUlid]: next };
        });
    }

    function revealCompletedReview(response) {
        const runUlid = completedReviewRun(response);
        if (!runUlid) return;
        setOpenReviewRunUlid(runUlid);
        stageReviewChoice(response, runUlid);
    }

    function openRoutingReview(response) {
        const runUlid = String(response?.run_ulid || '');
        if (!runUlid || reviewCandidates(response).length === 0) return;
        const nextOpen = toggleReviewRun(openReviewRunUlid, runUlid);
        setOpenReviewRunUlid(nextOpen);
        if (nextOpen !== runUlid) return;
        stageReviewChoice(response, runUlid);
    }

    function confirmRoutingReview(runUlid) {
        const choice = reviewSelections[runUlid] || '';
        if (!runUlid || !choice) return;
        const preferred = choice === '__none__' ? null : choice;
        queueRoutingReview(runUlid, preferred);
        setConfirmedReviews((current) => ({ ...current, [runUlid]: choice }));
        setOpenReviewRunUlid((current) => (current === runUlid ? null : current));
    }

    const openReviewResponse = findReviewResponse(messages, openReviewRunUlid);
    const openReviewCandidates = reviewCandidates(openReviewResponse);
    const showRoutingReview = !isTestMode && openReviewCandidates.length > 0;
    const openReviewChoice = reviewSelections[openReviewRunUlid] || '';
    const openAgentChoice = soleAgentChoice(openReviewCandidates);
    const openConfirmedChoice = confirmedReviews[openReviewRunUlid] || '';

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
                    <div className="agent-suggest-anchor" ref={suggestRef}>
                        <div className="agent-drawer-header__title">
                            <Sparkles size={18} className="agent-sparkles-icon" />
                            <span>AI Agent</span>
                            <button
                                type="button"
                                className="agent-suggest__trigger"
                                aria-expanded={suggestionsOpen}
                                aria-controls="agent-suggest-panel"
                                onClick={() => setSuggestionsOpen((open) => !open)}
                            >
                                Gợi ý ▾
                            </button>
                        </div>
                        {suggestionsOpen ? (
                            <div id="agent-suggest-panel" className="agent-suggest__panel" role="region" aria-label="Gợi ý">
                                {renderSuggestions()}
                            </div>
                        ) : null}
                    </div>
                    <div className="agent-drawer-header__controls">
                        <select
                            className="agent-project-select"
                            value={selected.key}
                            onChange={(event) => {
                                const nextKey = event.target.value;
                                const next = switchProject(projects, nextKey);
                                setSelectedKey(next.key);
                                setError('');
                                setLastCopy(null);
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

                        <div className="agent-dev-tabs" role="tablist" aria-label="Developer mode">
                            <span className="agent-dev-label">Dev</span>
                            <button
                                type="button"
                                role="tab"
                                id="drawer-dev-tab-normal"
                                aria-selected={developerMode === 'normal'}
                                className={`agent-dev-tab agent-dev-tab--normal ${developerMode === 'normal' ? 'is-active' : ''}`}
                                onClick={() => updateDevMode('normal')}
                                disabled={isDevModeDisabled}
                            >
                                Normal
                            </button>
                            <button
                                type="button"
                                role="tab"
                                id="drawer-dev-tab-debug"
                                aria-selected={developerMode === 'debug'}
                                className={`agent-dev-tab agent-dev-tab--debug ${developerMode === 'debug' ? 'is-active' : ''}`}
                                onClick={() => updateDevMode('debug')}
                                disabled={isDevModeDisabled}
                            >
                                Debug
                            </button>
                            <button
                                type="button"
                                role="tab"
                                id="drawer-dev-tab-diag"
                                aria-selected={developerMode === 'diag'}
                                className={`agent-dev-tab agent-dev-tab--diag ${developerMode === 'diag' ? 'is-active' : ''}`}
                                onClick={() => updateDevMode('diag')}
                                disabled={isDevModeDisabled}
                            >
                                Diag
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}

            {showSidebar ? (
                <aside className="agent-projects">
                    <p className="agent-kicker">Project scope</p>
                    <ul>
                        {projects.map((project) => (
                            <li key={project.key}>
                                <button
                                    type="button"
                                    className={project.key === selectedKey ? 'is-active' : ''}
                                    onClick={() => setSelectedKey(project.key)}
                                >
                                    <span>{project.label}</span>
                                    {project.retrieval === 'unsupported' ? (
                                        <small>Retrieval disabled</small>
                                    ) : null}
                                </button>
                            </li>
                        ))}
                    </ul>
                </aside>
            ) : null}

            <div className={`agent-main-layout ${showHistoryMobile ? 'agent-main-layout--history-open' : ''}`}>
                <div className="agent-workspace">
                    {!isDrawer ? (
                        <header className="agent-workspace__header">
                            <div>
                                <p className="agent-kicker">Scope: {selected.label}</p>
                                <h1>SEO Operations Agent</h1>
                            </div>
                            <div className="agent-workspace__controls">
                                <div className="agent-dev-tabs" role="tablist" aria-label="Developer mode">
                                    <span className="agent-dev-label">Dev</span>
                                    <button
                                        type="button"
                                        role="tab"
                                        id="header-dev-tab-normal"
                                        aria-selected={developerMode === 'normal'}
                                        className={`agent-dev-tab agent-dev-tab--normal ${developerMode === 'normal' ? 'is-active' : ''}`}
                                        onClick={() => updateDevMode('normal')}
                                        disabled={isDevModeDisabled}
                                    >
                                        Normal
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        id="header-dev-tab-debug"
                                        aria-selected={developerMode === 'debug'}
                                        className={`agent-dev-tab agent-dev-tab--debug ${developerMode === 'debug' ? 'is-active' : ''}`}
                                        onClick={() => updateDevMode('debug')}
                                        disabled={isDevModeDisabled}
                                    >
                                        Debug
                                    </button>
                                    <button
                                        type="button"
                                        role="tab"
                                        id="header-dev-tab-diag"
                                        aria-selected={developerMode === 'diag'}
                                        className={`agent-dev-tab agent-dev-tab--diag ${developerMode === 'diag' ? 'is-active' : ''}`}
                                        onClick={() => updateDevMode('diag')}
                                        disabled={isDevModeDisabled}
                                    >
                                        Diag
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    className="agent-history-btn"
                                    onClick={() => setShowHistoryMobile((current) => !current)}
                                    title="Toggle History"
                                    aria-label="Toggle History"
                                >
                                    <History size={16} />
                                </button>
                            </div>
                        </header>
                    ) : null}

                    <div className="agent-messages" role="log" aria-live="polite" ref={messagesRef}>
                        {isTestMode ? (
                            <form className="agent-test-workspace" aria-label="Unified Test workspace" onSubmit={onRunTest}>
                                <div>
                                    <p className="agent-kicker">Unified Test</p>
                                    <h2>Test</h2>
                                    <p>Use one input shell for standalone Prompts and complete Tasks.</p>
                                </div>

                                <div className="agent-test-field">
                                    <label>Target</label>
                                    <TestTargetPicker
                                        targets={testTargets}
                                        value={testTargetKey}
                                        onChange={setTestTargetKey}
                                        loading={testCatalogLoading}
                                    />
                                </div>

                                <div className="agent-test-field">
                                    <label htmlFor="agent-test-site">Site</label>
                                    <select id="agent-test-site" value={testSiteId} onChange={(event) => { setTestSiteId(event.target.value); setTestArticleId(''); setTestArticleSearch(''); }}>
                                        <option value="">Select a concrete site</option>
                                        {siteProjects.map((site) => (
                                            <option key={site.key} value={site.siteId}>{site.label}</option>
                                        ))}
                                    </select>
                                </div>

                                <div className="agent-test-field">
                                    <label htmlFor="agent-test-input-source">Input source</label>
                                    <select id="agent-test-input-source" value={testInputSource} onChange={(event) => setTestInputSource(event.target.value)}>
                                        <option value="article">Article</option>
                                        <option value="raw">Raw input</option>
                                    </select>
                                </div>

                                {testInputSource === 'article' ? (
                                    <div className="agent-test-article-fallback">
                                        <div className="agent-test-field agent-test-field--full">
                                            <label htmlFor="agent-test-article-search">Article picker/search</label>
                                            <input id="agent-test-article-search" value={testArticleSearch} onChange={(event) => { setTestArticleSearch(event.target.value); setTestArticleId(''); }} placeholder="Search article by title…" />
                                            {testArticlesLoading ? <span className="agent-test-article-status">Searching…</span> : null}
                                            {testArticleResults.length > 0 ? (
                                                <div className="agent-test-article-results" role="listbox" aria-label="Article results">
                                                    {testArticleResults.map((article) => (
                                                        <button
                                                            type="button"
                                                            role="option"
                                                            aria-selected={String(article.id) === String(testArticleId)}
                                                            className={String(article.id) === String(testArticleId) ? 'is-selected' : ''}
                                                            key={article.id}
                                                            onClick={() => { setTestArticleId(String(article.id)); setTestArticleSearch(article.title); setTestArticleResults([]); }}
                                                        >
                                                            {article.title}
                                                        </button>
                                                    ))}
                                                </div>
                                            ) : null}
                                            {testArticleId ? (
                                                <div className="agent-test-selected-article">
                                                    <span>Selected article #{testArticleId}: {testArticleSearch}</span>
                                                    <button type="button" onClick={() => { setTestArticleId(''); setTestArticleSearch(''); }}>Clear</button>
                                                </div>
                                            ) : null}
                                        </div>
                                        <div className="agent-test-field">
                                            <label htmlFor="agent-test-article-title">Article title</label>
                                            <input id="agent-test-article-title" value={testArticleTitle} onChange={(event) => setTestArticleTitle(event.target.value)} placeholder="Article title" />
                                        </div>
                                        <div className="agent-test-field">
                                            <label htmlFor="agent-test-article-keyword">Keyword</label>
                                            <input id="agent-test-article-keyword" value={testArticleKeyword} onChange={(event) => setTestArticleKeyword(event.target.value)} placeholder="Primary keyword" />
                                        </div>
                                    </div>
                                ) : (
                                    <div className="agent-test-field">
                                        <label htmlFor="agent-test-raw-input">Raw input</label>
                                        <textarea id="agent-test-raw-input" rows={7} value={testRawInput} onChange={(event) => setTestRawInput(event.target.value)} placeholder="Paste test input…" />
                                    </div>
                                )}

                                <button type="submit" className="agent-test-run" disabled={testRunning || !testTargetKey || !testSiteId}>
                                    {testRunning ? <><Loader2 size={15} className="agent-spinner" /> Running…</> : 'Run'}
                                </button>

                                {testResult ? (
                                    <section className="agent-test-result" aria-live="polite">
                                        <div className="agent-test-result__heading">
                                            <MediaTypeIcon outputType={testResult.output_type} />
                                            <strong>{testResult.target_label}</strong>
                                            <span>{testResult.status}</span>
                                        </div>
                                        {executedModelsText(testResult.models) ? (
                                            <p className="agent-test-result__models">{executedModelsText(testResult.models)}</p>
                                        ) : null}
                                        <p className="agent-test-result__summary">{testResult.context_summary}</p>
                                        {testResult.output ? <pre>{testResult.output}</pre> : null}
                                        {testResult.error ? <p className="agent-test-result__error">{testResult.error}</p> : null}
                                        {(testResult.media || []).map((media, index) => media.type === 'image' ? (
                                            <img key={`${media.url}-${index}`} src={media.url} alt={`${testResult.target_label} result`} loading="lazy" />
                                        ) : (
                                            <video key={`${media.url}-${index}`} src={media.url} controls preload="metadata" />
                                        ))}
                                    </section>
                                ) : null}
                            </form>
                        ) : null}

                        {!isTestMode && conversationTurns.length === 0 && !threadLoadingUlid && !threadLoadError ? (
                            <div className="agent-welcome agent-empty">
                                <div className="agent-welcome__icon-wrap">
                                    <Sparkles size={26} className="agent-sparkles-icon agent-welcome__icon" />
                                </div>
                                <h2 className="agent-welcome__title">
                                    {t.welcomeTitle}
                                </h2>
                                <p className="agent-welcome__desc">
                                    {t.welcomeDesc(selected.label)}
                                </p>
                                {renderSuggestions()}
                            </div>
                        ) : null}

                        {threadLoadingUlid ? (
                            <div className="agent-thread-skeleton" aria-busy="true" aria-label="Đang tải hội thoại">
                                <span className="agent-thread-skeleton__user" />
                                <span className="agent-thread-skeleton__assistant" />
                            </div>
                        ) : null}
                        {threadLoadError ? (
                            <div className="agent-error-banner" role="alert">
                                <span>{threadLoadError}</span>
                                <button type="button" onClick={() => loadThread(viewingThreadUlid, currentScopeRef, { archived: isViewingArchived })}>Thử lại</button>
                            </div>
                        ) : null}

                        {!isTestMode ? conversationTurns.map((turn) => {
                            const requestedIndex = selectedVersions[turn.id];
                            const versionIndex = Math.min(
                                requestedIndex ?? (turn.versions.length > 0 ? turn.versions.length - 1 : 0),
                                Math.max(0, turn.versions.length - 1)
                            );
                            const version = turn.versions[versionIndex];
                            const versionCandidates = reviewCandidates(version?.response);
                            const versionRunUlid = version?.response?.run_ulid || '';
                            const confirmedChoice = confirmedReviews[versionRunUlid] || '';
                            const reviewMark = resolveReviewIndicator(version?.response, confirmedChoice);
                            const panelOpen = openReviewRunUlid === versionRunUlid && versionCandidates.length > 0;
                            const rerunning = rerunTargetId != null && String(turn.id) === String(rerunTargetId);

                            return (
                                <div key={turn.id} className="agent-turn">
                                    <article className="agent-message is-user">
                                        <div className="agent-message__body">
                                            <p>{turn.content}</p>
                                        </div>
                                    </article>

                                    {rerunning ? (
                                        <article className="agent-message is-assistant agent-processing-status" data-rerun-placeholder="true" role="status">
                                            <div className="agent-status-indicator">
                                                <Loader2 size={16} className="agent-spinner" />
                                                <span>{rerunNotice || 'Thinking…'}</span>
                                            </div>
                                        </article>
                                    ) : version ? (
                                        <article className="agent-message is-assistant">
                                            <div className="agent-message__header">
                                                <span className="agent-message__role">AI Assistant</span>
                                            </div>
                                            <div className="agent-message__body">
                                                <ResponseView
                                                    response={version.response}
                                                    onAction={onConfirmationAction}
                                                    actionsBusy={confirmationBusyRunUlid === version?.response?.run_ulid}
                                                    draftIntakeUrl={endpoints.draftIntakeUrl}
                                                    csrf={csrf}
                                                    siteId={selected?.siteId || null}
                                                    selectionKey={version.id || version.response?.run_ulid || turn.id}
                                                    debug={isDebugMode}
                                                />
                                                {(() => {
                                                    const modelDiag = version?.response?.model_diagnostics;
                                                    const answerDiag = version?.response?.answer_diagnostics;
                                                    const decisionDiag = modelDiag?.decision;
                                                    const finalAnswerDiag = modelDiag?.answer || answerDiag;
                                                    const hasDiagnostics = Boolean(decisionDiag || finalAnswerDiag);

                                                    if (!hasDiagnostics) return null;

                                                    return (
                                                        <details className="agent-diagnostics-disclosure">
                                                            <summary>Diagnostics</summary>
                                                            {decisionDiag && (
                                                                <div className="agent-diagnostics-stage">
                                                                    <h4>Decision</h4>
                                                                    <pre>{JSON.stringify(decisionDiag, null, 2)}</pre>
                                                                </div>
                                                            )}
                                                            {finalAnswerDiag && (
                                                                <div className="agent-diagnostics-stage">
                                                                    <h4>Answer</h4>
                                                                    <pre>{JSON.stringify(finalAnswerDiag, null, 2)}</pre>
                                                                </div>
                                                            )}
                                                        </details>
                                                    );
                                                })()}
                                            </div>
                                            <div className="agent-message__actions">
                                                {turn.versions.length > 1 ? (
                                                    <span className="agent-version-nav">
                                                        <button
                                                            type="button"
                                                            disabled={versionIndex <= 0}
                                                            onClick={() =>
                                                                setSelectedVersions((current) => ({
                                                                    ...current,
                                                                    [turn.id]: versionIndex - 1,
                                                                }))
                                                            }
                                                            aria-label="Previous response version"
                                                        >
                                                            ‹
                                                        </button>
                                                        <span>
                                                            {versionIndex + 1} / {turn.versions.length}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            disabled={versionIndex >= turn.versions.length - 1}
                                                            onClick={() =>
                                                                setSelectedVersions((current) => ({
                                                                    ...current,
                                                                    [turn.id]: versionIndex + 1,
                                                                }))
                                                            }
                                                            aria-label="Next response version"
                                                        >
                                                            ›
                                                        </button>
                                                    </span>
                                                ) : null}

                                                {versionCandidates.length > 0 ? (
                                                    <button
                                                        type="button"
                                                        className={`agent-message-action-btn agent-vote-btn${reviewMark ? ' is-active' : ''}${panelOpen ? ' is-open' : ''}`}
                                                        aria-expanded={panelOpen}
                                                        title={reviewMark?.title || 'Đánh giá cách hiểu câu hỏi'}
                                                        aria-label={reviewMark?.title || 'Đánh giá cách hiểu câu hỏi'}
                                                        onClick={() => openRoutingReview(version.response)}
                                                    >
                                                        <Vote size={13} />
                                                    </button>
                                                ) : null}
                                                <button
                                                    type="button"
                                                    className="agent-message-action-btn"
                                                    onClick={() => copyText(responseToPlainText(version.response))}
                                                    title="Copy answer" aria-label="Copy answer"><Copy size={13} /></button>
                                                {!isViewingArchived && (
                                                    <button
                                                        type="button"
                                                        className="agent-message-action-btn"
                                                        onClick={() => onRerun(turn.id)}
                                                        disabled={busy || Boolean(rerunTargetId) || isDevModeDisabled}
                                                        title="Rerun" aria-label="Rerun"><RotateCcw size={13} /></button>
                                                )}
                                            </div>
                                        </article>
                                    ) : null}
                                    {rerunErrorByTurn?.id === turn.id ? (
                                        <div className="agent-error-banner" role="alert">
                                            <span>{rerunErrorByTurn.message}</span>
                                            <button type="button" onClick={() => onRerun(turn.id)}>Thử lại</button>
                                        </div>
                                    ) : null}
                                </div>
                            );
                        }) : null}

                        {!isTestMode && processingStatus ? (
                            <article className="agent-message is-assistant agent-processing-status" role="status">
                                <div className="agent-status-indicator">
                                    <Loader2 size={16} className="agent-spinner" />
                                    <span>{processingStatus}</span>
                                </div>
                            </article>
                        ) : null}

                        {error ? <div className="agent-error-banner">{error}</div> : null}
                    </div>

                    {showRoutingReview ? (
                        <section id={`routing-review-${openReviewRunUlid}`} className="agent-routing-review" aria-label="Đánh giá cách hiểu câu hỏi">
                            <div className="agent-routing-review__head">
                                <p className="agent-routing-review__question">
                                    Theo bạn, câu hỏi nào gần với ý bạn muốn hỏi nhất?
                                </p>
                                <button type="button" className="agent-routing-review__close" aria-label="Đóng đánh giá" onClick={() => setOpenReviewRunUlid(null)}>×</button>
                            </div>
                            <div className="agent-routing-review__choices">
                                {openReviewCandidates.map((candidate) => (
                                    <label key={candidate.id} className="agent-routing-review__choice">
                                        <input
                                            type="radio"
                                            name={`routing-review-${openReviewRunUlid}`}
                                            checked={openReviewChoice === candidate.id}
                                            onChange={() => setReviewSelections((current) => ({
                                                ...current,
                                                [openReviewRunUlid]: candidate.id,
                                            }))}
                                        />
                                        <span>
                                            {candidate.question}
                                            {openAgentChoice === candidate.id ? <small>Hệ thống đã chọn</small> : null}
                                        </span>
                                    </label>
                                ))}
                                <label className="agent-routing-review__choice">
                                    <input
                                        type="radio"
                                        name={`routing-review-${openReviewRunUlid}`}
                                        checked={openReviewChoice === '__none__'}
                                        onChange={() => setReviewSelections((current) => ({
                                            ...current,
                                            [openReviewRunUlid]: '__none__',
                                        }))}
                                    />
                                    <span>Không câu nào đúng ý tôi</span>
                                </label>
                            </div>
                            <button
                                type="button"
                                className="agent-routing-review__confirm"
                                disabled={!openReviewChoice}
                                onClick={() => confirmRoutingReview(openReviewRunUlid)}
                            >
                                {openConfirmedChoice === openReviewChoice ? 'Đã ghi nhận' : 'Xác nhận'}
                            </button>
                        </section>
                    ) : null}

                    {isTestMode ? null : isViewingArchived ? (
                        <div className="agent-archived-banner" role="status">
                            <span>{locale === 'vi' ? t.archivedBanner : 'This conversation is archived and read-only.'}</span>
                            <button
                                type="button"
                                className="agent-archived-banner__btn"
                                onClick={onNewConversation}
                            >
                                <Plus size={14} />
                                <span>{locale === 'vi' ? t.startNewChat : 'Start new chat'}</span>
                            </button>
                        </div>
                    ) : (
                        <>
                        <form className="agent-composer" onSubmit={onComposerSubmit}>
                            <div className="agent-input-wrap">
                                <textarea
                                    ref={textareaRef}
                                    className="agent-input"
                                    placeholder={
                                        globalUnsupported
                                            ? t.placeholderUnsupported
                                            : t.placeholder
                                    }
                                    value={draft}
                                    onChange={(e) => setDraft(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) {
                                            e.preventDefault();
                                            onSend();
                                        }
                                    }}
                                    disabled={busy || Boolean(threadLoadingUlid) || Boolean(rerunTargetId) || isViewingArchived}
                                    rows={2}
                                />
                                <div className="agent-composer-actions">
                                    <button
                                        type="button"
                                        className="agent-copy-btn"
                                        onClick={onCopy}
                                        disabled={busy || Boolean(threadLoadingUlid) || Boolean(rerunTargetId) || !draft.trim() || isViewingArchived}
                                        title={locale === 'vi' ? 'Sao chép câu lệnh' : 'Copy prompt'}
                                    >
                                        <Copy size={15} />
                                        <span>{copyState ? (locale === 'vi' ? 'Đã chép' : 'Copied') : (locale === 'vi' ? 'Sao chép' : 'Copy')}</span>
                                    </button>
                                    <button
                                        type="submit"
                                        className="agent-send-btn"
                                        disabled={busy || Boolean(threadLoadingUlid) || Boolean(rerunTargetId) || !draft.trim() || isViewingArchived}
                                        title={locale === 'vi' ? 'Gửi tin nhắn' : 'Send message'}
                                        aria-label="Send message"
                                    >
                                        {busy ? <Loader2 size={16} className="agent-spinner" /> : <Send size={16} />}
                                    </button>
                                </div>
                            </div>
                        </form>
                        </>
                    )}
                </div>

                {/* Right History Sidebar */}
                <aside className="agent-history-sidebar" aria-label="Conversation history">
                    <div className="agent-history-sidebar__header">
                        <span className="agent-history-sidebar__title">History</span>
                        <div className="agent-history-sidebar__actions">
                            <button
                                type="button"
                                className="agent-new-chat-btn"
                                onClick={onNewConversation}
                                title="New conversation"
                                aria-label="New conversation"
                            >
                                <Plus size={14} />
                            </button>
                        </div>
                    </div>

                    <div className="agent-history-tabs" role="tablist" aria-label="History categories">
                        <button
                            type="button"
                            role="tab"
                            id="history-tab-chats"
                            aria-selected={historyTab === 'chats'}
                            className={`agent-history-tab ${historyTab === 'chats' ? 'is-active' : ''}`}
                            onClick={() => setHistoryTab('chats')}
                        >
                            {locale === 'vi' ? 'Hội thoại' : 'Chats'}
                        </button>
                        <button
                            type="button"
                            role="tab"
                            id="history-tab-archived"
                            aria-selected={historyTab === 'archived'}
                            className={`agent-history-tab ${historyTab === 'archived' ? 'is-active' : ''}`}
                            onClick={() => setHistoryTab('archived')}
                        >
                            {locale === 'vi' ? 'Đã lưu trữ' : 'Archived'}
                        </button>
                    </div>

                    <div className="agent-history-sidebar__body">
                        {loadingThreads ? (
                            <div className="agent-history-loading">
                                <Loader2 size={16} className="agent-spinner" />
                                <span>{locale === 'vi' ? 'Đang tải…' : 'Loading…'}</span>
                            </div>
                        ) : visibleHistoryTab === 'chats' ? (
                            activeThreads.length === 0 ? (
                                <div className="agent-history-empty">
                                    {locale === 'vi' ? t.noConversations : 'No conversations for this site yet.'}
                                </div>
                            ) : (
                                <ul className="agent-history-list">
                                    {activeThreads.map((t) => (
                                        <li key={t.ulid} className="agent-history-item-wrap">
                                            <button
                                                type="button"
                                                className={`agent-history-item ${t.ulid === viewingThreadUlid && !isViewingArchived ? 'is-active' : ''}`}
                                                onClick={() => {
                                                    loadThread(t.ulid, currentScopeRef, { archived: false });
                                                    setShowHistoryMobile(false);
                                                }}
                                            >
                                                <span className="agent-history-item__title">{t.title || 'Untitled conversation'}</span>
                                                <span className="agent-history-item__meta">
                                                    {formatTimeAgo(t.last_message_at || t.created_at)}
                                                </span>
                                            </button>
                                            <span className="agent-history-action-group">
                                                <button
                                                    type="button"
                                                    className="agent-history-archive-btn"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onArchiveThread(t.ulid);
                                                    }}
                                                    disabled={busy || debugBusy}
                                                    title="Archive conversation"
                                                    aria-label="Archive conversation"
                                                >
                                                    <Archive size={13} />
                                                </button>
                                                <button
                                                    type="button"
                                                    className="agent-history-delete-btn"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onDeleteThread(t.ulid);
                                                    }}
                                                    disabled={busy || debugBusy}
                                                    title="Delete conversation"
                                                    aria-label="Delete conversation"
                                                >
                                                    <Trash2 size={13} />
                                                </button>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )
                        ) : (
                            archivedThreads.length === 0 ? (
                                <div className="agent-history-empty">
                                    {locale === 'vi' ? t.noArchived : 'No archived conversations for this site.'}
                                </div>
                            ) : (
                                <ul className="agent-history-list">
                                    {archivedThreads.map((t) => (
                                        <li key={t.ulid} className="agent-history-item-wrap">
                                            <button
                                                type="button"
                                                className={`agent-history-item ${t.ulid === viewingThreadUlid && isViewingArchived ? 'is-active' : ''}`}
                                                onClick={() => {
                                                    loadThread(t.ulid, currentScopeRef, { archived: true });
                                                    setShowHistoryMobile(false);
                                                }}
                                            >
                                                <span className="agent-history-item__title">{t.title || 'Untitled conversation'}</span>
                                                <span className="agent-history-item__meta">
                                                    {formatTimeAgo(t.last_message_at || t.created_at)}
                                                </span>
                                            </button>
                                            <button
                                                type="button"
                                                className="agent-history-delete-btn"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onDeleteThread(t.ulid);
                                                }}
                                                title="Delete conversation"
                                                aria-label="Delete conversation"
                                            >
                                                <Trash2 size={13} />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )
                        )}
                    </div>
                </aside>
            </div>

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
