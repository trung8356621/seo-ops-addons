import React, { useEffect, useState } from 'react';
import {
    fetchWebsiteShareFeed,
    updateWebsiteShareContent,
    reportWebsiteShare,
    generateSampleComments,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';
import WebsiteShareCard from './WebsiteShareCard';

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
    const [editingId, setEditingId] = useState(null);
    const [draft, setDraft] = useState('');
    const [busyId, setBusyId] = useState(null);

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

    const onReport = async (job, social) => {
        if (!canMutate) return;
        setBusyId(job.id);
        try {
            const updated = await reportWebsiteShare(job.id, {
                social,
                share_text: job.share_content || '',
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
                {jobs.map((job) => (
                    <WebsiteShareCard
                        key={job.id}
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
                        onReport={onReport}
                    />
                ))}
            </div>
        </div>
    );
}
