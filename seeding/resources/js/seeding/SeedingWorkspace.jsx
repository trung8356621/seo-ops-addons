import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Toaster } from 'sonner';
import MetricCards from './components/MetricCards';
import FeedToolbar from './components/FeedToolbar';
import TopicFeed from './components/TopicFeed';
import TopicComposer from './components/TopicComposer';
import TopicDetail from './components/TopicDetail';
import TeamStatsSidebar from './components/TeamStatsSidebar';
import LinkPoolPanel from './components/LinkPoolPanel';
import ShareGeneratePanel from './components/ShareGeneratePanel';
import ReportModal from './components/ReportModal';
import {
    createDebouncedWriter,
    documentKey,
    makeLocalDraftId,
    previewText,
    readDocument,
    topicHasWorkHistory,
    topicKeyOf,
    writeDocument,
} from './services/storage';
import { extractLinksFromPaste, suggestSocialUrl } from './services/linkExtract';
import {
    deriveMetrics,
    sortTopicsForFeed,
    topicMatchesFilter,
} from './features/workspace/selectors';
import {
    canDeleteTopic,
    canEditTopic,
    canSeedTopic,
    canShareDraftTopic,
    canManageOwnSeedLinks,
} from './features/workspace/auth';
import { generateSeedBatch, regenerateSeedOutput } from './services/seedGenerate';
import { normalizeSeedLinks } from './services/linkPool';
import { fetchSharedFeed, shareTopic as shareTopicApi, submitReport } from './api';
import {
    applyReportSuccessLocal,
    mergeSharedFeed,
    removeDraftTopic,
    restoreDraftTopic,
} from './services/shareFeed';
import { notifyError, notifySuccess } from './services/toast';

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
 * React-owned Seeding workspace — local-first UX, DB only at commit points.
 */
