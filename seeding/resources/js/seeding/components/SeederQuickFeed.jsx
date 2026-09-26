import { auditT } from '../../i18n-audit.js';
import React, { useEffect, useMemo, useState } from 'react';
import TopicCard from './TopicCard';
import ShareGeneratePanel from './ShareGeneratePanel';
import WebsiteShareCard from './WebsiteShareCard';
import WebsiteShareGeneratePanel from './WebsiteShareGeneratePanel';
import { topicKeyOf } from '../services/storage';
import {
    fetchWebsiteShareFeed,
    generateWebsiteShareContent,
    updateWebsiteShareTargetContent,
    reportWebsiteShare,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';
import { writeClipboard } from '../services/clipboardWrite';

/**
 * Presentation-only merge of Topic Comment + actionable Website Share items.
 * Does NOT merge DB models or backend services.
 */
function workSortKey(item) {
    if (item.kind === 'topic') {
        const t = item.topic;
        return String(t.updated_at || t.shared_at || t.created_at || '');
    }
    const j = item.job;
    return String(j.updated_at || j.indexed_at || j.created_at || '');
}

/**
 * @param {Record<string, unknown>} job
 */
export function isWebsiteShareActionable(job) {
    const status = String(job?.status || '');
    if (status === 'scheduled') return false;
    if (status === 'completed' || status === 'done') return false;
    return true;
}

/**
 * Seeder-only unified quick feed (2-column desktop via CSS modifier).
 */
export default function SeederQuickFeed({
    topics,
    reports,
    seedBatches = [],
    seedOutputs = [],
    canMutate,
    hasWorkspaceAccess = true,
    isManager = false,
    userId,
    linkPreviewCache = {},
    dailyProgressMap = {},
    sharingTopicKey = null,
    activeGenTopicId = null,
    dailyLinkProgress = {},
    sharedAssignments = [],
    generating = false,
    outputsForTopic,
    canSeedTopicFn,
    onOpenDetail,
    onLinksChange,
    onCacheUpdate,
    onEdit,
    onDelete,
    onShareDraft,
    onGenComment,
    onCloseGen,
    onGenerate,
    onUpdateOutput,
    onRegenerateOutput,
    onDeleteOutput,
    onReport,
}) {
    const [jobs, setJobs] = useState([]);
    const [loadingJobs, setLoadingJobs] = useState(false);
    const [editingTargetId, setEditingTargetId] = useState(null);
    const [draft, setDraft] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [activeWebsiteShareId, setActiveWebsiteShareId] = useState(null);

    const loadJobs = async () => {
        setLoadingJobs(true);
        try {
            const data = await fetchWebsiteShareFeed('all');
            const list = Array.isArray(data?.jobs) ? data.jobs : [];
            setJobs(list.filter(isWebsiteShareActionable));
        } catch (e) {
            // Soft fail — topic feed still usable
            if (e?.name !== 'AbortError') {
                notifyError(e?.message || auditT('audit_95bfe594faba'));
            }
        } finally {
            setLoadingJobs(false);
        }
    };

    useEffect(() => {
        loadJobs();
        const timer = setInterval(loadJobs, 60000);
        return () => clearInterval(timer);
    }, []);

    const items = useMemo(() => {
        /** @type {Array<{ kind: 'topic'|'website', id: string, topic?: any, job?: any }>} */
        const merged = [];
        for (const topic of topics || []) {
            merged.push({
                kind: 'topic',
                id: `topic:${topicKeyOf(topic)}`,
                topic,
            });
        }
        for (const job of jobs) {
            merged.push({
                kind: 'website',
                id: `ws:${job.id}`,
                job,
            });
        }
        merged.sort((a, b) => workSortKey(b).localeCompare(workSortKey(a)));
        return merged;
    }, [topics, jobs]);

    const onGen = async (job) => {
        if (!canMutate || job.status === 'scheduled') return;
        setBusyId(job.id);
        try {
            const updated = await generateWebsiteShareContent(job.id);
            notifySuccess(auditT('audit_45ec01253919'));
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
        } catch (e) {
            notifyError(e?.message || auditT('audit_f2981c392d8e'));
        } finally {
            setBusyId(null);
        }
    };

    const onSaveEdit = async (job, target) => {
        setBusyId(job.id);
        try {
            const updated = await updateWebsiteShareTargetContent(job.id, target.id, draft);
            notifySuccess(auditT('audit_157f98ec42b5'));
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
            setEditingTargetId(null);
        } catch (e) {
            notifyError(e?.message || auditT('audit_542811fc4ac4'));
        } finally {
            setBusyId(null);
        }
    };

    const onCopy = async (target) => {
        const text = String(target.share_content || '').trim();
        if (!text) {
            notifyError(auditT('audit_bdd3db004085'));
            return;
        }
        try {
            const result = await writeClipboard(text);
            if (result.ok) notifySuccess(auditT('audit_a4d46c3b06b1'));
            else notifyError(result.error || auditT('audit_1cdc97b3a598'));
        } catch {
            notifyError(auditT('audit_1cdc97b3a598'));
        }
    };

    const onWsReport = async (job, target) => {
        if (!canMutate) return;
        setBusyId(job.id);
        try {
            const updated = await reportWebsiteShare(job.id, {
                social: target.social,
            });
            notifySuccess(auditT('audit_1836afcb4f7d'));
            const next = updated?.job || job;
            if (!isWebsiteShareActionable(next)) {
                setJobs((prev) => prev.filter((j) => j.id !== job.id));
            } else {
                setJobs((prev) => prev.map((j) => (j.id === job.id ? next : j)));
            }
        } catch (e) {
            notifyError(e?.message || auditT('audit_8902b7e36887'));
        } finally {
            setBusyId(null);
        }
    };

    if (items.length === 0) {
        return (
            <div className="seeding-ws__empty-feed" data-seeder-quick-feed-empty>
                <p>{loadingJobs ? auditT('audit_577a1d591051') : auditT('audit_900d4c9de367')}</p>
            </div>
        );
    }

    return (
        <div className="seeding-ws__feed-host is-seeder-quick-feed" data-feed-host data-seeder-quick-feed>
            <div className="seeding-ws__feed-grid" data-feed="seeder-quick">
                {items.map((item) => {
                    if (item.kind === 'website') {
                        const job = item.job;
                        const wsOpen = String(activeWebsiteShareId) === String(job.id);
                        return (
                            <div key={item.id} className={`seeding-ws__feed-item${wsOpen ? ' is-gen-open' : ''}`} data-feed-item data-item-kind="website" data-gen-open={wsOpen ? '1' : '0'}>
                                <WebsiteShareCard
                                    job={job}
                                    canMutate={canMutate}
                                    busy={busyId === job.id}
                                    genOpen={wsOpen}
                                    onGenToggle={(current) => setActiveWebsiteShareId(wsOpen ? null : current.id)}
                                />
                                {wsOpen ? (
                                    <WebsiteShareGeneratePanel
                                        open
                                        job={job}
                                        canMutate={canMutate}
                                        busy={busyId === job.id}
                                        editingTargetId={editingTargetId}
                                        draft={draft}
                                        onDraftChange={setDraft}
                                        onGenerate={onGen}
                                        onStartEdit={(target) => { setEditingTargetId(target.id); setDraft(target.share_content || ''); }}
                                        onSaveEdit={onSaveEdit}
                                        onCancelEdit={() => setEditingTargetId(null)}
                                        onCopy={onCopy}
                                        onReport={onWsReport}
                                        onClose={() => setActiveWebsiteShareId(null)}
                                    />
                                ) : null}
                            </div>
                        );
                    }

                    const topic = item.topic;
                    const id = topicKeyOf(topic);
                    const genOpen = activeGenTopicId != null && String(activeGenTopicId) === String(id);
                    const topicOutputs = typeof outputsForTopic === 'function'
                        ? outputsForTopic(topic)
                        : [];
                    const canSeed = typeof canSeedTopicFn === 'function'
                        ? canSeedTopicFn(topic)
                        : false;

                    return (
                        <div
                            key={item.id}
                            className={`seeding-ws__feed-item${genOpen ? ' is-gen-open' : ''}`}
                            data-feed-item
                            data-item-kind="topic"
                            data-gen-open={genOpen ? '1' : '0'}
                        >
                            <TopicCard
                                topic={topic}
                                reports={reports}
                                seedBatches={seedBatches}
                                seedOutputs={seedOutputs}
                                canMutate={canMutate}
                                hasWorkspaceAccess={hasWorkspaceAccess}
                                isManager={isManager}
                                userId={userId}
                                linkPreviewCache={linkPreviewCache}
                                dailyProgressMap={dailyProgressMap}
                                sharing={sharingTopicKey === id}
                                genOpen={genOpen}
                                onOpenDetail={onOpenDetail}
                                onLinksChange={onLinksChange}
                                onCacheUpdate={onCacheUpdate}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                onShareDraft={onShareDraft}
                                onGenComment={onGenComment}
                            />
                            {genOpen ? (
                                <ShareGeneratePanel
                                    open
                                    inline
                                    topic={topic}
                                    sharedAssignments={sharedAssignments}
                                    dailyLinkProgress={dailyLinkProgress}
                                    topicOutputs={topicOutputs}
                                    linkPreviewCache={linkPreviewCache}
                                    canSeed={canSeed}
                                    generating={generating}
                                    onClose={onCloseGen}
                                    onGenerate={onGenerate}
                                    onUpdateOutput={onUpdateOutput}
                                    onRegenerateOutput={onRegenerateOutput}
                                    onDeleteOutput={onDeleteOutput}
                                    onReport={onReport}
                                />
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
