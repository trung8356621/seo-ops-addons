import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import MetricCards from './components/MetricCards';
import FeedToolbar from './components/FeedToolbar';
import TopicFeed from './components/TopicFeed';
import TopicComposer from './components/TopicComposer';
import TopicDetail from './components/TopicDetail';
import TeamStatsSidebar from './components/TeamStatsSidebar';
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
import { canDeleteTopic, canEditTopic, canShareTopic } from './features/workspace/auth';

function emptyComposerDraft() {
    return {
        localId: makeLocalDraftId(),
        title: '',
        full_text: '',
        social_url: '',
        links: [],
        source_html: null,
        _mode: 'create',
    };
}

/**
 * Seeding app root — feed shell + team stats sidebar + detail + work drawer.
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
    const userDisplayName = bootstrap?.user?.display_name || '';
    const scope = useMemo(() => ({ installationId, userId }), [installationId, userId]);

    const [topics, setTopics] = useState([]);
    const [reports, setReports] = useState([]);
    const [linkPreviews, setLinkPreviews] = useState({});
    const [filter, setFilter] = useState('work');
    const [search, setSearch] = useState('');
    const [composerOpen, setComposerOpen] = useState(false);
    const [composer, setComposer] = useState(null);
    const [detailId, setDetailId] = useState(null);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    /** @type {[string|null, Function]} */
    const [activeWorkItemId, setActiveWorkItemId] = useState(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [toast, setToast] = useState(null);

    const writer = useRef(createDebouncedWriter());
    const toastTimer = useRef(null);
    const topicsRef = useRef(topics);
    const reportsRef = useRef(reports);
    const linkPreviewsRef = useRef(linkPreviews);
    const uiRef = useRef({});

    useEffect(() => { topicsRef.current = topics; }, [topics]);
    useEffect(() => { reportsRef.current = reports; }, [reports]);
    useEffect(() => { linkPreviewsRef.current = linkPreviews; }, [linkPreviews]);
    useEffect(() => {
        uiRef.current = {
            filter,
            search,
            detail_topic_id: detailId,
            active_work_item_id: activeWorkItemId,
            history_open: historyOpen,
            sidebar_collapsed: sidebarCollapsed,
        };
    }, [filter, search, detailId, activeWorkItemId, historyOpen, sidebarCollapsed]);

    const showToast = useCallback((message) => {
        if (toastTimer.current) clearTimeout(toastTimer.current);
        setToast({ message });
        toastTimer.current = setTimeout(() => setToast(null), 3500);
    }, []);

    const persistNow = useCallback((nextTopics, nextReports, uiPartial = {}, nextLinkPreviews = linkPreviewsRef.current) => {
        const current = readDocument(scope);
        writeDocument(scope, {
            ...current,
            topics: nextTopics,
            reports: nextReports,
            link_previews: nextLinkPreviews,
            ui: {
                ...uiRef.current,
                ...uiPartial,
            },
        });
    }, [scope]);

    const schedulePersist = useCallback((nextTopics, nextReports, nextLinkPreviews = linkPreviewsRef.current) => {
        writer.current.schedule(() => persistNow(nextTopics, nextReports, {}, nextLinkPreviews));
    }, [persistNow]);

    useEffect(() => {
        const doc = readDocument(scope);
        const topicsRaw = Array.isArray(doc.topics) ? doc.topics : [];
        // Per-user localStorage scope: legacy topics without owner belong to this user.
        const topicsOwned = topicsRaw.map((t) => (
            t.created_by_user_id == null || t.created_by_user_id === ''
                ? { ...t, created_by_user_id: userId, created_by_display_name: t.created_by_display_name || userDisplayName }
                : t
        ));
        setTopics(topicsOwned);
        setReports(doc.reports || []);
        setLinkPreviews(doc.link_previews && typeof doc.link_previews === 'object' ? doc.link_previews : {});
        setFilter(doc.ui?.filter || 'work');
        setSearch(doc.ui?.search || '');
        setDetailId(doc.ui?.detail_topic_id ? String(doc.ui.detail_topic_id) : null);
        setActiveWorkItemId(doc.ui?.active_work_item_id ? String(doc.ui.active_work_item_id) : null);
        setHistoryOpen(Boolean(doc.ui?.history_open));
        setSidebarCollapsed(Boolean(doc.ui?.sidebar_collapsed));
        setComposerOpen(false);
        setComposer(null);
        if (topicsOwned.some((t, i) => t !== topicsRaw[i])) {
            schedulePersist(topicsOwned, doc.reports || [], doc.link_previews || {});
        }
    }, [scope, userId, userDisplayName, schedulePersist]);

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
            list = list.filter((t) => `${t.title || ''} ${t.preview || ''} ${t.full_text || ''} ${t.social_url || ''}`.toLowerCase().includes(q));
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

    const replaceTopics = useCallback((nextTopics, nextReports = reportsRef.current, nextLinkPreviews = linkPreviewsRef.current) => {
        topicsRef.current = nextTopics;
        reportsRef.current = nextReports;
        linkPreviewsRef.current = nextLinkPreviews;
        setTopics(nextTopics);
        setReports(nextReports);
        setLinkPreviews(nextLinkPreviews);
        schedulePersist(nextTopics, nextReports, nextLinkPreviews);
    }, [schedulePersist]);

    const updateLinkPreviewCache = useCallback((nextCache) => {
        const merged = { ...linkPreviewsRef.current, ...nextCache };
        linkPreviewsRef.current = merged;
        setLinkPreviews(merged);
        schedulePersist(topicsRef.current, reportsRef.current, merged);
    }, [schedulePersist]);

    const patchTopicByKey = useCallback((key, patcher) => {
        const nextTopics = topicsRef.current.map((t) =>
            topicKeyOf(t) === key ? patcher(t) : t,
        );
        replaceTopics(nextTopics);
    }, [replaceTopics]);

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
        const title = String(composer.title || '').trim();
        const topic = {
            localId: composer.localId || makeLocalDraftId(),
            title,
            full_text: fullText,
            social_url: String(composer.social_url || '').trim(),
            links,
            comments: [],
            state: 'draft',
            created_at: now,
            updated_at: now,
            preview: previewText(fullText),
            created_by_user_id: userId,
            created_by_display_name: userDisplayName,
        };
        const nextTopics = [topic, ...topicsRef.current];
        replaceTopics(nextTopics);
        writer.current.flush(() => persistNow(nextTopics, reportsRef.current));
        setComposerOpen(false);
        setComposer(null);
        setFilter('work');
        showToast('Đã tạo chủ đề (local)');
    };

    const saveEditedTopic = () => {
        if (!composer || composer._mode !== 'edit' || !canMutate) return;
        const key = String(composer.localId || composer.id);
        const target = topicsRef.current.find((t) => topicKeyOf(t) === key);
        if (!target || !canEditTopic(target, userId, canMutate)) {
            showToast('Không có quyền sửa chủ đề này.');
            return;
        }
        const fullText = String(composer.full_text || '').trim();
        if (!fullText) return;
        const links = Array.isArray(composer.links) && composer.links.length > 0
            ? composer.links
            : extractLinksFromPaste(fullText, composer.source_html);
        patchTopicByKey(key, (t) => ({
            ...t,
            title: String(composer.title || '').trim(),
            full_text: fullText,
            social_url: String(composer.social_url || '').trim(),
            links,
            preview: previewText(fullText),
            updated_at: new Date().toISOString(),
        }));
        setComposerOpen(false);
        setComposer(null);
        showToast('Đã cập nhật chủ đề');
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

    const updateTopicComments = (topic, comments) => {
        const key = topicKeyOf(topic);
        patchTopicByKey(key, (t) => ({
            ...t,
            comments,
            updated_at: new Date().toISOString(),
        }));
    };

    const updateTopicLinks = useCallback((topic, links) => {
        const key = topicKeyOf(topic);
        patchTopicByKey(key, (t) => ({ ...t, links }));
    }, [patchTopicByKey]);

    const shareTopic = (topic) => {
        if (!topic || !canMutate) return;
        if (!canShareTopic(topic)) {
            showToast('Cần ít nhất 1 bình luận.');
            return;
        }
        const now = new Date().toISOString();
        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.map((t) =>
            topicKeyOf(t) === key
                ? {
                    ...t,
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

    const deleteTopic = (topic) => {
        if (!topic || !canMutate) return;
        if (!canDeleteTopic(topic, userId, canMutate, reportsRef.current, topicHasWorkHistory)) {
            showToast('Không có quyền xóa.');
            return;
        }
        if (topicHasWorkHistory(topic, reportsRef.current)) {
            showToast('Không thể xóa — đã có lịch sử làm việc.');
            return;
        }
        if (!window.confirm('Xóa chủ đề này?')) return;
        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.filter((t) => topicKeyOf(t) !== key);
        replaceTopics(nextTopics);
        if (detailId === key) setDetailId(null);
        showToast('Đã xóa chủ đề');
    };

    const editTopic = (topic) => {
        if (!canEditTopic(topic, userId, canMutate)) {
            showToast('Chỉ tác giả mới được sửa.');
            return;
        }
        setComposer({
            localId: topic.localId || topic.id,
            title: topic.title || '',
            full_text: topic.full_text || '',
            social_url: topic.social_url || '',
            links: topic.links || [],
            source_html: null,
            _mode: 'edit',
        });
        setComposerOpen(true);
        setDetailId(null);
    };

    /**
     * Local prototype claim — not concurrency-safe.
     */
    const claimComment = (comment) => {
        const topic = detailTopic || topicsRef.current.find((t) =>
            (t.comments || []).some((c) => String(c.id) === String(comment.id)),
        );
        if (!topic || !canMutate) return;
        if ((topic.state || 'draft') === 'draft') {
            showToast('Đẩy chia sẻ trước khi nhận việc.');
            return;
        }
        if (comment.state === 'completed') return;
        if (comment.state === 'in_progress' && String(comment.claimed_by_user_id) !== String(userId)) {
            showToast('Bình luận đang được người khác nhận (local).');
            return;
        }
        const now = new Date().toISOString();
        const key = topicKeyOf(topic);
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
                            claimed_by_display_name: userDisplayName,
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
                            claimed_by_display_name: null,
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
            user_display_name: userDisplayName,
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
    }, [activeWork, replaceTopics, persistNow, showToast, userId, userDisplayName]);

    const toggleSidebar = () => {
        setSidebarCollapsed((v) => {
            schedulePersist(topicsRef.current, reportsRef.current);
            return !v;
        });
    };

    const openReport = () => {
        setHistoryOpen(true);
        if (detailId) setDetailId(null);
    };

    const shellClass = [
        'seeding-ws',
        'seeding-ws--feed',
        'seeding-ws--shell',
        !sidebarCollapsed ? 'has-sidebar' : 'sidebar-collapsed',
        activeWorkItemId ? 'has-drawer' : '',
    ].filter(Boolean).join(' ');

    return (
        <div className={shellClass} data-storage-key={documentKey(scope)} data-layout="shell">
            <div className="seeding-ws__main-column">
                {detailTopic ? (
                    <TopicDetail
                        topic={detailTopic}
                        canMutate={canMutate}
                        canDelete={canDeleteTopic(detailTopic, userId, canMutate, reports, topicHasWorkHistory)}
                        canEdit={canEditTopic(detailTopic, userId, canMutate)}
                        userId={userId}
                        userDisplayName={userDisplayName}
                        linkPreviewCache={linkPreviews}
                        onBack={closeDetail}
                        onDelete={() => deleteTopic(detailTopic)}
                        onEdit={() => editTopic(detailTopic)}
                        onCommentsChange={(comments) => updateTopicComments(detailTopic, comments)}
                        onCacheUpdate={updateLinkPreviewCache}
                        onShare={() => shareTopic(detailTopic)}
                        onClaim={claimComment}
                    />
                ) : (
                    <>
                        <header className="seeding-ws__page-head">
                            <div>
                                <h1 className="seeding-ws__page-title">Seeding</h1>
                                <p className="seeding-ws__page-sub">Comment-task workflow — feed là nơi thao tác chính</p>
                            </div>
                            <div className="seeding-ws__page-head-actions">
                                <button
                                    type="button"
                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                    onClick={() => setHistoryOpen((v) => !v)}
                                >
                                    Báo cáo
                                </button>
                                {sidebarCollapsed ? (
                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={toggleSidebar}>
                                        Mở panel
                                    </button>
                                ) : null}
                            </div>
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
                                mode={composer._mode === 'edit' ? 'edit' : 'create'}
                                onChange={patchComposer}
                                onPasteContent={onPasteContent}
                                onCancel={cancelComposer}
                                onCreate={composer._mode === 'edit' ? saveEditedTopic : createTopic}
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
                            canMutate={canMutate}
                            userId={userId}
                            userDisplayName={userDisplayName}
                            linkPreviewCache={linkPreviews}
                            onOpenDetail={openDetail}
                            onCommentsChange={updateTopicComments}
                            onLinksChange={updateTopicLinks}
                            onCacheUpdate={updateLinkPreviewCache}
                            onEdit={editTopic}
                            onDelete={deleteTopic}
                            onShare={shareTopic}
                            onCreate={openComposer}
                        />
                    </>
                )}
            </div>

            <TeamStatsSidebar
                open
                collapsed={sidebarCollapsed}
                topics={topics}
                reports={reports}
                onToggleCollapse={toggleSidebar}
                onOpenReport={openReport}
            />

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
