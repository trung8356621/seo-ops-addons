import React, { useEffect, useState } from 'react';
import {
    fetchWebsiteShareFeed,
    generateWebsiteShareContent,
    updateWebsiteShareTargetContent,
    reportWebsiteShare,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';
import { writeClipboard } from '../services/clipboardWrite';
import WebsiteShareCard from './WebsiteShareCard';
import WebsiteShareGeneratePanel from './WebsiteShareGeneratePanel';

const FILTERS = [
    { id: 'all', label: 'Tất cả' },
    { id: 'scheduled', label: 'Chờ tạo nhiệm vụ' },
    { id: 'pending_content', label: 'Chờ tạo nội dung' },
    { id: 'has_content', label: 'Có nội dung' },
    { id: 'sharing', label: 'Đang chia sẻ' },
    { id: 'completed', label: 'Đã hoàn thành' },
];

/**
 * Website Share feed — independent from Topic Comment seeding.
 */
export default function WebsiteShareFeed({ canMutate }) {
    const [filter, setFilter] = useState('all');
    const [jobs, setJobs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [editingTargetId, setEditingTargetId] = useState(null);
    const [draft, setDraft] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [activeWebsiteShareId, setActiveWebsiteShareId] = useState(null);

    const load = async () => {
        setLoading(true);
        try {
            const data = await fetchWebsiteShareFeed(filter);
            setJobs(Array.isArray(data?.jobs) ? data.jobs : []);
        } catch (e) {
            notifyError(e?.message || 'Không tải được Bài từ Website');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        const timer = setInterval(load, 60000);
        return () => clearInterval(timer);
    }, [filter]);

    const onGen = async (job) => {
        if (!canMutate || job.status === 'scheduled') return;
        setBusyId(job.id);
        try {
            const updated = await generateWebsiteShareContent(job.id);
            notifySuccess('Đã tạo nội dung');
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
        } catch (e) {
            notifyError(e?.message || 'Gen thất bại');
        } finally {
            setBusyId(null);
        }
    };

    const onSaveEdit = async (job, target) => {
        setBusyId(job.id);
        try {
            const updated = await updateWebsiteShareTargetContent(job.id, target.id, draft);
            notifySuccess('Đã lưu nội dung');
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
            setEditingTargetId(null);
        } catch (e) {
            notifyError(e?.message || 'Không lưu được');
        } finally {
            setBusyId(null);
        }
    };

    const onCopy = async (target) => {
        const text = String(target.share_content || '').trim();
        if (!text) {
            notifyError('Chưa có nội dung');
            return;
        }
        try {
            const result = await writeClipboard(text);
            if (result.ok) notifySuccess('Đã copy');
            else notifyError(result.error || 'Copy thất bại');
        } catch {
            notifyError('Copy thất bại');
        }
    };

    const onReport = async (job, target) => {
        if (!canMutate) return;
        setBusyId(job.id);
        try {
            const updated = await reportWebsiteShare(job.id, {
                social: target.social,
            });
            notifySuccess('Đã báo cáo share');
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
        } catch (e) {
            notifyError(e?.message || 'Báo cáo thất bại');
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div data-panel="website-share">
            <div className="seeding-ws__tabs" style={{ marginBottom: '0.75rem' }}>
                {FILTERS.map((f) => (
                    <button
                        key={f.id}
                        type="button"
                        className={`seeding-ws__tab${filter === f.id ? ' is-active' : ''}`}
                        onClick={() => setFilter(f.id)}
                    >
                        {f.label}
                    </button>
                ))}
            </div>

            {loading && jobs.length === 0 ? (
                <div className="seeding-ws__empty-feed"><p>Đang tải…</p></div>
            ) : null}

            {!loading && jobs.length === 0 ? (
                <div className="seeding-ws__empty-feed"><p>Chưa có bài từ website.</p></div>
            ) : null}

            <div className="seeding-ws__feed-grid">
                {jobs.map((job) => {
                    const open = String(activeWebsiteShareId) === String(job.id);
                    return (
                        <div key={job.id} className={`seeding-ws__feed-item${open ? ' is-gen-open' : ''}`} data-feed-item data-item-kind="website">
                            <WebsiteShareCard
                                job={job}
                                canMutate={canMutate}
                                busy={busyId === job.id}
                                genOpen={open}
                                onGenToggle={(current) => setActiveWebsiteShareId(open ? null : current.id)}
                            />
                            {open ? (
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
                                    onReport={onReport}
                                    onClose={() => setActiveWebsiteShareId(null)}
                                />
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
