import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import MetricCards from './components/MetricCards';
import FeedToolbar from './components/FeedToolbar';
import TopicFeed from './components/TopicFeed';
import TopicComposer from './components/TopicComposer';
import TopicDetail from './components/TopicDetail';
import TeamStatsSidebar from './components/TeamStatsSidebar';
import LinkPoolPanel from './components/LinkPoolPanel';
import ShareGeneratePanel from './components/ShareGeneratePanel';
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
    canManageOwnSeedLinks,
    canSeedTopic,
} from './features/workspace/auth';
import { generateSeedBatch, regenerateSeedOutput } from './services/seedGenerate';
import { normalizeSeedLinks } from './services/linkPool';

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
 * Flexible Seeding workspace — Topic signal → Chia sẻ → quantity → Gen.
 * Link Pool personal; soft daily limit; no claim / comment workflow.
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
    const hasWorkspaceAccess = true;

    const [topics, setTopics] = useState([]);
    const [reports, setReports] = useState([]);
    const [seedLinks, setSeedLinks] = useState([]);
    const [seedBatches, setSeedBatches] = useState([]);
    const [seedOutputs, setSeedOutputs] = useState([]);
    const [linkPreviews, setLinkPreviews] = useState({});
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [composerOpen, setComposerOpen] = useState(false);
    const [composer, setComposer] = useState(null);
    const [detailId, setDetailId] = useState(null);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [linkPoolOpen, setLinkPoolOpen] = useState(false);
    const [shareTopicId, setShareTopicId] = useState(null);
    const [generating, setGenerating] = useState(false);
    const [toast, setToast] = useState(null);

    const writer = useRef(createDebouncedWriter());
    const toastTimer = useRef(null);
    const topicsRef = useRef(topics);
    const reportsRef = useRef(reports);
    const seedLinksRef = useRef(seedLinks);
    const seedBatchesRef = useRef(seedBatches);
    const seedOutputsRef = useRef(seedOutputs);
    const linkPreviewsRef = useRef(linkPreviews);
    const uiRef = useRef({});

    useEffect(() => { topicsRef.current = topics; }, [topics]);
    useEffect(() => { reportsRef.current = reports; }, [reports]);
    useEffect(() => { seedLinksRef.current = seedLinks; }, [seedLinks]);
    useEffect(() => { seedBatchesRef.current = seedBatches; }, [seedBatches]);
    useEffect(() => { seedOutputsRef.current = seedOutputs; }, [seedOutputs]);
    useEffect(() => { linkPreviewsRef.current = linkPreviews; }, [linkPreviews]);
    useEffect(() => {
        uiRef.current = {
            filter,
            search,
            detail_topic_id: detailId,
            history_open: false,
            sidebar_collapsed: sidebarCollapsed,
            link_pool_open: linkPoolOpen,
            share_topic_id: shareTopicId,
        };
    }, [filter, search, detailId, sidebarCollapsed, linkPoolOpen, shareTopicId]);

    const showToast = useCallback((message) => {
        if (toastTimer.current) clearTimeout(toastTimer.current);
        setToast({ message });
        toastTimer.current = setTimeout(() => setToast(null), 3500);
    }, []);

    const persistNow = useCallback(( partial = {}) => {
        const current = readDocument(scope);
        writeDocument(scope, {
            ...current,
            topics: partial.topics ?? topicsRef.current,
            reports: partial.reports ?? reportsRef.current,
            seed_links: partial.seed_links ?? seedLinksRef.current,
            seed_batches: partial.seed_batches ?? seedBatchesRef.current,
            seed_outputs: partial.seed_outputs ?? seedOutputsRef.current,
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
        if (persist) schedulePersist();
    }, [schedulePersist]);

    useEffect(() => {
        const doc = readDocument(scope);
        const topicsRaw = Array.isArray(doc.topics) ? doc.topics : [];
        const topicsOwned = topicsRaw.map((t) => (
            t.created_by_user_id == null || t.created_by_user_id === ''
                ? { ...t, created_by_user_id: userId, created_by_display_name: t.created_by_display_name || userDisplayName }
                : t
        ));
        setTopics(topicsOwned);
        setReports(doc.reports || []);
        setSeedLinks(normalizeSeedLinks(doc.seed_links || []));
        setSeedBatches(doc.seed_batches || []);
        setSeedOutputs(doc.seed_outputs || []);
        setLinkPreviews(doc.link_previews && typeof doc.link_previews === 'object' ? doc.link_previews : {});
        setFilter(doc.ui?.filter || 'all');
        setSearch(doc.ui?.search || '');
        setDetailId(doc.ui?.detail_topic_id ? String(doc.ui.detail_topic_id) : null);
        setSidebarCollapsed(Boolean(doc.ui?.sidebar_collapsed));
        setLinkPoolOpen(Boolean(doc.ui?.link_pool_open));
        setShareTopicId(doc.ui?.share_topic_id ? String(doc.ui.share_topic_id) : null);
        setComposerOpen(false);
        setComposer(null);
        if (topicsOwned.some((t, i) => t !== topicsRaw[i])) {
            topicsRef.current = topicsOwned;
            writer.current.schedule(() => persistNow({ topics: topicsOwned }));
        }
    }, [scope, userId, userDisplayName, persistNow]);

    useEffect(() => () => {
        writer.current.cancel();
        if (toastTimer.current) clearTimeout(toastTimer.current);
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

    const shareTopic = useMemo(
        () => (shareTopicId ? topics.find((t) => topicKeyOf(t) === String(shareTopicId)) || null : null),
        [shareTopicId, topics],
    );

    const topicOutputs = useMemo(() => {
        if (!shareTopicId) return [];
        return seedOutputs
            .filter((o) => String(o.topic_id) === String(shareTopicId) && String(o.user_id) === String(userId))
            .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
    }, [seedOutputs, shareTopicId, userId]);

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
        setFilter('all');
        showToast('Đã tạo chủ đề');
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

    const updateTopicLinks = useCallback((topic, links) => {
        const key = topicKeyOf(topic);
        patchTopicByKey(key, (t) => ({ ...t, links }));
    }, [patchTopicByKey]);

    const openShare = (topic) => {
        if (!canSeedTopic(topic, { hasWorkspaceAccess })) return;
        setShareTopicId(topicKeyOf(topic));
        setLinkPoolOpen(false);
    };

    const closeShare = () => setShareTopicId(null);

    const deleteTopic = (topic) => {
        if (!topic || !canMutate) return;
        const extra = { seed_batches: seedBatchesRef.current, seed_outputs: seedOutputsRef.current };
        if (!canDeleteTopic(topic, userId, canMutate, reportsRef.current, topicHasWorkHistory, extra)) {
            showToast('Không có quyền xóa.');
            return;
        }
        if (topicHasWorkHistory(topic, reportsRef.current, extra)) {
            showToast('Không thể xóa — đã có lịch sử seeding.');
            return;
        }
        if (!window.confirm('Xóa chủ đề này?')) return;
        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.filter((t) => topicKeyOf(t) !== key);
        applyDoc({ topics: nextTopics });
        if (detailId === key) setDetailId(null);
        if (shareTopicId === key) setShareTopicId(null);
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

    const onSeedLinksChange = (nextLinks) => {
        if (!canManageOwnSeedLinks(hasWorkspaceAccess)) return;
        applyDoc({ seed_links: normalizeSeedLinks(nextLinks) });
    };

    const runGenerate = async (quantity) => {
        if (!shareTopic || !canSeedTopic(shareTopic, { hasWorkspaceAccess })) return;
        setGenerating(true);
        try {
            const { batch, outputs } = await generateSeedBatch({
                topic: shareTopic,
                userId,
                userDisplayName,
                quantity,
                seedLinks: seedLinksRef.current,
                existingOutputs: seedOutputsRef.current,
            });
            const now = new Date().toISOString();
            const key = topicKeyOf(shareTopic);
            const nextTopics = topicsRef.current.map((t) => (
                topicKeyOf(t) === key
                    ? { ...t, last_seeded_at: now, updated_at: now }
                    : t
            ));
            const nextBatches = [batch, ...seedBatchesRef.current];
            const nextOutputs = [...outputs, ...seedOutputsRef.current];
            applyDoc({
                topics: nextTopics,
                seed_batches: nextBatches,
                seed_outputs: nextOutputs,
            }, false);
            writer.current.flush(() => persistNow({
                topics: nextTopics,
                seed_batches: nextBatches,
                seed_outputs: nextOutputs,
            }));
            showToast(`Đã Gen ${outputs.length} nội dung`);
        } catch (e) {
            showToast(e?.message || 'Gen thất bại');
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
        if (!shareTopic) return;
        try {
            const updated = await regenerateSeedOutput({
                output,
                topic: shareTopic,
                seedLinks: seedLinksRef.current,
                existingOutputs: seedOutputsRef.current,
                rerandomLink: false,
            });
            const next = seedOutputsRef.current.map((o) => (
                String(o.id) === String(updated.id) ? updated : o
            ));
            applyDoc({ seed_outputs: next }, false);
            writer.current.flush(() => persistNow({ seed_outputs: next }));
            showToast('Đã Gen lại');
        } catch (e) {
            showToast(e?.message || 'Gen lại thất bại');
        }
    };

    const deleteOutput = (output) => {
        const next = seedOutputsRef.current.filter((o) => String(o.id) !== String(output.id));
        applyDoc({ seed_outputs: next });
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
        (shareTopicId || linkPoolOpen) ? 'has-drawer' : '',
    ].filter(Boolean).join(' ');

    return (
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
                        onBack={closeDetail}
                        onDelete={() => deleteTopic(detailTopic)}
                        onEdit={() => editTopic(detailTopic)}
                        onShare={() => openShare(detailTopic)}
                    />
                ) : (
                    <>
                        <header className="seeding-ws__page-head">
                            <div>
                                <h1 className="seeding-ws__page-title">Seeding</h1>
                                <p className="seeding-ws__page-sub">
                                    Flexible Seeding — chọn topic trending, Gen nội dung từ Link Pool của bạn
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
                            onOpenLinkPool={() => { setLinkPoolOpen(true); setShareTopicId(null); }}
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
                            onOpenDetail={openDetail}
                            onLinksChange={updateTopicLinks}
                            onCacheUpdate={updateLinkPreviewCache}
                            onEdit={editTopic}
                            onDelete={deleteTopic}
                            onShare={openShare}
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
                        seedOutputs={seedOutputs}
                        canManage={canManageOwnSeedLinks(hasWorkspaceAccess)}
                        onClose={() => setLinkPoolOpen(false)}
                        onChange={onSeedLinksChange}
                    />
                </aside>
            ) : null}

            {shareTopic ? (
                <aside className="seeding-ws__drawer" data-drawer="share-generate">
                    <ShareGeneratePanel
                        open
                        topic={shareTopic}
                        seedLinks={seedLinks}
                        seedOutputs={seedOutputs}
                        topicOutputs={topicOutputs}
                        linkPreviewCache={linkPreviews}
                        canSeed={canSeedTopic(shareTopic, { hasWorkspaceAccess })}
                        generating={generating}
                        onClose={closeShare}
                        onGenerate={runGenerate}
                        onUpdateOutput={updateOutput}
                        onRegenerateOutput={regenOutput}
                        onDeleteOutput={deleteOutput}
                    />
                </aside>
            ) : null}

            {toast ? <div className="seeding-ws__toast">{toast.message}</div> : null}
        </div>
    );
}
