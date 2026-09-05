import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import MetricCards from './components/MetricCards';
import FeedToolbar from './components/FeedToolbar';
import TopicFeed from './components/TopicFeed';
import TopicComposer from './components/TopicComposer';
import TopicDetail from './components/TopicDetail';
import GlobalWorkDrawer from './components/GlobalWorkDrawer';
import LocalReport from './components/LocalReport';
import {
    createDebouncedWriter,
    documentKey,
    findReportForComment,
    makeId,
    makeLocalDraftId,
    previewText,
    readDocument,
    topicHasWorkHistory,
    topicKeyOf,
    writeDocument,
} from './services/storage';
import { extractLinksFromPaste, suggestSocialUrl } from './services/linkExtract';
import { saveProof } from './services/proofStore';
import { deriveMetrics, topicMatchesFilter } from './features/workspace/selectors';

function emptyComposerDraft() {
    return {
        localId: makeLocalDraftId(),
        full_text: '',
        social_url: '',
        links: [],
        source_html: null,
    };
}

/**
 * Seeding app root — feed + detail + global work drawer.
 * Local claim is NOT concurrency-safe (prototype).
 *
 * @param {{
 *   canMutate?: boolean,
 *   bootstrap?: {
 *     client?: { installation_id?: string },
 *     user?: { id?: number, display_name?: string },
 *   }
 * }} props
 */
