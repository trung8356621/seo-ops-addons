import { auditT } from '../i18n-audit.js';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Toaster } from 'sonner';
import MetricCards from './components/MetricCards';
import FeedToolbar from './components/FeedToolbar';
import TopicFeed from './components/TopicFeed';
import TopicComposer from './components/TopicComposer';
import TopicDetail from './components/TopicDetail';
import SeedingSidebar from './components/SeedingSidebar';
import ReportModal from './components/ReportModal';
import ManagerPanel from './components/ManagerPanel';
import WebsiteShareFeed from './components/WebsiteShareFeed';
import SeederQuickFeed from './components/SeederQuickFeed';
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
    canCreateTopic,
} from './features/workspace/auth';
import { generateSeedBatch, regenerateSeedOutput } from './services/seedGenerate';
import { normalizeSeedLinks } from './services/linkPool';
import {
    bumpAssignmentProgress,
    normalizeDailyLinkProgress,
    todayProgressMap,
} from './services/dailyLinkProgress';
import { fetchSharedFeed, fetchLinkAssignments, shareTopic as shareTopicApi, submitReport } from './api';
import {
    applyReportSuccessLocal,
    mergeSharedFeed,
    removeDraftTopic,
    restoreDraftTopic,
} from './services/shareFeed';
import { notifyError, notifySuccess } from './services/toast';

const ROLE_MANAGER = 'seeding.manager';
const ROLE_TOPIC_CREATOR = 'seeding.topic_creator';
const ROLE_SEEDER = 'seeding.seeder';

function resolveSeedingRole(bootstrap, manager) {
    const raw = String(bootstrap?.user?.role || '').trim().toLowerCase();
    if (raw === ROLE_MANAGER || raw === ROLE_TOPIC_CREATOR || raw === ROLE_SEEDER) {
        return raw;
    }
    // Legacy short roles from older bootstrap payloads
    if (raw === 'manager') return ROLE_MANAGER;
    if (raw === 'topic_creator') return ROLE_TOPIC_CREATOR;
    if (raw === 'seeder') return ROLE_SEEDER;
    return manager ? ROLE_MANAGER : ROLE_SEEDER;
}
function emptyComposerDraft() {
    return {
        localId: makeLocalDraftId(),
        title: '',
        full_text: '',
        social_url: '',
        social_platform: 'facebook',
        target_comments: 5,
        social_targets: [{ social_platform: 'facebook', target_comments: 5 }],
        links: [],
        source_html: null,
        _mode: 'create',
    };
}

/**
 * React-owned Seeding workspace — local-first UX, DB only at commit points.
 */