export default function SeedingWorkspace({ canMutate = true, bootstrap = null }) {
    const installationId = bootstrap?.client?.installation_id || 'app:local';
    const userId = bootstrap?.user?.id ?? 0;
    const userDisplayName = bootstrap?.user?.display_name || '';
    const scope = useMemo(() => ({ installationId, userId }), [installationId, userId]);
    const hasWorkspaceAccess = true;

    const [topics, setTopics] = useState([]);
    const [reports, setReports] = useState([]);
    const [seedLinks, setSeedLinks] = useState([]);
    const [seedBatches, setSeedBatches] = useState([]);
    const [seedOutputs, setSeedOutputs] = useState([]);
    const [linkPreviews, setLinkPreviews] = useState({});
    const [linkUsageToday, setLinkUsageToday] = useState({});
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [composerOpen, setComposerOpen] = useState(false);
    const [composer, setComposer] = useState(null);
    const [detailId, setDetailId] = useState(null);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [linkPoolOpen, setLinkPoolOpen] = useState(false);
    const [genTopicId, setGenTopicId] = useState(null);
    const [generating, setGenerating] = useState(false);
    const [sharingTopicKey, setSharingTopicKey] = useState(null);
    const [reportTarget, setReportTarget] = useState(null);
    const [reporting, setReporting] = useState(false);

    const writer = useRef(createDebouncedWriter());
    const topicsRef = useRef(topics);
    const reportsRef = useRef(reports);
    const seedLinksRef = useRef(seedLinks);
    const seedBatchesRef = useRef(seedBatches);
    const seedOutputsRef = useRef(seedOutputs);
    const linkPreviewsRef = useRef(linkPreviews);
    const linkUsageRef = useRef(linkUsageToday);
    const uiRef = useRef({});
    const feedAbortRef = useRef(null);

    useEffect(() => { topicsRef.current = topics; }, [topics]);
    useEffect(() => { reportsRef.current = reports; }, [reports]);
    useEffect(() => { seedLinksRef.current = seedLinks; }, [seedLinks]);
    useEffect(() => { seedBatchesRef.current = seedBatches; }, [seedBatches]);
    useEffect(() => { seedOutputsRef.current = seedOutputs; }, [seedOutputs]);
    useEffect(() => { linkPreviewsRef.current = linkPreviews; }, [linkPreviews]);
    useEffect(() => { linkUsageRef.current = linkUsageToday; }, [linkUsageToday]);
    useEffect(() => {
        uiRef.current = {
            filter,
            search,
            detail_topic_id: detailId,
            history_open: false,
            sidebar_collapsed: sidebarCollapsed,
            link_pool_open: linkPoolOpen,
            share_topic_id: genTopicId,
        };
    }, [filter, search, detailId, sidebarCollapsed, linkPoolOpen, genTopicId]);

    const persistNow = useCallback((partial = {}) => {
        const current = readDocument(scope);
        writeDocument(scope, {
            ...current,
            topics: partial.topics ?? topicsRef.current,
            reports: partial.reports ?? reportsRef.current,
            seed_links: partial.seed_links ?? seedLinksRef.current,
            seed_batches: partial.seed_batches ?? seedBatchesRef.current,
            seed_outputs: partial.seed_outputs ?? seedOutputsRef.current,
            link_usage_today: partial.link_usage_today ?? linkUsageRef.current,
            link_previews: partial.link_previews ?? linkPreviewsRef.current,
            ui: {
                ...uiRef.current,
                ...(partial.ui || {}),
            },
        });
    }, [scope]);

    const schedulePersist = useCallback(() => {
        writer.current.schedule(() => persistNow());
    }, [persistNow]);

    const applyDoc = useCallback((patch, persist = true) => {
        if (patch.topics) {
            topicsRef.current = patch.topics;
            setTopics(patch.topics);
        }
        if (patch.reports) {
            reportsRef.current = patch.reports;
            setReports(patch.reports);
        }
        if (patch.seed_links) {
            seedLinksRef.current = patch.seed_links;
            setSeedLinks(patch.seed_links);
        }
        if (patch.seed_batches) {
            seedBatchesRef.current = patch.seed_batches;
            setSeedBatches(patch.seed_batches);
        }
        if (patch.seed_outputs) {
            seedOutputsRef.current = patch.seed_outputs;
            setSeedOutputs(patch.seed_outputs);
        }
        if (patch.link_previews) {
            linkPreviewsRef.current = patch.link_previews;
            setLinkPreviews(patch.link_previews);
        }
        if (patch.link_usage_today) {
            linkUsageRef.current = patch.link_usage_today;
            setLinkUsageToday(patch.link_usage_today);
        }
        if (persist) schedulePersist();
    }, [schedulePersist]);

    const refreshFeed = useCallback(async () => {
        if (feedAbortRef.current) feedAbortRef.current.abort();
        const controller = new AbortController();
        feedAbortRef.current = controller;
        try {
            const data = await fetchSharedFeed(controller.signal);
            const feedTopics = Array.isArray(data?.topics) ? data.topics : [];
            const usage = data?.link_usage_today && typeof data.link_usage_today === 'object'
                ? data.link_usage_today
                : {};
            const merged = mergeSharedFeed(topicsRef.current, feedTopics, userId);
            applyDoc({ topics: merged, link_usage_today: usage }, false);
            writer.current.flush(() => persistNow({ topics: merged, link_usage_today: usage }));
        } catch (e) {
            if (e?.name === 'AbortError') return;
            // Soft fail — local drafts still usable
        }
    }, [applyDoc, persistNow, userId]);

    useEffect(() => {
        const doc = readDocument(scope);
        const topicsRaw = Array.isArray(doc.topics) ? doc.topics : [];
        const topicsOwned = topicsRaw.map((t) => (
            t.created_by_user_id == null || t.created_by_user_id === ''
                ? { ...t, created_by_user_id: userId, created_by_display_name: t.created_by_display_name || userDisplayName }
                : t
        ));
        setTopics(topicsOwned);
        topicsRef.current = topicsOwned;
        setReports(doc.reports || []);
        setSeedLinks(normalizeSeedLinks(doc.seed_links || []));
        setSeedBatches(doc.seed_batches || []);
        setSeedOutputs(doc.seed_outputs || []);
        setLinkPreviews(doc.link_previews && typeof doc.link_previews === 'object' ? doc.link_previews : {});
        setLinkUsageToday(doc.link_usage_today && typeof doc.link_usage_today === 'object' ? doc.link_usage_today : {});
        setFilter(doc.ui?.filter || 'all');
        setSearch(doc.ui?.search || '');
        setDetailId(doc.ui?.detail_topic_id ? String(doc.ui.detail_topic_id) : null);
        setSidebarCollapsed(Boolean(doc.ui?.sidebar_collapsed));
        setLinkPoolOpen(Boolean(doc.ui?.link_pool_open));
        setGenTopicId(doc.ui?.share_topic_id ? String(doc.ui.share_topic_id) : null);
        setComposerOpen(false);
        setComposer(null);
        refreshFeed();
        const timer = setInterval(() => refreshFeed(), 60000);
        return () => {
            clearInterval(timer);
            if (feedAbortRef.current) feedAbortRef.current.abort();
        };
    }, [scope, userId, userDisplayName, refreshFeed]);

    useEffect(() => () => {
        writer.current.cancel();
    }, []);

    const counts = useMemo(() => {
        const ctx = { batches: seedBatches, userId };
        return {
            all: topics.filter((t) => topicMatchesFilter('all', t, ctx)).length,
            draft: topics.filter((t) => topicMatchesFilter('draft', t, ctx)).length,
            recent: topics.filter((t) => topicMatchesFilter('recent', t, ctx)).length,
            archived: topics.filter((t) => topicMatchesFilter('archived', t, ctx)).length,
        };
    }, [topics, seedBatches, userId]);

    const metrics = useMemo(
        () => deriveMetrics(topics, seedBatches, seedOutputs, userId),
        [topics, seedBatches, seedOutputs, userId],
    );

    const filteredTopics = useMemo(() => {
        const q = search.trim().toLowerCase();
        const ctx = { batches: seedBatches, userId };
        let list = topics.filter((t) => topicMatchesFilter(filter, t, ctx));
        if (q) {
            list = list.filter((t) => `${t.title || ''} ${t.preview || ''} ${t.full_text || ''} ${t.social_url || ''}`.toLowerCase().includes(q));
        }
        return sortTopicsForFeed(list, seedBatches, userId);
    }, [topics, filter, search, seedBatches, userId]);

    const detailTopic = useMemo(
        () => (detailId ? topics.find((t) => topicKeyOf(t) === String(detailId)) || null : null),
        [detailId, topics],
    );

    const genTopic = useMemo(
        () => (genTopicId ? topics.find((t) => topicKeyOf(t) === String(genTopicId)) || null : null),
        [genTopicId, topics],
    );

    const topicOutputs = useMemo(() => {
        if (!genTopicId) return [];
        return seedOutputs
            .filter((o) => String(o.topic_id) === String(genTopicId) || String(o.topic_id) === String(genTopic?.id))
            .filter((o) => String(o.user_id) === String(userId))
            .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
    }, [seedOutputs, genTopicId, genTopic, userId]);

    const updateLinkPreviewCache = useCallback((nextCache) => {
        const merged = { ...linkPreviewsRef.current, ...nextCache };
        applyDoc({ link_previews: merged });
    }, [applyDoc]);

    const patchTopicByKey = useCallback((key, patcher) => {
        const nextTopics = topicsRef.current.map((t) =>
            topicKeyOf(t) === key ? patcher(t) : t,
        );
        applyDoc({ topics: nextTopics });
    }, [applyDoc]);

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
            title: String(composer.title || '').trim(),
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
        applyDoc({ topics: nextTopics }, false);
        writer.current.flush(() => persistNow({ topics: nextTopics }));
        setComposerOpen(false);
        setComposer(null);
        setFilter('draft');
        notifySuccess('Đã tạo chủ đề');
    };

    const saveEditedTopic = () => {
        if (!composer || composer._mode !== 'edit' || !canMutate) return;
        const key = String(composer.localId || composer.id);
        const target = topicsRef.current.find((t) => topicKeyOf(t) === key);
        if (!target || !canEditTopic(target, userId, canMutate)) {
            notifyError('Không có quyền sửa chủ đề này.');
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
        notifySuccess('Đã cập nhật chủ đề');
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

    const updateTopicLinks = useCallback((topic, links) => {
        const key = topicKeyOf(topic);
        patchTopicByKey(key, (t) => ({ ...t, links }));
    }, [patchTopicByKey]);

    const openGen = (topic) => {
        if (!canSeedTopic(topic, { hasWorkspaceAccess, userId })) return;
        setGenTopicId(topicKeyOf(topic));
        setLinkPoolOpen(false);
    };

    const closeGen = () => setGenTopicId(null);

    const shareDraft = async (topic) => {
        if (!canShareDraftTopic(topic, userId, canMutate)) return;
        const key = topicKeyOf(topic);
        const draftSnapshot = { ...topic };
        const draftIndex = topicsRef.current.findIndex((t) => topicKeyOf(t) === key);

        setSharingTopicKey(key);
        const optimistic = removeDraftTopic(topicsRef.current, key);
        applyDoc({ topics: optimistic }, false);
        writer.current.flush(() => persistNow({ topics: optimistic }));

        try {
            await shareTopicApi({
                title: topic.title || '',
                full_text: topic.full_text || '',
                source_html: topic.source_html || null,
                social_url: topic.social_url || '',
                links: topic.links || [],
            });
            notifySuccess('Đã chia sẻ chủ đề');
            await refreshFeed();
        } catch (e) {
            const rolled = restoreDraftTopic(topicsRef.current, draftSnapshot, draftIndex < 0 ? 0 : draftIndex);
            applyDoc({ topics: rolled }, false);
            writer.current.flush(() => persistNow({ topics: rolled }));
            notifyError(e?.message || 'Chia sẻ thất bại');
        } finally {
            setSharingTopicKey(null);
        }
    };

    const deleteTopic = (topic) => {
        if (!topic || !canMutate) return;
        const extra = { seed_batches: seedBatchesRef.current, seed_outputs: seedOutputsRef.current };
        if (!canDeleteTopic(topic, userId, canMutate, reportsRef.current, topicHasWorkHistory, extra)) {
            notifyError('Không có quyền xóa.');
            return;
        }
        if (!window.confirm('Xóa chủ đề này?')) return;
        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.filter((t) => topicKeyOf(t) !== key);
        applyDoc({ topics: nextTopics });
        if (detailId === key) setDetailId(null);
        if (genTopicId === key) setGenTopicId(null);
        notifySuccess('Đã xóa chủ đề');
    };

    const editTopic = (topic) => {
        if (!canEditTopic(topic, userId, canMutate)) {
            notifyError('Chỉ sửa được nháp local của bạn.');
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

    const onSeedLinksChange = (nextLinks, toastMsg) => {
        if (!canManageOwnSeedLinks(hasWorkspaceAccess)) return;
        applyDoc({ seed_links: normalizeSeedLinks(nextLinks) });
        if (toastMsg) notifySuccess(toastMsg);
    };

    const runGenerate = async (quantity) => {
        if (!genTopic || !canSeedTopic(genTopic, { hasWorkspaceAccess, userId })) return;
        setGenerating(true);
        try {
            const { batch, outputs } = await generateSeedBatch({
                topic: genTopic,
                userId,
                userDisplayName,
                quantity,
            });
            const nextBatches = [batch, ...seedBatchesRef.current];
            const nextOutputs = [...outputs, ...seedOutputsRef.current];
            applyDoc({
                seed_batches: nextBatches,
                seed_outputs: nextOutputs,
            }, false);
            writer.current.flush(() => persistNow({
                seed_batches: nextBatches,
                seed_outputs: nextOutputs,
            }));
            notifySuccess(`Đã Gen ${outputs.length} comment`);
        } catch (e) {
            notifyError(e?.message || 'Gen thất bại');
        } finally {
            setGenerating(false);
        }
    };

    const updateOutput = (output) => {
        const next = seedOutputsRef.current.map((o) => (
            String(o.id) === String(output.id) ? { ...output, updated_at: new Date().toISOString() } : o
        ));
        applyDoc({ seed_outputs: next });
    };

    const regenOutput = async (output) => {
        if (!genTopic) return;
        try {
            const updated = await regenerateSeedOutput({
                output,
                topic: genTopic,
            });
            const next = seedOutputsRef.current.map((o) => (
                String(o.id) === String(updated.id) ? updated : o
            ));
            applyDoc({ seed_outputs: next }, false);
            writer.current.flush(() => persistNow({ seed_outputs: next }));
            notifySuccess('Đã Gen lại');
        } catch (e) {
            notifyError(e?.message || 'Gen lại thất bại');
        }
    };

    const deleteOutput = (output) => {
        const next = seedOutputsRef.current.filter((o) => String(o.id) !== String(output.id));
        applyDoc({ seed_outputs: next });
    };

    const openReport = (output) => {
        setReportTarget({ topic: genTopic, comment: output });
    };

    const confirmReport = async ({ proof }) => {
        if (!reportTarget?.topic || !reportTarget?.comment) return;
        setReporting(true);
        try {
            const comment = reportTarget.comment;
            const topic = reportTarget.topic;
            const data = await submitReport({
                topic_id: topic.id,
                comment_text: comment.content,
                seed_link_id: comment.selected_seed_link_id || comment.seed_link_id,
                seed_url: comment.selected_seed_url || comment.url,
                proof,
            });

            const local = applyReportSuccessLocal({
                topics: topicsRef.current,
                generatedComments: seedOutputsRef.current,
                topicId: topic.id,
                commentId: comment.id,
                userReportCount: data.user_report_count,
                required: data.required_report_count,
                linkUsageToday: data.link_usage_today || linkUsageRef.current,
                seedLinkId: comment.selected_seed_link_id || comment.seed_link_id,
            });

            applyDoc({
                topics: local.topics,
                seed_outputs: local.generatedComments,
                link_usage_today: data.link_usage_today || local.linkUsageToday,
            }, false);
            writer.current.flush(() => persistNow({
                topics: local.topics,
                seed_outputs: local.generatedComments,
                link_usage_today: data.link_usage_today || local.linkUsageToday,
            }));

            setReportTarget(null);
            if (local.completed) {
                setGenTopicId(null);
                notifySuccess('Đã hoàn thành chủ đề');
            } else {
                notifySuccess('Đã báo cáo');
            }
        } catch (e) {
            notifyError(e?.message || 'Upload proof lỗi');
        } finally {
            setReporting(false);
        }
    };

    const toggleSidebar = () => {
        setSidebarCollapsed((v) => {
            schedulePersist();
            return !v;
        });
    };

    const shellClass = [
        'seeding-ws',
        'seeding-ws--feed',
        'seeding-ws--shell',
        !sidebarCollapsed ? 'has-sidebar' : 'sidebar-collapsed',
        (genTopicId || linkPoolOpen) ? 'has-drawer' : '',
    ].filter(Boolean).join(' ');

    return (
        <>
            {/* Outside grid — fixed Toaster as grid child steals the 1fr track */}
            <Toaster richColors position="top-right" />
            <div className={shellClass} data-storage-key={documentKey(scope)} data-layout="shell">
            <div className="seeding-ws__main-column">
                {detailTopic ? (
                    <TopicDetail
                        topic={detailTopic}
                        canMutate={canMutate}
                        canDelete={canDeleteTopic(
                            detailTopic,
                            userId,
                            canMutate,
                            reports,
                            topicHasWorkHistory,
                            { seed_batches: seedBatches, seed_outputs: seedOutputs },
                        )}
                        canEdit={canEditTopic(detailTopic, userId, canMutate)}
                        hasWorkspaceAccess={hasWorkspaceAccess}
                        userId={userId}
                        onBack={closeDetail}
                        onDelete={() => deleteTopic(detailTopic)}
                        onEdit={() => editTopic(detailTopic)}
                        onShare={() => {
                            if (canShareDraftTopic(detailTopic, userId, canMutate)) {
                                shareDraft(detailTopic);
                            } else {
                                openGen(detailTopic);
                            }
                        }}
                    />
                ) : (
                    <>
                        <header className="seeding-ws__page-head">
                            <div>
                                <h1 className="seeding-ws__page-title">Seeding</h1>
                                <p className="seeding-ws__page-sub">
                                    React-first — nháp local, chia sẻ DB, Gen comment local, báo cáo commit
                                </p>
                            </div>
                            <div className="seeding-ws__page-head-actions">
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
                            onFilter={(f) => { setFilter(f); schedulePersist(); }}
                            onSearch={(v) => { setSearch(v); schedulePersist(); }}
                            onCreate={openComposer}
                            onOpenLinkPool={() => { setLinkPoolOpen(true); setGenTopicId(null); }}
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

                        <TopicFeed
                            topics={filteredTopics}
                            reports={reports}
                            seedBatches={seedBatches}
                            seedOutputs={seedOutputs}
                            canMutate={canMutate}
                            hasWorkspaceAccess={hasWorkspaceAccess}
                            userId={userId}
                            linkPreviewCache={linkPreviews}
                            sharingTopicKey={sharingTopicKey}
                            onOpenDetail={openDetail}
                            onLinksChange={updateTopicLinks}
                            onCacheUpdate={updateLinkPreviewCache}
                            onEdit={editTopic}
                            onDelete={deleteTopic}
                            onShareDraft={shareDraft}
                            onGenComment={openGen}
                            onCreate={openComposer}
                        />
                    </>
                )}
            </div>

            <TeamStatsSidebar
                open
                collapsed={sidebarCollapsed}
                topics={topics}
                seedBatches={seedBatches}
                seedOutputs={seedOutputs}
                seedLinks={seedLinks}
                userId={userId}
                onToggleCollapse={toggleSidebar}
            />

            {linkPoolOpen ? (
                <aside className="seeding-ws__drawer" data-drawer="link-pool">
                    <LinkPoolPanel
                        open
                        seedLinks={seedLinks}
                        linkUsageToday={linkUsageToday}
                        canManage={canManageOwnSeedLinks(hasWorkspaceAccess)}
                        onClose={() => setLinkPoolOpen(false)}
                        onChange={onSeedLinksChange}
                    />
                </aside>
            ) : null}

            {genTopic ? (
                <aside className="seeding-ws__drawer" data-drawer="share-generate">
                    <ShareGeneratePanel
                        open
                        topic={genTopic}
                        seedLinks={seedLinks}
                        linkUsageToday={linkUsageToday}
                        seedOutputs={seedOutputs}
                        topicOutputs={topicOutputs}
                        linkPreviewCache={linkPreviews}
                        canSeed={canSeedTopic(genTopic, { hasWorkspaceAccess, userId })}
                        generating={generating}
                        onClose={closeGen}
                        onGenerate={runGenerate}
                        onUpdateOutput={updateOutput}
                        onRegenerateOutput={regenOutput}
                        onDeleteOutput={deleteOutput}
                        onReport={openReport}
                    />
                </aside>
            ) : null}

            </div>

            <ReportModal
                open={Boolean(reportTarget)}
                topic={reportTarget?.topic || null}
                comment={reportTarget?.comment || null}
                submitting={reporting}
                onClose={() => { if (!reporting) setReportTarget(null); }}
                onConfirm={confirmReport}
            />
        </>
    );
}
