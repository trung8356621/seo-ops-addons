import { auditT } from '../../i18n-audit.js';
import React, { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import {
    fetchManagerTopics,
    fetchManagerReports,
    pauseManagerTopic,
    resumeManagerTopic,
    cancelManagerTopic,
    fetchCommentPrompt,
    fetchCommentPromptHistoryDetail,
} from '../api';
import { notifyError, notifySuccess } from '../services/toast';
import ReportReviewModal from './ReportReviewModal';
import SocialAccountsPanel from './SocialAccountsPanel';

const STATUS_FILTERS = [
    { id: 'all', label: auditT('audit_f7a578dcbdca') },
    { id: 'running', label: auditT('audit_b091a3002a71') },
    { id: 'pending', label: auditT('audit_09f930fa5406') },
    { id: 'done', label: auditT('audit_ae639c37b294') },
    { id: 'paused', label: auditT('audit_b8d3bf311087') },
];

const SUB_TABS = [
    { id: 'topics', label: auditT('audit_95f745575c44') },
    { id: 'reports', label: auditT('audit_7013ca8bfd15') },
    { id: 'social-accounts', label: auditT('audit_3614416a9d97') },
    { id: 'members', label: auditT('audit_cd264c4a8f28') },
    { id: 'summary', label: auditT('audit_e2550a9edab1') },
];

const SOCIAL_OPTIONS = [
    { value: '', label: auditT('audit_6c674584ffb4') },
    { value: 'facebook', label: 'Facebook' },
    { value: 'threads', label: 'Threads' },
    { value: 'tiktok', label: 'TikTok' },
    { value: 'pinterest', label: 'Pinterest' },
    { value: 'reddit', label: 'Reddit' },
];

const REPORT_APPROVAL_FILTERS = [
    { id: 'all', label: auditT('audit_f7a578dcbdca') },
    { id: 'pending', label: auditT('audit_3352b356a4c7') },
    { id: 'approved', label: auditT('audit_1165d8820535') },
];

function statusLabel(status) {
    if (status === 'success') return auditT('audit_9a7d70370906');
    if (status === 'failed') return auditT('audit_471434ca3944');
    return status || '—';
}

function compactUrl(url) {
    const raw = String(url || '').trim();
    if (!raw) return '';
    try {
        const u = new URL(raw);
        const path = u.pathname === '/' ? '' : u.pathname;
        const short = `${u.host}${path}`;
        return short.length > 36 ? `${short.slice(0, 34)}…` : short;
    } catch {
        return raw.length > 36 ? `${raw.slice(0, 34)}…` : raw;
    }
}

function reportIsApproved(row) {
    return Boolean(row?.is_approved || row?.approval_status === 'approved');
}

/**
 * Manager-only table + stats + Gen Comment prompt/history.
 */
export default function ManagerPanel({ websiteStats = null }) {
    const [subTab, setSubTab] = useState('topics');
    const [status, setStatus] = useState('all');
    const [social, setSocial] = useState('');
    const [search, setSearch] = useState('');
    const [topics, setTopics] = useState([]);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(false);

    const [reports, setReports] = useState([]);
    const [reportMembers, setReportMembers] = useState([]);
    const [reportsLoading, setReportsLoading] = useState(false);
    const [reportUserId, setReportUserId] = useState('');
    const [reportSocial, setReportSocial] = useState('');
    const [reportSearch, setReportSearch] = useState('');
    const [reportDateFrom, setReportDateFrom] = useState('');
    const [reportDateTo, setReportDateTo] = useState('');
    const [reportApprovalStatus, setReportApprovalStatus] = useState('all');
    const [reportDetailIndex, setReportDetailIndex] = useState(null);

    const [promptBody, setPromptBody] = useState('');
    const [promptLoading, setPromptLoading] = useState(false);
    const [promptError, setPromptError] = useState('');
    const [promptEditUrl, setPromptEditUrl] = useState('');
    const [promptHookKey, setPromptHookKey] = useState('seeding.comment.generate');
    const [history, setHistory] = useState([]);
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detail, setDetail] = useState(null);

    const load = async () => {
        setLoading(true);
        try {
            const data = await fetchManagerTopics({ status, social, search });
            setTopics(Array.isArray(data?.topics) ? data.topics : []);
            setStats(data?.stats || null);
        } catch (e) {
            notifyError(e?.message || auditT('audit_c0550c95458b'));
        } finally {
            setLoading(false);
        }
    };

    const loadReports = async () => {
        setReportsLoading(true);
        try {
            const data = await fetchManagerReports({
                user_id: reportUserId,
                social: reportSocial,
                search: reportSearch,
                date_from: reportDateFrom,
                date_to: reportDateTo,
                status: reportApprovalStatus === 'all' ? '' : reportApprovalStatus,
            });
            setReports(Array.isArray(data?.reports) ? data.reports : []);
            setReportMembers(Array.isArray(data?.members) ? data.members : []);
            setReportDetailIndex((prev) => {
                if (prev == null) return null;
                const list = Array.isArray(data?.reports) ? data.reports : [];
                return prev < list.length ? prev : (list.length > 0 ? list.length - 1 : null);
            });
        } catch (e) {
            notifyError(e?.message || auditT('audit_cf300b29eeff'));
        } finally {
            setReportsLoading(false);
        }
    };

    const loadPrompt = async () => {
        setPromptLoading(true);
        setPromptError('');
        try {
            const data = await fetchCommentPrompt();
            setPromptBody(String(data?.prompt_body ?? ''));
            setPromptEditUrl(String(data?.edit_url ?? ''));
            setPromptHookKey(String(data?.hook_key ?? 'seeding.comment.generate'));
            setHistory(Array.isArray(data?.history) ? data.history : []);
        } catch (e) {
            const message = e?.message || auditT('audit_d2b71ced57f1');
            setPromptError(message);
            notifyError(message);
            // Keep sections visible — empty prompt/history + error banner.
        } finally {
            setPromptLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, [status, social]);

    useEffect(() => {
        if (subTab === 'summary') {
            loadPrompt();
        }
        if (subTab === 'reports') {
            loadReports();
        }
    }, [subTab, reportApprovalStatus]);

    const onAction = async (topic, action) => {
        try {
            if (action === 'pause') await pauseManagerTopic(topic.id);
            if (action === 'resume') await resumeManagerTopic(topic.id);
            if (action === 'cancel') {
                if (!window.confirm(auditT('audit_d8a5a62c3d61'))) return;
                await cancelManagerTopic(topic.id);
            }
            notifySuccess(auditT('audit_d696b7919768'));
            await load();
        } catch (e) {
            notifyError(e?.message || auditT('audit_941196265df9'));
        }
    };

    const onSavePrompt = async () => {
        notifyError(auditT('audit_6df41db9f763'));
    };

    const onViewHistory = async (row) => {
        setDetailOpen(true);
        setDetail(null);
        setDetailLoading(true);
        try {
            const data = await fetchCommentPromptHistoryDetail(row.slot);
            setDetail(data?.log || null);
        } catch (e) {
            notifyError(e?.message || auditT('audit_40b60bc9783e'));
            setDetailOpen(false);
        } finally {
            setDetailLoading(false);
        }
    };

    return (
        <div data-panel="manager">
            <div className="seeding-ws__manager-subtabs">
                {SUB_TABS.map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        className={`seeding-ws__main-tab${subTab === tab.id ? ' is-active' : ''}`}
                        onClick={() => setSubTab(tab.id)}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {subTab === 'summary' ? (
                <div className="seeding-ws__summary-stack">
                    <div className="seeding-ws__stats-grid">
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_running ?? '—'}</strong><span>Đang chạy</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_done ?? '—'}</strong><span>Hoàn thành</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_pending ?? '—'}</strong><span>Pending</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.topics_paused ?? '—'}</strong><span>Tạm dừng</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.comments_required ?? '—'}</strong><span>Comments required</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{stats?.comments_completed ?? '—'}</strong><span>Comments completed</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{websiteStats?.pending ?? '—'}</strong><span>Website pending</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{websiteStats?.ready ?? '—'}</strong><span>Website ready</span></div>
                        <div className="seeding-ws__stat-tile"><strong>{websiteStats?.completed ?? '—'}</strong><span>Website completed</span></div>
                    </div>

                    <section className="seeding-ws__prompt-section" data-section="gen-comment-prompt">
                        <h3 className="seeding-ws__section-title">Prompt Gen Comment</h3>
                        {promptError ? (
                            <p className="seeding-ws__prompt-error" role="alert">{promptError}</p>
                        ) : null}
                        <p className="seeding-ws__prompt-help" data-authority="shared_prompt">
                            Nguồn SSOT: Prompt Management (<code>{promptHookKey}</code>). Seeding chỉ xem — không sửa tại đây.
                        </p>
                        <textarea
                            className="seeding-ws__textarea seeding-ws__textarea--prompt"
                            value={promptBody}
                            readOnly
                            rows={12}
                            spellCheck={false}
                            disabled={promptLoading}
                            aria-busy={promptLoading}
                            aria-readonly="true"
                        />
                        <p className="seeding-ws__prompt-help">
                            Biến hỗ trợ duy nhất: <code>{'{{mcp_context}}'}</code>
                        </p>
                        {promptEditUrl ? (
                            <a
                                className="seeding-ws__btn seeding-ws__btn--primary"
                                href={promptEditUrl}
                                data-link="shared-prompt-editor"
                            >
                                Mở Prompt Management
                            </a>
                        ) : (
                            <button
                                type="button"
                                className="seeding-ws__btn seeding-ws__btn--ghost"
                                onClick={onSavePrompt}
                                disabled
                            >
                                Chỉnh sửa tại Prompt Management
                            </button>
                        )}
                    </section>

                    <section className="seeding-ws__prompt-section" data-section="gen-comment-history">
                        <h3 className="seeding-ws__section-title">Lịch sử Gen Comment</h3>
                        <div className="seeding-ws__manager-table-wrap">
                            <table className="seeding-ws__manager-table seeding-ws__history-table">
                                <thead>
                                    <tr>
                                        <th>Thời gian</th>
                                        <th>Chủ đề</th>
                                        <th>MXH</th>
                                        <th>Số lượng</th>
                                        <th>Model</th>
                                        <th>Trạng thái</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.length === 0 ? (
                                        <tr>
                                            <td colSpan={7}>
                                                {promptLoading
                                                    ? auditT('audit_577a1d591051')
                                                    : promptError
                                                        ? auditT('audit_c9318c2c8834')
                                                        : auditT('audit_249b56aed3d6')}
                                            </td>
                                        </tr>
                                    ) : history.map((row) => (
                                        <tr key={`${row.slot}-${row.sequence}`}>
                                            <td>{row.generated_at_label || '—'}</td>
                                            <td>{row.topic_id ?? '—'}</td>
                                            <td>{row.social || '—'}</td>
                                            <td>{row.quantity ?? '—'}</td>
                                            <td className="seeding-ws__mono-cell">
                                                {[row.provider, row.model].filter(Boolean).join('/') || '—'}
                                            </td>
                                            <td>{statusLabel(row.status)}</td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="seeding-ws__btn seeding-ws__btn--ghost"
                                                    onClick={() => onViewHistory(row)}
                                                >
                                                    Xem
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            ) : null}

            {subTab === 'topics' ? (
                <>
                    <div className="seeding-ws__manager-filters">
                        {STATUS_FILTERS.map((f) => (
                            <button
                                key={f.id}
                                type="button"
                                className={`seeding-ws__tab${status === f.id ? ' is-active' : ''}`}
                                onClick={() => setStatus(f.id)}
                            >
                                {f.label}
                            </button>
                        ))}
                        <select
                            className="seeding-ws__input"
                            style={{ maxWidth: '10rem' }}
                            value={social}
                            onChange={(e) => setSocial(e.target.value)}
                        >
                            {SOCIAL_OPTIONS.map((opt) => (
                                <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                        <input
                            className="seeding-ws__input"
                            style={{ maxWidth: '16rem' }}
                            placeholder="Search title/link"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') load(); }}
                        />
                        <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={load} disabled={loading}>
                            {loading ? auditT('audit_577a1d591051') : auditT('audit_d0242d1abc66')}
                        </button>
                    </div>

                    <div className="seeding-ws__manager-table-wrap">
                        <table className="seeding-ws__manager-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Chủ đề</th>
                                    <th>Link</th>
                                    <th>MXH</th>
                                    <th>Yêu cầu / HT</th>
                                    <th>Tiến độ</th>
                                    <th>Trạng thái</th>
                                    <th>Người tạo</th>
                                    <th>Ngày tạo</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                {topics.length === 0 ? (
                                    <tr>
                                        <td colSpan={10}>{loading ? auditT('audit_577a1d591051') : auditT('audit_a0d54fa5af55')}</td>
                                    </tr>
                                ) : topics.map((topic, idx) => (
                                    <tr key={topic.id}>
                                        <td>{idx + 1}</td>
                                        <td>{topic.title || topic.preview || '—'}</td>
                                        <td style={{ maxWidth: '12rem', wordBreak: 'break-all' }}>
                                            {topic.social_url || (topic.links?.[0]?.url) || '—'}
                                        </td>
                                        <td>{topic.social_platform_label || topic.social_platform || '—'}</td>
                                        <td>{topic.progress_label || `${topic.completed_comments || 0} / ${topic.target_comments || 0}`}</td>
                                        <td>{topic.progress_percent ?? 0}%</td>
                                        <td>{topic.status_label || topic.status}</td>
                                        <td>{topic.creator_name || topic.created_by_display_name || '—'}</td>
                                        <td>{topic.created_date_label || '—'}</td>
                                        <td>
                                            <div style={{ display: 'flex', gap: '0.25rem', flexWrap: 'wrap' }}>
                                                {topic.status === 'paused' ? (
                                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onAction(topic, 'resume')}>Resume</button>
                                                ) : (
                                                    <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onAction(topic, 'pause')}>Pause</button>
                                                )}
                                                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => onAction(topic, 'cancel')}>Hủy</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            ) : null}

            {subTab === 'reports' ? (
                <div data-section="manager-reports">
                    <div className="seeding-ws__manager-filters" data-filters="reports">
                        {REPORT_APPROVAL_FILTERS.map((f) => (
                            <button
                                key={f.id}
                                type="button"
                                className={`seeding-ws__tab${reportApprovalStatus === f.id ? ' is-active' : ''}`}
                                onClick={() => setReportApprovalStatus(f.id)}
                                data-filter-status={f.id}
                            >
                                {f.label}
                            </button>
                        ))}
                        <select
                            className="seeding-ws__input"
                            style={{ maxWidth: '12rem' }}
                            value={reportUserId}
                            onChange={(e) => setReportUserId(e.target.value)}
                            aria-label={auditT('audit_cd264c4a8f28')}
                        >
                            <option value="">Tất cả thành viên</option>
                            {reportMembers.map((m) => (
                                <option key={m.user_id} value={String(m.user_id)}>
                                    {m.user_display_name || `#${m.user_id}`}
                                </option>
                            ))}
                        </select>
                        <select
                            className="seeding-ws__input"
                            style={{ maxWidth: '10rem' }}
                            value={reportSocial}
                            onChange={(e) => setReportSocial(e.target.value)}
                            aria-label="MXH"
                        >
                            {SOCIAL_OPTIONS.map((opt) => (
                                <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                        <input
                            className="seeding-ws__input"
                            style={{ maxWidth: '14rem' }}
                            placeholder={auditT('audit_206910e5b613')}
                            value={reportSearch}
                            onChange={(e) => setReportSearch(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') loadReports(); }}
                        />
                        <input
                            type="date"
                            className="seeding-ws__input"
                            style={{ maxWidth: '10rem' }}
                            value={reportDateFrom}
                            onChange={(e) => setReportDateFrom(e.target.value)}
                            aria-label={auditT('audit_ea7c92c61e95')}
                        />
                        <input
                            type="date"
                            className="seeding-ws__input"
                            style={{ maxWidth: '10rem' }}
                            value={reportDateTo}
                            onChange={(e) => setReportDateTo(e.target.value)}
                            aria-label={auditT('audit_fe67ba88ffb9')}
                        />
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--ghost"
                            onClick={loadReports}
                            disabled={reportsLoading}
                        >
                            {reportsLoading ? auditT('audit_577a1d591051') : auditT('audit_d0242d1abc66')}
                        </button>
                    </div>

                    <div className="seeding-ws__manager-table-wrap">
                        <table className="seeding-ws__manager-table seeding-ws__reports-table" data-table="manager-reports">
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Trạng thái</th>
                                    <th>Thành viên</th>
                                    <th>Chủ đề</th>
                                    <th>MXH</th>
                                    <th>Comment</th>
                                    <th>Link</th>
                                    <th>Proof</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {reports.length === 0 ? (
                                    <tr>
                                        <td colSpan={9}>
                                            {reportsLoading ? auditT('audit_577a1d591051') : auditT('audit_3fd7ca3e628e')}
                                        </td>
                                    </tr>
                                ) : reports.map((row, idx) => (
                                    <tr
                                        key={row.id}
                                        className="seeding-ws__report-row"
                                        onClick={() => setReportDetailIndex(idx)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                setReportDetailIndex(idx);
                                            }
                                        }}
                                        tabIndex={0}
                                        role="button"
                                    >
                                        <td className="seeding-ws__nowrap">{row.reported_at_label || '—'}</td>
                                        <td>
                                            <span
                                                className={`seeding-ws__report-status-pill seeding-ws__report-status-pill--table${reportIsApproved(row) ? ' is-approved' : ' is-pending'}`}
                                                data-status={reportIsApproved(row) ? 'approved' : 'pending'}
                                            >
                                                {row.approval_status_label || (reportIsApproved(row) ? auditT('audit_1165d8820535') : auditT('audit_3352b356a4c7'))}
                                            </span>
                                        </td>
                                        <td>{row.user_display_name || '—'}</td>
                                        <td className="seeding-ws__report-topic">
                                            {row.topic_title || row.topic_preview || `#${row.topic_id}`}
                                        </td>
                                        <td>{row.social_platform_label || row.social_platform || '—'}</td>
                                        <td
                                            className="seeding-ws__report-comment-cell"
                                            title={row.comment_text || ''}
                                        >
                                            {row.comment_excerpt || '—'}
                                        </td>
                                        <td className="seeding-ws__report-link-cell">
                                            {row.seed_url ? (
                                                <a
                                                    href={row.seed_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title={row.seed_url}
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {row.seed_link_title || compactUrl(row.seed_url) || row.seed_link_label || 'Link'}
                                                </a>
                                            ) : (
                                                <span className="seeding-ws__muted">—</span>
                                            )}
                                        </td>
                                        <td onClick={(e) => e.stopPropagation()}>
                                            {row.has_proof && row.proof_url ? (
                                                <button
                                                    type="button"
                                                    className="seeding-ws__report-proof-btn"
                                                    onClick={() => setReportDetailIndex(idx)}
                                                    aria-label="Xem proof"
                                                >
                                                    <img
                                                        className="seeding-ws__proof-thumb"
                                                        src={row.proof_url}
                                                        alt=""
                                                        loading="lazy"
                                                    />
                                                </button>
                                            ) : (
                                                <span className="seeding-ws__proof-thumb seeding-ws__proof-thumb--empty" aria-hidden="true" />
                                            )}
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                className="seeding-ws__btn seeding-ws__btn--ghost"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setReportDetailIndex(idx);
                                                }}
                                            >
                                                Xem
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : null}

            {subTab === 'social-accounts' ? (
                <SocialAccountsPanel />
            ) : null}

            {subTab === 'members' ? (
                <div className="seeding-ws__empty-feed" data-section="manager-members-placeholder">
                    <p>Bảng thành viên sẽ mở rộng từ seeding_reports (đang dùng dữ liệu Tổng kết).</p>
                </div>
            ) : null}

            {reportDetailIndex != null && reports[reportDetailIndex] ? (
                <ReportReviewModal
                    reports={reports}
                    index={reportDetailIndex}
                    onClose={() => setReportDetailIndex(null)}
                    onIndexChange={setReportDetailIndex}
                    onReportUpdated={(updated) => {
                        setReports((prev) => prev.map((r) => (
                            Number(r.id) === Number(updated.id) ? { ...r, ...updated } : r
                        )));
                    }}
                />
            ) : null}

            {detailOpen ? (
                <div className="seeding-ws__modal-backdrop" role="presentation" onClick={() => setDetailOpen(false)}>
                    <div
                        className="seeding-ws__modal seeding-ws__modal--wide"
                        role="dialog"
                        aria-modal="true"
                        aria-label={auditT('audit_36c84b951102')}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="seeding-ws__modal-head">
                            <h3>Chi tiết Gen Comment</h3>
                            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={() => setDetailOpen(false)}>
                                <X size={16} />
                            </button>
                        </div>
                        {detailLoading ? (
                            <div className="seeding-ws__prompt-skeleton" aria-busy="true">
                                <div className="seeding-ws__skeleton-block" />
                                <div className="seeding-ws__skeleton-block" />
                            </div>
                        ) : detail ? (
                            <div className="seeding-ws__history-detail">
                                <dl className="seeding-ws__history-meta">
                                    <div><dt>Thời gian</dt><dd>{detail.generated_at_label || '—'}</dd></div>
                                    <div><dt>Provider</dt><dd>{detail.provider || '—'}</dd></div>
                                    <div><dt>Model</dt><dd>{detail.model || '—'}</dd></div>
                                    <div><dt>Quantity</dt><dd>{detail.quantity ?? '—'}</dd></div>
                                    <div><dt>Status</dt><dd>{statusLabel(detail.status)}</dd></div>
                                    <div><dt>Social</dt><dd>{detail.social || '—'}</dd></div>
                                    <div><dt>Topic</dt><dd>{detail.topic_id ?? '—'}</dd></div>
                                </dl>
                                {detail.error_message ? (
                                    <div className="seeding-ws__history-error">
                                        <strong>Error</strong>
                                        <pre className="seeding-ws__pre">{detail.error_message}</pre>
                                    </div>
                                ) : null}
                                <div>
                                    <h4>1. MCP Context</h4>
                                    <pre className="seeding-ws__pre">{detail.mcp_context || ''}</pre>
                                </div>
                                <div>
                                    <h4>2. Final AI Request / Final Prompt</h4>
                                    <pre className="seeding-ws__pre">{detail.final_prompt || ''}</pre>
                                </div>
                                <div>
                                    <h4>3. AI Output</h4>
                                    <pre className="seeding-ws__pre">{detail.ai_output || '—'}</pre>
                                </div>
                            </div>
                        ) : (
                            <p>Không có dữ liệu.</p>
                        )}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
