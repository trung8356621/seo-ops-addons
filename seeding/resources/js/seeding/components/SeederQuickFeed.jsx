import React, { useEffect, useMemo, useState } from 'react';
import TopicCard from './TopicCard';
import ShareGeneratePanel from './ShareGeneratePanel';
import WebsiteShareCard from './WebsiteShareCard';
import { topicKeyOf } from '../services/storage';
import {
    fetchWebsiteShareFeed,
    updateWebsiteShareContent,
    reportWebsiteShare,
    generateSampleComments,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';

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
    const [editingId, setEditingId] = useState(null);
    const [draft, setDraft] = useState('');
    const [busyId, setBusyId] = useState(null);

    const loadJobs = async () => {
        setLoadingJobs(true);
        try {
            const data = await fetchWebsiteShareFeed('all');
            const list = Array.isArray(data?.jobs) ? data.jobs : [];
            setJobs(list.filter(isWebsiteShareActionable));
        } catch (e) {
            // Soft fail — topic feed still usable
            if (e?.name !== 'AbortError') {
                notifyError(e?.message || 'Không tải được Website Share');
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
            const res = await generateSampleComments({
                full_text: `${job.title || ''}\n${job.article_url || ''}`,
                count: 1,
                platform: job.targets?.[0]?.social || null,
            });
            const text = Array.isArray(res?.comments) ? (res.comments[0] || '') : '';
            const updated = await updateWebsiteShareContent(job.id, text);
            notifySuccess('Đã tạo nội dung');
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
        } catch (e) {
            notifyError(e?.message || 'Gen thất bại');
        } finally {
            setBusyId(null);
        }
    };

    const onSaveEdit = async (job) => {
        setBusyId(job.id);
        try {
            const updated = await updateWebsiteShareContent(job.id, draft);
            notifySuccess('Đã lưu nội dung');
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
            setEditingId(null);
        } catch (e) {
            notifyError(e?.message || 'Không lưu được');
        } finally {
            setBusyId(null);
        }
    };

    const onCopy = async (job) => {
        const text = String(job.share_content || '').trim();
        if (!text) {
            notifyError('Chưa có nội dung');
            return;
        }
        try {
            await navigator.clipboard.writeText(text);
            notifySuccess('Đã copy');
        } catch {
            notifyError('Copy thất bại');
        }
    };

    const onWsReport = async (job, social) => {
        if (!canMutate) return;
        setBusyId(job.id);
        try {
            const updated = await reportWebsiteShare(job.id, {
                social,
                share_text: job.share_content || '',
            });
            notifySuccess('Đã báo cáo share');
            const next = updated?.job || job;
            if (!isWebsiteShareActionable(next)) {
                setJobs((prev) => prev.filter((j) => j.id !== job.id));
            } else {
                setJobs((prev) => prev.map((j) => (j.id === job.id ? next : j)));
            }
        } catch (e) {
            notifyError(e?.message || 'Báo cáo thất bại');
        } finally {
            setBusyId(null);
        }
    };

    if (items.length === 0) {
        return (
            <div className="seeding-ws__empty-feed" data-seeder-quick-feed-empty>
                <p>{loadingJobs ? 'Đang tải…' : 'Chưa có việc seeding sẵn sàng.'}</p>
            </div>
        );
    }

    return (
        <div className="seeding-ws__feed-host is-seeder-quick-feed" data-feed-host data-seeder-quick-feed>
            <div className="seeding-ws__feed-grid" data-feed="seeder-quick">
                {items.map((item) => {
                    if (item.kind === 'website') {
                        const job = item.job;
                        return (
                            <div key={item.id} className="seeding-ws__feed-item" data-feed-item data-item-kind="website">
                                <WebsiteShareCard
                                    job={job}
                                    canMutate={canMutate}
                                    busy={busyId === job.id}
                                    editing={editingId === job.id}
                                    draft={draft}
                                    onDraftChange={setDraft}
                                    onGen={onGen}
                                    onStartEdit={(j) => { setEditingId(j.id); setDraft(j.share_content || ''); }}
                                    onSaveEdit={onSaveEdit}
                                    onCopy={onCopy}
                                    onReport={onWsReport}
                                />
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
