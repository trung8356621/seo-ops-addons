import { auditT } from '../../i18n-audit.js';
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
    { id: 'all', label: auditT('audit_f7a578dcbdca') },
    { id: 'scheduled', label: auditT('audit_a03e9c945267') },
    { id: 'pending_content', label: auditT('audit_e0e999f1b566') },
    { id: 'has_content', label: auditT('audit_891ef7c8d7ac') },
    { id: 'sharing', label: auditT('audit_d1c54f98d50c') },
    { id: 'completed', label: auditT('audit_ae639c37b294') },
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
            notifyError(e?.message || auditT('audit_fa33146c98be'));
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

    const onReport = async (job, target) => {
        if (!canMutate) return;
        setBusyId(job.id);
        try {
            const updated = await reportWebsiteShare(job.id, {
                social: target.social,
            });
            notifySuccess(auditT('audit_1836afcb4f7d'));
            setJobs((prev) => prev.map((j) => (j.id === job.id ? (updated?.job || j) : j)));
        } catch (e) {
            notifyError(e?.message || auditT('audit_8902b7e36887'));
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