export default function SeedingWorkspace({ canMutate = true, bootstrap = null }) {
    const installationId = bootstrap?.client?.installation_id || 'app:local';
    const userId = bootstrap?.user?.id ?? 0;
    const scope = useMemo(() => ({ installationId, userId }), [installationId, userId]);

    const [topics, setTopics] = useState([]);
    const [reports, setReports] = useState([]);
    const [filter, setFilter] = useState('work');
    const [search, setSearch] = useState('');
    const [composerOpen, setComposerOpen] = useState(false);
    const [composer, setComposer] = useState(null);
    const [detailId, setDetailId] = useState(null);
    /** @type {[string|null, Function]} */
    const [activeWorkItemId, setActiveWorkItemId] = useState(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [toast, setToast] = useState(null);

    const writer = useRef(createDebouncedWriter());
    const toastTimer = useRef(null);
    const topicsRef = useRef(topics);
    const reportsRef = useRef(reports);
    const uiRef = useRef({});

    useEffect(() => { topicsRef.current = topics; }, [topics]);
    useEffect(() => { reportsRef.current = reports; }, [reports]);
    useEffect(() => {
        uiRef.current = {
            filter,
            search,
            detail_topic_id: detailId,
            active_work_item_id: activeWorkItemId,
            history_open: historyOpen,
        };
    }, [filter, search, detailId, activeWorkItemId, historyOpen]);

    const showToast = useCallback((message) => {
        if (toastTimer.current) clearTimeout(toastTimer.current);
        setToast({ message });
        toastTimer.current = setTimeout(() => setToast(null), 3500);
    }, []);

    const persistNow = useCallback((nextTopics, nextReports, uiPartial = {}) => {
        const current = readDocument(scope);
        writeDocument(scope, {
            ...current,
            topics: nextTopics,
            reports: nextReports,
            ui: {
                ...uiRef.current,
                ...uiPartial,
            },
        });
    }, [scope]);

    const schedulePersist = useCallback((nextTopics, nextReports) => {
        writer.current.schedule(() => persistNow(nextTopics, nextReports));
    }, [persistNow]);

    useEffect(() => {
        const doc = readDocument(scope);
        setTopics(doc.topics || []);
        setReports(doc.reports || []);
        setFilter(doc.ui?.filter || 'work');
        setSearch(doc.ui?.search || '');
        setDetailId(doc.ui?.detail_topic_id ? String(doc.ui.detail_topic_id) : null);
        setActiveWorkItemId(doc.ui?.active_work_item_id ? String(doc.ui.active_work_item_id) : null);
        setHistoryOpen(Boolean(doc.ui?.history_open));
        setComposerOpen(false);
        setComposer(null);
    }, [scope]);

    useEffect(() => () => {
        writer.current.cancel();
        if (toastTimer.current) clearTimeout(toastTimer.current);
    }, []);

    const counts = useMemo(() => ({
        work: topics.filter((t) => topicMatchesFilter('work', t)).length,
        draft: topics.filter((t) => topicMatchesFilter('draft', t)).length,
        shared: topics.filter((t) => topicMatchesFilter('shared', t)).length,
        completed: topics.filter((t) => topicMatchesFilter('completed', t)).length,
        archived: topics.filter((t) => topicMatchesFilter('archived', t)).length,
    }), [topics]);

    const metrics = useMemo(() => deriveMetrics(topics, reports, userId), [topics, reports, userId]);

    const filteredTopics = useMemo(() => {
        const q = search.trim().toLowerCase();
        let list = topics.filter((t) => topicMatchesFilter(filter, t));
        if (q) {
            list = list.filter((t) => `${t.preview || ''} ${t.full_text || ''} ${t.social_url || ''}`.toLowerCase().includes(q));
        }
        return list;
    }, [topics, filter, search]);

    const detailTopic = useMemo(
        () => (detailId ? topics.find((t) => topicKeyOf(t) === String(detailId)) || null : null),
        [detailId, topics],
    );

    const activeWork = useMemo(() => {
        if (!activeWorkItemId) return { topic: null, comment: null };
        for (const topic of topics) {
            const comment = (topic.comments || []).find((c) => String(c.id) === String(activeWorkItemId));
            if (comment) return { topic, comment };
        }
        return { topic: null, comment: null };
    }, [activeWorkItemId, topics]);

    const replaceTopics = useCallback((nextTopics, nextReports = reportsRef.current) => {
        topicsRef.current = nextTopics;
        reportsRef.current = nextReports;
        setTopics(nextTopics);
        setReports(nextReports);
        schedulePersist(nextTopics, nextReports);
    }, [schedulePersist]);

    const openComposer = () => {
        if (!canMutate) return;
        setComposer(emptyComposerDraft());
        setComposerOpen(true);
    };

    const pendingPasteRef = useRef(null);

    const patchComposer = (partial) => {
        setComposer((prev) => {
            if (!prev) return prev;
            const next = { ...prev, ...partial };
            if (partial.full_text !== undefined) {
                const fromPaste = pendingPasteRef.current;
                const links = fromPaste?.links
                    || extractLinksFromPaste(partial.full_text, next.source_html);
                next.links = links;
                if (fromPaste?.html) next.source_html = fromPaste.html;
                if (!String(next.social_url || '').trim()) {
                    const suggested = suggestSocialUrl(links);
                    if (suggested) next.social_url = suggested;
                }
                pendingPasteRef.current = null;
            }
            return next;
        });
    };

    const onPasteContent = (event) => {
        const html = event.clipboardData?.getData('text/html') || '';
        const text = event.clipboardData?.getData('text/plain') || '';
        const links = extractLinksFromPaste(text, html);
        pendingPasteRef.current = { html, text, links };
    };

    const createTopic = () => {
        if (!composer || !canMutate) return;
        const fullText = String(composer.full_text || '').trim();
        if (!fullText) return;
        const links = Array.isArray(composer.links) && composer.links.length > 0
            ? composer.links
            : extractLinksFromPaste(fullText, composer.source_html);
        const now = new Date().toISOString();
        const topic = {
            localId: composer.localId || makeLocalDraftId(),
            full_text: fullText,
            social_url: String(composer.social_url || '').trim(),
            links,
            comments: [],
            state: 'draft',
            created_at: now,
            updated_at: now,
            preview: previewText(fullText),
        };
        const nextTopics = [topic, ...topicsRef.current];
        replaceTopics(nextTopics);
        writer.current.flush(() => persistNow(nextTopics, reportsRef.current));
        setComposerOpen(false);
        setComposer(null);
        setFilter('work');
        showToast('Đã tạo chủ đề (local)');
    };

    const cancelComposer = () => {
        setComposerOpen(false);
        setComposer(null);
    };

    const openDetail = (topic) => {
        setDetailId(topicKeyOf(topic));
        setComposerOpen(false);
        setComposer(null);
    };

    const closeDetail = () => setDetailId(null);

    const updateDetailComments = (comments) => {
        if (!detailTopic) return;
        const key = topicKeyOf(detailTopic);
        const nextTopics = topicsRef.current.map((t) =>
            topicKeyOf(t) === key ? { ...t, comments, updated_at: new Date().toISOString() } : t,
        );
        replaceTopics(nextTopics);
    };

    const shareTopic = () => {
        if (!detailTopic || !canMutate) return;
        const comments = Array.isArray(detailTopic.comments) ? detailTopic.comments : [];
        if (comments.length < 1) {
            showToast('Cần ít nhất 1 bình luận.');
            return;
        }
        const now = new Date().toISOString();
        const key = topicKeyOf(detailTopic);
        const nextTopics = topicsRef.current.map((t) =>
            topicKeyOf(t) === key
                ? {
                    ...t,
                    comments,
                    state: 'shared',
                    status: 'shared',
                    shared_at: now,
                    updated_at: now,
                }
                : t,
        );
        replaceTopics(nextTopics);
        writer.current.flush(() => persistNow(nextTopics, reportsRef.current, {
            detail_topic_id: null,
            filter: 'work',
        }));
        setDetailId(null);
        setFilter('work');
        showToast('Đã đẩy chia sẻ (local) — đang chạy');
    };

    const deleteTopic = () => {
        if (!detailTopic || !canMutate) return;
        if (topicHasWorkHistory(detailTopic, reportsRef.current)) {
            showToast('Không thể xóa — đã có lịch sử làm việc.');
            return;
        }
        const key = topicKeyOf(detailTopic);
        const nextTopics = topicsRef.current.filter((t) => topicKeyOf(t) !== key);
        replaceTopics(nextTopics);
        setDetailId(null);
        showToast('Đã xóa chủ đề');
    };

    /**
     * Local prototype claim — not concurrency-safe.
     */
    const claimComment = (comment) => {
        if (!detailTopic || !canMutate) return;
        if ((detailTopic.state || 'draft') === 'draft') {
            showToast('Đẩy chia sẻ trước khi nhận việc.');
            return;
        }
        if (comment.state === 'completed') return;
        if (comment.state === 'in_progress' && String(comment.claimed_by_user_id) !== String(userId)) {
            showToast('Bình luận đang được người khác nhận (local).');
            return;
        }
        const now = new Date().toISOString();
        const key = topicKeyOf(detailTopic);
        const nextTopics = topicsRef.current.map((t) => {
            if (topicKeyOf(t) !== key) return t;
            return {
                ...t,
                comments: (t.comments || []).map((c) =>
                    c.id === comment.id
                        ? {
                            ...c,
                            state: 'in_progress',
                            claimed_by_user_id: userId,
                            claimed_at: c.claimed_at || now,
                        }
                        : c,
                ),
                updated_at: now,
            };
        });
        replaceTopics(nextTopics);
        setActiveWorkItemId(String(comment.id));
        writer.current.flush(() => persistNow(nextTopics, reportsRef.current, {
            active_work_item_id: String(comment.id),
        }));
    };

    const releaseWork = () => {
        const { topic, comment } = activeWork;
        if (!topic || !comment) {
            setActiveWorkItemId(null);
            return;
        }
        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.map((t) => {
            if (topicKeyOf(t) !== key) return t;
            return {
                ...t,
                comments: (t.comments || []).map((c) =>
                    c.id === comment.id
                        ? {
                            ...c,
                            state: 'available',
                            claimed_by_user_id: null,
                            claimed_at: null,
                        }
                        : c,
                ),
            };
        });
        replaceTopics(nextTopics);
        setActiveWorkItemId(null);
        writer.current.flush(() => persistNow(nextTopics, reportsRef.current, {
            active_work_item_id: null,
        }));
    };

    const copyWorkComment = async () => {
        const text = activeWork.comment?.text || '';
        try {
            await navigator.clipboard.writeText(text);
            showToast('Đã copy');
        } catch {
            showToast('Không copy được');
        }
    };

    const completeWithProof = useCallback(async (file) => {
        const { topic, comment } = activeWork;
        if (!topic || !comment) throw new Error('Không có việc đang mở.');

        // Idempotency: one completion report per comment item
        if (findReportForComment(reportsRef.current, comment.id)) {
            setActiveWorkItemId(null);
            showToast('Đã hoàn tất trước đó');
            return;
        }

        const proofId = makeId('proof');
        const createdAt = new Date().toISOString();
        await saveProof({
            id: proofId,
            blob: file,
            mime: file.type || 'image/png',
            size: file.size || 0,
            created_at: createdAt,
            topic_id: topicKeyOf(topic),
            comment_item_id: comment.id,
        });

        const report = {
            id: makeId('rpt'),
            topic_id: topicKeyOf(topic),
            comment_item_id: comment.id,
            user_id: userId,
            user_display_name: bootstrap?.user?.display_name || '',
            comment_text: comment.text,
            social_url: topic.social_url || '',
            proof_id: proofId,
            mime: file.type || 'image/png',
            size: file.size || 0,
            completed_at: createdAt,
        };

        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.map((t) => {
            if (topicKeyOf(t) !== key) return t;
            const comments = (t.comments || []).map((c) =>
                c.id === comment.id
                    ? { ...c, state: 'completed', completed_at: createdAt }
                    : c,
            );
            const allDone = comments.length > 0 && comments.every((c) => c.state === 'completed');
            return {
                ...t,
                comments,
                state: allDone ? 'completed' : t.state,
                updated_at: createdAt,
            };
        });
        const nextReports = [...reportsRef.current, report];
        replaceTopics(nextTopics, nextReports);
        setActiveWorkItemId(null);
        writer.current.flush(() => persistNow(nextTopics, nextReports, {
            active_work_item_id: null,
        }));
        showToast('Hoàn tất +1');
    }, [activeWork, replaceTopics, persistNow, showToast, userId]);

    const shellClass = `seeding-ws seeding-ws--feed${activeWorkItemId ? ' has-drawer' : ''}`;

    return (
        <div className={shellClass} data-storage-key={documentKey(scope)} data-layout="feed">
            <div className="seeding-ws__main-column">
                {detailTopic ? (
                    <TopicDetail
                        topic={detailTopic}
                        canMutate={canMutate}
                        canDelete={canMutate && !topicHasWorkHistory(detailTopic, reports)}
                        userId={userId}
                        onBack={closeDetail}
                        onDelete={deleteTopic}
                        onCommentsChange={updateDetailComments}
                        onShare={shareTopic}
                        onClaim={claimComment}
                    />
                ) : (
                    <>
                        <header className="seeding-ws__page-head">
                            <div>
                                <h1 className="seeding-ws__page-title">Seeding</h1>
                                <p className="seeding-ws__page-sub">Comment-task workflow — topic bất biến, việc là bình luận</p>
                            </div>
                            <button
                                type="button"
                                className="seeding-ws__btn seeding-ws__btn--ghost"
                                onClick={() => setHistoryOpen((v) => !v)}
                            >
                                Báo cáo
                            </button>
                        </header>

                        <MetricCards metrics={metrics} />

                        <FeedToolbar
                            filter={filter}
                            search={search}
                            counts={counts}
                            canMutate={canMutate}
                            onFilter={(f) => { setFilter(f); schedulePersist(topicsRef.current, reportsRef.current); }}
                            onSearch={(v) => { setSearch(v); schedulePersist(topicsRef.current, reportsRef.current); }}
                            onCreate={openComposer}
                        />

                        {composerOpen && composer ? (
                            <TopicComposer
                                topic={composer}
                                canMutate={canMutate}
                                onChange={patchComposer}
                                onPasteContent={onPasteContent}
                                onCancel={cancelComposer}
                                onCreate={createTopic}
                            />
                        ) : null}

                        <LocalReport
                            reports={reports}
                            topics={topics}
                            open={historyOpen}
                            onClose={() => setHistoryOpen(false)}
                        />

                        <TopicFeed
                            topics={filteredTopics}
                            reports={reports}
                            onOpen={openDetail}
                            onCreate={openComposer}
                            canMutate={canMutate}
                        />
                    </>
                )}
            </div>

            <GlobalWorkDrawer
                open={Boolean(activeWorkItemId && activeWork.comment)}
                topic={activeWork.topic}
                comment={activeWork.comment}
                onCopy={copyWorkComment}
                onRelease={releaseWork}
                onClose={() => setActiveWorkItemId(null)}
                onProofImage={completeWithProof}
            />

            {toast ? <div className="seeding-ws__toast">{toast.message}</div> : null}
        </div>
    );
}