export default function SeedingWorkspace({
    canMutate = true,
    isManager = false,
    canCreateTopic: canCreateTopicProp = null,
    bootstrap = null,
}) {
    const installationId = bootstrap?.client?.installation_id || 'app:local';
    const userId = bootstrap?.user?.id ?? 0;
    const userDisplayName = bootstrap?.user?.display_name || '';
    const manager = Boolean(
        isManager
        || bootstrap?.user?.is_manager
        || bootstrap?.permissions?.is_manager
    );
    const seedingRole = resolveSeedingRole(bootstrap, manager);
    const isTopicCreatorRole = seedingRole === ROLE_TOPIC_CREATOR;
    const isSeederQuickFeed = seedingRole === ROLE_SEEDER && !manager;
    const allowCreate = canCreateTopicProp != null
        ? Boolean(canCreateTopicProp)
        : canCreateTopic(manager, canMutate, {
            seedingRole,
            isTopicCreator: isTopicCreatorRole,
        });
    const scope = useMemo(() => ({ installationId, userId }), [installationId, userId]);
    const hasWorkspaceAccess = true;

    const [mainTab, setMainTab] = useState('feed');
    const [topics, setTopics] = useState([]);
    const [reports, setReports] = useState([]);
    const [seedLinks, setSeedLinks] = useState([]);
    /** FLOW B — installation shared assignments (not Topic.links) */
    const [sharedAssignments, setSharedAssignments] = useState([]);
    const [seedBatches, setSeedBatches] = useState([]);
    const [seedOutputs, setSeedOutputs] = useState([]);
    const [linkPreviews, setLinkPreviews] = useState({});
    const [linkUsageToday, setLinkUsageToday] = useState({});
    const [dailyLinkProgress, setDailyLinkProgress] = useState({});
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [composerOpen, setComposerOpen] = useState(false);
    const [composer, setComposer] = useState(null);
    const [detailId, setDetailId] = useState(null);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [linkPoolOpen, setLinkPoolOpen] = useState(false);
    const [activeGenTopicId, setActiveGenTopicId] = useState(null);
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
    const dailyProgressRef = useRef(dailyLinkProgress);
    const uiRef = useRef({});
    const feedAbortRef = useRef(null);

    useEffect(() => { topicsRef.current = topics; }, [topics]);
    useEffect(() => { reportsRef.current = reports; }, [reports]);
    useEffect(() => { seedLinksRef.current = seedLinks; }, [seedLinks]);
    useEffect(() => { seedBatchesRef.current = seedBatches; }, [seedBatches]);
    useEffect(() => { seedOutputsRef.current = seedOutputs; }, [seedOutputs]);
    useEffect(() => { linkPreviewsRef.current = linkPreviews; }, [linkPreviews]);
    useEffect(() => { linkUsageRef.current = linkUsageToday; }, [linkUsageToday]);
    useEffect(() => { dailyProgressRef.current = dailyLinkProgress; }, [dailyLinkProgress]);
    useEffect(() => {
        uiRef.current = {
            filter,
            search,
            detail_topic_id: detailId,
            history_open: false,
            sidebar_collapsed: sidebarCollapsed,
            link_pool_open: linkPoolOpen,
            share_topic_id: activeGenTopicId,
        };
    }, [filter, search, detailId, sidebarCollapsed, linkPoolOpen, activeGenTopicId]);

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
            daily_link_progress: partial.daily_link_progress ?? dailyProgressRef.current,
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
        if (patch.daily_link_progress) {
            const next = normalizeDailyLinkProgress(patch.daily_link_progress);
            dailyProgressRef.current = next;
            setDailyLinkProgress(next);
        }
        if (persist) schedulePersist();
    }, [schedulePersist]);

    const refreshSharedAssignments = useCallback(async () => {
        try {
            const data = await fetchLinkAssignments(true, 'shared');
            setSharedAssignments(Array.isArray(data?.assignments) ? data.assignments : []);
        } catch {
            setSharedAssignments([]);
        }
    }, []);

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
            await refreshSharedAssignments();
        } catch (e) {
            if (e?.name === 'AbortError') return;
            // Soft fail — local drafts still usable
        }
    }, [applyDoc, persistNow, userId, refreshSharedAssignments]);

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
        setDailyLinkProgress(normalizeDailyLinkProgress(doc.daily_link_progress));
        setFilter(doc.ui?.filter || 'all');
        setSearch(doc.ui?.search || '');
        setDetailId(doc.ui?.detail_topic_id ? String(doc.ui.detail_topic_id) : null);
        setSidebarCollapsed(Boolean(doc.ui?.sidebar_collapsed));
        setLinkPoolOpen(Boolean(doc.ui?.link_pool_open) && seedingRole !== ROLE_SEEDER);
        setActiveGenTopicId(doc.ui?.share_topic_id ? String(doc.ui.share_topic_id) : null);
        setComposerOpen(false);
        setComposer(null);
        refreshFeed();
        const timer = setInterval(() => refreshFeed(), 60000);
        return () => {
            clearInterval(timer);
            if (feedAbortRef.current) feedAbortRef.current.abort();
        };
    }, [scope, userId, userDisplayName, refreshFeed]);

    useEffect(() => {
        let cancelled = false;
        fetchLinkAssignments(true, 'shared')
            .then((data) => {
                if (cancelled) return;
                setSharedAssignments(Array.isArray(data?.assignments) ? data.assignments : []);
            })
            .catch(() => {
                if (!cancelled) setSharedAssignments([]);
            });
        return () => { cancelled = true; };
    }, [hasWorkspaceAccess]);

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
        () => (activeGenTopicId ? topics.find((t) => topicKeyOf(t) === String(activeGenTopicId)) || null : null),
        [activeGenTopicId, topics],
    );

    const dailyProgressToday = useMemo(
        () => todayProgressMap(dailyLinkProgress),
        [dailyLinkProgress],
    );

    const outputsForTopic = useCallback((topic) => {
        if (!topic) return [];
        const key = topicKeyOf(topic);
        const topicId = topic.id;
        return seedOutputs
            .filter((o) => String(o.topic_id) === String(key) || String(o.topic_id) === String(topicId))
            .filter((o) => String(o.user_id) === String(userId))
            .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
    }, [seedOutputs, userId]);

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
        if (!allowCreate) return;
        setComposer(emptyComposerDraft());
        setComposerOpen(true);
        setMainTab('feed');
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

    const createTopic = async () => {
        if (!composer || !allowCreate) return;
        const fullText = String(composer.full_text || '').trim();
        if (!fullText) return;
        const socialTargets = Array.isArray(composer.social_targets) && composer.social_targets.length > 0
            ? composer.social_targets
            : [{
                social_platform: composer.social_platform || 'facebook',
                target_comments: Number(composer.target_comments) || 5,
            }];

        setSharingTopicKey('create');
        try {
            const data = await shareTopicApi({
                title: String(composer.title || '').trim(),
                full_text: fullText,
                source_html: composer.source_html || null,
                social_url: String(composer.social_url || '').trim(),
                social_targets: socialTargets,
                // FLOW A independent of FLOW B — do not attach shared assignments to Topic.
                links: [],
                idempotency_key: `create:${String(composer.localId || composer.id || Date.now())}`,
            });
            const createdCount = Array.isArray(data?.topics) ? data.topics.length : 1;
            notifySuccess(createdCount > 1
                ? `Đã tạo ${createdCount} topic execution`
                : auditT('audit_a723fc6953c7'));
            setComposerOpen(false);
            setComposer(null);
            await refreshFeed();
        } catch (e) {
            notifyError(e?.message || auditT('audit_b61260373785'));
        } finally {
            setSharingTopicKey(null);
        }
    };

    const saveEditedTopic = () => {
        if (!composer || composer._mode !== 'edit' || !canMutate) return;
        const key = String(composer.localId || composer.id);
        const target = topicsRef.current.find((t) => topicKeyOf(t) === key);
        if (!target || !canEditTopic(target, userId, canMutate)) {
            notifyError(auditT('audit_ca516fe78556'));
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
        notifySuccess(auditT('audit_4cbe2a406b3d'));
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
        const key = topicKeyOf(topic);
        setActiveGenTopicId((prev) => (prev === key ? null : key));
        setLinkPoolOpen(false);
    };

    const closeGen = () => setActiveGenTopicId(null);

    const shareDraft = async (topic) => {
        if (!canShareDraftTopic(topic, userId, canMutate, manager, {
            seedingRole,
            isTopicCreator: isTopicCreatorRole,
        })) return;
        const key = topicKeyOf(topic);
        const draftSnapshot = { ...topic };

        setSharingTopicKey(key);
        // Keep local draft until server confirms success (no optimistic remove).
        try {
            const idempotencyKey = `share:${key}`;
            await shareTopicApi({
                title: topic.title || '',
                full_text: topic.full_text || '',
                source_html: topic.source_html || null,
                social_url: topic.social_url || '',
                social_platform: topic.social_platform || undefined,
                target_comments: topic.target_comments || undefined,
                social_targets: topic.social_targets || undefined,
                links: [],
                idempotency_key: idempotencyKey,
            });
            const optimistic = removeDraftTopic(topicsRef.current, key);
            applyDoc({ topics: optimistic }, false);
            writer.current.flush(() => persistNow({ topics: optimistic }));
            notifySuccess(auditT('audit_a723fc6953c7'));
            await refreshFeed();
        } catch (e) {
            // Failure: draft remains / is restored for the creating user.
            const stillThere = topicsRef.current.some((t) => topicKeyOf(t) === key);
            if (!stillThere) {
                const rolled = restoreDraftTopic(topicsRef.current, draftSnapshot, 0);
                applyDoc({ topics: rolled }, false);
                writer.current.flush(() => persistNow({ topics: rolled }));
            }
            notifyError(e?.message || auditT('audit_f1ca20a1a5ec'));
        } finally {
            setSharingTopicKey(null);
        }
    };

    const deleteTopic = (topic) => {
        if (!topic || !canMutate) return;
        const extra = { seed_batches: seedBatchesRef.current, seed_outputs: seedOutputsRef.current };
        if (!canDeleteTopic(topic, userId, canMutate, reportsRef.current, topicHasWorkHistory, extra)) {
            notifyError(auditT('audit_67f31a32c0be'));
            return;
        }
        if (!window.confirm(auditT('audit_6f925a8050bf'))) return;
        const key = topicKeyOf(topic);
        const nextTopics = topicsRef.current.filter((t) => topicKeyOf(t) !== key);
        applyDoc({ topics: nextTopics });
        if (detailId === key) setDetailId(null);
        if (activeGenTopicId === key) setActiveGenTopicId(null);
        notifySuccess(auditT('audit_09ca0c041eb7'));
    };

    const editTopic = (topic) => {
        if (!canEditTopic(topic, userId, canMutate)) {
            notifyError(auditT('audit_3ddb26ccb11b'));
            return;
        }
        setComposer({
            localId: topic.localId || topic.id,
            title: topic.title || '',
            full_text: topic.full_text || '',
            social_url: topic.social_url || '',
            links: [],
            source_html: null,
            _mode: 'edit',
        });
        setComposerOpen(true);
        setDetailId(null);
    };

    const onSeedLinksChange = (nextLinks, toastMsg) => {
        if (!canManageOwnSeedLinks(hasWorkspaceAccess, {
            seedingRole,
            isManager: manager,
            isTopicCreator: isTopicCreatorRole,
        })) return;
        // Legacy local cache only — Creator SoT is DB assignments.
        applyDoc({ seed_links: normalizeSeedLinks(nextLinks) });
        if (toastMsg) notifySuccess(toastMsg);
    };

    const onAssignmentsChange = () => {
        // Creator CRUD updated DB — reload installation shared list (do not use mine rows for Copy).
        void refreshSharedAssignments();
    };

    const runGenerate = async (quantity) => {
        if (!genTopic || !canSeedTopic(genTopic, { hasWorkspaceAccess, userId })) return;
        if (generating) return;
        setGenerating(true);
        try {
            const { batch, outputs } = await generateSeedBatch({
                topic: genTopic,
                userId,
                userDisplayName,
                quantity,
                linkPreviewCache: linkPreviews,
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
            notifyError(e?.message || auditT('audit_f2981c392d8e'));
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
                linkPreviewCache: linkPreviews,
            });
            const next = seedOutputsRef.current.map((o) => (
                String(o.id) === String(updated.id) ? updated : o
            ));
            applyDoc({ seed_outputs: next }, false);
            writer.current.flush(() => persistNow({ seed_outputs: next }));
            notifySuccess(auditT('audit_5e129df2b4d7'));
        } catch (e) {
            notifyError(e?.message || auditT('audit_dbfa21e6dbdd'));
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
                topicDone: data.topic_done,
                globalCompleted: data.global_completed,
                globalTarget: data.global_target,
                linkUsageToday: data.link_usage_today || linkUsageRef.current,
                seedLinkId: comment.selected_seed_link_id || comment.seed_link_id,
            });

            const assignmentId = comment.selected_seed_link_id || comment.seed_link_id;
            const nextDaily = assignmentId
                ? bumpAssignmentProgress(dailyProgressRef.current, String(assignmentId), 1)
                : dailyProgressRef.current;

            applyDoc({
                topics: local.topics,
                seed_outputs: local.generatedComments,
                link_usage_today: data.link_usage_today || local.linkUsageToday,
                daily_link_progress: nextDaily,
            }, false);
            writer.current.flush(() => persistNow({
                topics: local.topics,
                seed_outputs: local.generatedComments,
                link_usage_today: data.link_usage_today || local.linkUsageToday,
                daily_link_progress: nextDaily,
            }));

            setReportTarget(null);
            if (local.completed) {
                setActiveGenTopicId(null);
                notifySuccess(auditT('audit_7daf773629a9'));
            } else {
                notifySuccess(auditT('audit_f94c45936959'));
            }
        } catch (e) {
            notifyError(e?.message || auditT('audit_6d73b336ef3b'));
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
    ].filter(Boolean).join(' ');

    const canManageLinkPool = canManageOwnSeedLinks(hasWorkspaceAccess, {
        seedingRole,
        isManager: manager,
        isTopicCreator: isTopicCreatorRole,
    });

    const openLinkPool = () => {
        if (!canManageLinkPool) return;
        setLinkPoolOpen(true);
        if (sidebarCollapsed) {
            setSidebarCollapsed(false);
            schedulePersist();
        }
    };
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
                            if (canShareDraftTopic(detailTopic, userId, canMutate, manager, {
                                seedingRole,
                                isTopicCreator: isTopicCreatorRole,
                            })) {
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
                                    {isSeederQuickFeed
                                        ? 'Quick feed — comment & share website'
                                        : auditT('audit_b80ccd1e64bf')}
                                </p>
                            </div>
                        </header>

                        {!isSeederQuickFeed ? (
                            <nav className="seeding-ws__main-tabs" data-main-tabs>
                                <button
                                    type="button"
                                    className={`seeding-ws__main-tab${mainTab === 'feed' ? ' is-active' : ''}`}
                                    onClick={() => setMainTab('feed')}
                                >
                                    Feed Seeding
                                </button>
                                <button
                                    type="button"
                                    className={`seeding-ws__main-tab${mainTab === 'website' ? ' is-active' : ''}`}
                                    onClick={() => setMainTab('website')}
                                >
                                    Bài từ Website
                                </button>
                                {manager ? (
                                    <button
                                        type="button"
                                        className={`seeding-ws__main-tab${mainTab === 'manage' ? ' is-active' : ''}`}
                                        onClick={() => setMainTab('manage')}
                                    >
                                        Quản lý / Tổng kết
                                    </button>
                                ) : null}
                            </nav>
                        ) : null}

                        {isSeederQuickFeed || mainTab === 'feed' ? (
                            <>
                                {!isSeederQuickFeed ? <MetricCards metrics={metrics} /> : null}

                                {!isSeederQuickFeed ? (
                                    <FeedToolbar
                                        filter={filter}
                                        search={search}
                                        counts={counts}
                                        canMutate={allowCreate}
                                        onFilter={(f) => { setFilter(f); schedulePersist(); }}
                                        onSearch={(v) => { setSearch(v); schedulePersist(); }}
                                        onCreate={openComposer}
                                        onOpenLinkPool={canManageLinkPool ? openLinkPool : undefined}
                                    />
                                ) : null}

                                {composerOpen && composer ? (
                                    <TopicComposer
                                        topic={composer}
                                        canMutate={allowCreate}
                                        mode={composer._mode === 'edit' ? 'edit' : 'create'}
                                        onChange={patchComposer}
                                        onPasteContent={onPasteContent}
                                        onCancel={cancelComposer}
                                        onCreate={composer._mode === 'edit' ? saveEditedTopic : createTopic}
                                    />
                                ) : null}

                                {isSeederQuickFeed ? (
                                    <SeederQuickFeed
                                        topics={filteredTopics}
                                        reports={reports}
                                        seedBatches={seedBatches}
                                        seedOutputs={seedOutputs}
                                        canMutate={canMutate}
                                        hasWorkspaceAccess={hasWorkspaceAccess}
                                        isManager={manager}
                                        userId={userId}
                                        linkPreviewCache={linkPreviews}
                                        dailyProgressMap={dailyProgressToday}
                                        sharingTopicKey={sharingTopicKey}
                                        activeGenTopicId={activeGenTopicId}
                                        dailyLinkProgress={dailyLinkProgress}
                                        sharedAssignments={sharedAssignments}
                                        generating={generating}
                                        outputsForTopic={outputsForTopic}
                                        canSeedTopicFn={(t) => canSeedTopic(t, { hasWorkspaceAccess, userId })}
                                        onOpenDetail={openDetail}
                                        onLinksChange={updateTopicLinks}
                                        onCacheUpdate={updateLinkPreviewCache}
                                        onEdit={editTopic}
                                        onDelete={deleteTopic}
                                        onShareDraft={shareDraft}
                                        onGenComment={openGen}
                                        onCloseGen={closeGen}
                                        onGenerate={runGenerate}
                                        onUpdateOutput={updateOutput}
                                        onRegenerateOutput={regenOutput}
                                        onDeleteOutput={deleteOutput}
                                        onReport={openReport}
                                    />
                                ) : (
                                    <TopicFeed
                                        topics={filteredTopics}
                                        reports={reports}
                                        seedBatches={seedBatches}
                                        seedOutputs={seedOutputs}
                                        canMutate={canMutate}
                                        hasWorkspaceAccess={hasWorkspaceAccess}
                                        isManager={manager}
                                        userId={userId}
                                        linkPreviewCache={linkPreviews}
                                        dailyProgressMap={dailyProgressToday}
                                        sharingTopicKey={sharingTopicKey}
                                        activeGenTopicId={activeGenTopicId}
                                        dailyLinkProgress={dailyLinkProgress}
                                        sharedAssignments={sharedAssignments}
                                        generating={generating}
                                        outputsForTopic={outputsForTopic}
                                        canSeedTopicFn={(t) => canSeedTopic(t, { hasWorkspaceAccess, userId })}
                                        onOpenDetail={openDetail}
                                        onLinksChange={updateTopicLinks}
                                        onCacheUpdate={updateLinkPreviewCache}
                                        onEdit={editTopic}
                                        onDelete={deleteTopic}
                                        onShareDraft={shareDraft}
                                        onGenComment={openGen}
                                        onCreate={openComposer}
                                        onCloseGen={closeGen}
                                        onGenerate={runGenerate}
                                        onUpdateOutput={updateOutput}
                                        onRegenerateOutput={regenOutput}
                                        onDeleteOutput={deleteOutput}
                                        onReport={openReport}
                                    />
                                )}
                            </>
                        ) : null}

                        {!isSeederQuickFeed && mainTab === 'website' ? (
                            <WebsiteShareFeed canMutate={canMutate} />
                        ) : null}

                        {!isSeederQuickFeed && mainTab === 'manage' && manager ? (
                            <ManagerPanel />
                        ) : null}
                    </>
                )}
            </div>

            <SeedingSidebar
                open
                collapsed={sidebarCollapsed}
                topics={topics}
                seedBatches={seedBatches}
                seedOutputs={seedOutputs}
                seedLinks={seedLinks}
                sharedAssignments={sharedAssignments}
                dailyProgressMap={dailyProgressToday}
                userId={userId}
                linkPoolOpen={linkPoolOpen}
                canManageLinkPool={canManageLinkPool}
                onToggleCollapse={toggleSidebar}
                onOpenLinkPool={openLinkPool}
                onCloseLinkPool={() => setLinkPoolOpen(false)}
                onAssignmentsChange={onAssignmentsChange}
            />

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
