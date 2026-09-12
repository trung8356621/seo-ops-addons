import React, { useEffect, useState } from 'react';
import {
    fetchWebsiteShareFeed,
    updateWebsiteShareContent,
    reportWebsiteShare,
    generateSampleComments,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';

const FILTERS = [
    { id: 'all', label: 'Tất cả' },
    { id: 'scheduled', label: 'Chờ tạo nhiệm vụ' },
    { id: 'pending_content', label: 'Chờ tạo nội dung' },
    { id: 'has_content', label: 'Có nội dung' },
    { id: 'sharing', label: 'Đang chia sẻ' },
    { id: 'completed', label: 'Đã hoàn thành' },
];

function formatCountdown(seconds) {
    const s = Math.max(0, Number(seconds) || 0);
    const m = Math.floor(s / 60);
    const r = s % 60;
    if (m <= 0) return `Còn ${r} giây`;
    return `Còn ${m} phút`;
}

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
                {jobs.map((job) => {
                    const scheduled = job.status === 'scheduled';
                    const busy = busyId === job.id;
                    return (
                        <article key={job.id} className="seeding-ws__ws-card" data-website-share-card>
                            {job.thumbnail_url ? (
                                <img className="seeding-ws__ws-thumb" src={job.thumbnail_url} alt="" />
                            ) : null}
                            <div>
                                <strong>{job.title || 'Bài website'}</strong>
                                <div className="seeding-ws__page-sub">{job.domain || '—'}</div>
                            </div>
                            <div className="seeding-ws__vcard-chips">
                                <span className="seeding-ws__chip">Index: {job.indexed_at_label || '—'}</span>
                                <span className="seeding-ws__chip">{job.status_label || job.status}</span>
                                {(job.targets || []).map((t) => (
                                    <span key={t.id || t.social} className="seeding-ws__chip">
                                        {t.social_label || t.social} {t.completed_count}/{t.target_count}
                                    </span>
                                ))}
                            </div>

                            {scheduled ? (
                                <p className="seeding-ws__page-sub">
                                    Chờ tạo nhiệm vụ — {formatCountdown(job.seconds_until_eligible)}
                                </p>
                            ) : (
                                <>
                                    {editingId === job.id ? (
                                        <textarea
                                            className="seeding-ws__textarea"
                                            value={draft}
                                            onChange={(e) => setDraft(e.target.value)}
                                        />
                                    ) : (
                                        <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                                            {job.share_content || 'Chưa có nội dung share.'}
                                        </p>
                                    )}

                                    <div className="seeding-ws__vcard-actions">
                                        {editingId === job.id ? (
                                            <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" disabled={busy} onClick={() => onSaveEdit(job)}>
                                                Lưu
                                            </button>
                                        ) : (
                                            <>
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" disabled={!canMutate || busy} onClick={() => onGen(job)}>
                                                    {job.share_content ? 'Gen lại' : 'Tạo nội dung'}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    disabled={!canMutate}
                                                    onClick={() => { setEditingId(job.id); setDraft(job.share_content || ''); }}
                                                >
                                                    Sửa
                                                </button>
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onCopy(job)}>
                                                    Copy
                                                </button>
                                                {(job.targets || []).filter((t) => !t.is_complete).map((t) => (
                                                    <button
                                                        key={`report-${t.social}`}
                                                        type="button"
                                                        className="seeding-ws__btn seeding-ws__btn--ghost"
                                                        disabled={!canMutate || busy || !job.share_content}
                                                        onClick={() => onReport(job, t.social)}
                                                    >
                                                        Báo cáo {t.social_label || t.social}
                                                    </button>
                                                ))}
                                                {job.article_url ? (
                                                    <a className="seeding-ws__btn seeding-ws__btn--ghost" href={job.article_url} target="_blank" rel="noreferrer">
                                                        Mở bài
                                                    </a>
                                                ) : null}
                                            </>
                                        )}
                                    </div>
                                </>
                            )}
                        </article>
                    );
                })}
            </div>
        </div>
    );
}
