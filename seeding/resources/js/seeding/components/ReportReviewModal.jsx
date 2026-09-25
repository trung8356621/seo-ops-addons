import React, { useEffect, useMemo, useState } from 'react';
import { Check, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { approveManagerReport } from '../api';
import { notifyError, notifySuccess } from '../services/toast';

function compactUrl(url) {
    const raw = String(url || '').trim();
    if (!raw) return '';
    try {
        const u = new URL(raw);
        const path = u.pathname === '/' ? '' : u.pathname;
        const short = `${u.host}${path}`;
        return short.length > 28 ? `${short.slice(0, 26)}…` : short;
    } catch {
        return raw.length > 28 ? `${raw.slice(0, 26)}…` : raw;
    }
}

function formatBytes(size) {
    const n = Number(size);
    if (!Number.isFinite(n) || n <= 0) return null;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function isTypingTarget(el) {
    if (!el || !(el instanceof Element)) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

/**
 * Proof-first Manager review modal with prev/next over the current filtered set.
 *
 * @param {{
 *   reports: Array<Record<string, unknown>>,
 *   index: number,
 *   onClose: () => void,
 *   onIndexChange: (nextIndex: number) => void,
 *   onReportUpdated: (report: Record<string, unknown>) => void,
 * }} props
 */
export default function ReportReviewModal({
    reports,
    index,
    onClose,
    onIndexChange,
    onReportUpdated,
}) {
    const [approving, setApproving] = useState(false);
    const total = Array.isArray(reports) ? reports.length : 0;
    const safeIndex = total > 0 ? Math.min(Math.max(0, index), total - 1) : -1;
    const report = safeIndex >= 0 ? reports[safeIndex] : null;

    const canPrev = safeIndex > 0;
    const canNext = safeIndex >= 0 && safeIndex < total - 1;

    const adjacentProofUrls = useMemo(() => {
        if (safeIndex < 0) return [];
        const urls = [];
        const prev = reports[safeIndex - 1];
        const next = reports[safeIndex + 1];
        if (prev?.proof_url) urls.push(String(prev.proof_url));
        if (next?.proof_url) urls.push(String(next.proof_url));
        return urls;
    }, [reports, safeIndex]);

    useEffect(() => {
        adjacentProofUrls.forEach((url) => {
            const img = new Image();
            img.src = url;
        });
    }, [adjacentProofUrls]);

    useEffect(() => {
        const onKey = (e) => {
            if (isTypingTarget(e.target)) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose();
                return;
            }
            if (e.key === 'ArrowLeft' && canPrev) {
                e.preventDefault();
                onIndexChange(safeIndex - 1);
            }
            if (e.key === 'ArrowRight' && canNext) {
                e.preventDefault();
                onIndexChange(safeIndex + 1);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [canPrev, canNext, onClose, onIndexChange, safeIndex]);

    if (!report) return null;

    const approved = Boolean(report.is_approved || report.approval_status === 'approved');

    const goNextPending = (fromIndex, updatedList) => {
        const list = updatedList || reports;
        for (let i = fromIndex + 1; i < list.length; i += 1) {
            if (!list[i]?.is_approved && list[i]?.approval_status !== 'approved') {
                onIndexChange(i);
                return;
            }
        }
        for (let i = 0; i < fromIndex; i += 1) {
            if (!list[i]?.is_approved && list[i]?.approval_status !== 'approved') {
                onIndexChange(i);
                return;
            }
        }
    };

    const onApprove = async () => {
        if (approved || approving) return;
        setApproving(true);
        try {
            const data = await approveManagerReport(report.id);
            const updated = data?.report || null;
            if (!updated) {
                throw new Error(data?.message || 'Không duyệt được');
            }
            notifySuccess(data?.message || 'Đã duyệt');
            onReportUpdated(updated);
            // Prefer advancing to next pending after approve (modal stays open).
            const patched = reports.map((r) => (Number(r.id) === Number(updated.id) ? { ...r, ...updated } : r));
            goNextPending(safeIndex, patched);
        } catch (e) {
            notifyError(e?.message || 'Duyệt thất bại');
        } finally {
            setApproving(false);
        }
    };

    const proofMetaBits = [
        report.proof_mime,
        formatBytes(report.proof_meta?.size),
    ].filter(Boolean);

    return (
        <div
            className="seeding-ws__modal-backdrop seeding-ws__modal-backdrop--report-review"
            role="presentation"
            data-modal="report-detail"
            onClick={onClose}
        >
            <div
                className="seeding-ws__modal seeding-ws__modal--report-review"
                role="dialog"
                aria-modal="true"
                aria-label="Duyệt báo cáo"
                data-layout="proof-first"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="seeding-ws__report-review-head">
                    <div className="seeding-ws__report-review-counter" data-nav="index">
                        Báo cáo {safeIndex + 1} / {total}
                    </div>
                    <div className="seeding-ws__report-review-nav" data-nav="group">
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--ghost"
                            onClick={() => canPrev && onIndexChange(safeIndex - 1)}
                            disabled={!canPrev}
                            aria-label="Báo cáo trước"
                            data-nav="prev"
                        >
                            <ChevronLeft size={18} />
                            <span>Trước</span>
                        </button>
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--ghost"
                            onClick={() => canNext && onIndexChange(safeIndex + 1)}
                            disabled={!canNext}
                            aria-label="Báo cáo sau"
                            data-nav="next"
                        >
                            <span>Sau</span>
                            <ChevronRight size={18} />
                        </button>
                        <button
                            type="button"
                            className="seeding-ws__btn seeding-ws__btn--ghost seeding-ws__report-review-close"
                            onClick={onClose}
                            aria-label="Đóng"
                        >
                            <X size={16} />
                        </button>
                    </div>
                </div>

                <div className="seeding-ws__report-review-body">
                    <div className="seeding-ws__report-review-proof" data-pane="proof">
                        {report.has_proof && report.proof_url ? (
                            <img
                                key={report.proof_url}
                                className="seeding-ws__report-review-img"
                                src={String(report.proof_url)}
                                alt="Proof"
                            />
                        ) : (
                            <div className="seeding-ws__report-review-proof-empty">Không có ảnh proof</div>
                        )}
                    </div>

                    <aside className="seeding-ws__report-review-side" data-pane="meta">
                        <div
                            className={`seeding-ws__report-status-pill${approved ? ' is-approved' : ' is-pending'}`}
                            data-status={approved ? 'approved' : 'pending'}
                        >
                            {approved ? 'Đã duyệt' : 'Chờ duyệt'}
                        </div>

                        <dl className="seeding-ws__report-review-meta">
                            <div>
                                <dt>Thành viên</dt>
                                <dd>{report.user_display_name || '—'}</dd>
                            </div>
                            <div>
                                <dt>Thời gian</dt>
                                <dd>{report.reported_at_label || '—'}</dd>
                            </div>
                            <div>
                                <dt>Chủ đề / MXH</dt>
                                <dd>
                                    <div>{report.topic_title || report.topic_preview || `#${report.topic_id}`}</div>
                                    <div className="seeding-ws__muted">
                                        {report.social_platform_label || report.social_platform || '—'}
                                        {report.social_url ? (
                                            <>
                                                {' · '}
                                                <a
                                                    href={String(report.social_url)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title={String(report.social_url)}
                                                >
                                                    {compactUrl(report.social_url) || 'Mở'}
                                                </a>
                                            </>
                                        ) : null}
                                    </div>
                                </dd>
                            </div>
                            <div>
                                <dt>Comment</dt>
                                <dd>
                                    <pre className="seeding-ws__report-review-comment">{report.comment_text || '—'}</pre>
                                </dd>
                            </div>
                            <div>
                                <dt>Link seed</dt>
                                <dd>
                                    {report.seed_url ? (
                                        <>
                                            <div>{report.seed_link_title || report.seed_link_label || 'Link'}</div>
                                            <a
                                                className="seeding-ws__report-review-url"
                                                href={String(report.seed_url)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title={String(report.seed_url)}
                                            >
                                                {compactUrl(report.seed_url)}
                                            </a>
                                        </>
                                    ) : (
                                        <span className="seeding-ws__muted">—</span>
                                    )}
                                </dd>
                            </div>
                        </dl>

                        {proofMetaBits.length > 0 ? (
                            <p className="seeding-ws__report-review-proof-meta" data-meta="proof">
                                {proofMetaBits.join(' · ')}
                            </p>
                        ) : null}

                        {approved ? (
                            <div className="seeding-ws__report-approve-done" data-action="approved">
                                <Check size={16} />
                                <div>
                                    <strong>Đã duyệt</strong>
                                    {report.approved_at_label ? (
                                        <span className="seeding-ws__muted"> · {report.approved_at_label}</span>
                                    ) : null}
                                </div>
                            </div>
                        ) : (
                            <button
                                type="button"
                                className="seeding-ws__btn seeding-ws__btn--primary seeding-ws__report-approve-btn"
                                onClick={onApprove}
                                disabled={approving}
                                data-action="approve"
                            >
                                <Check size={16} />
                                {approving ? 'Đang duyệt…' : 'Duyệt'}
                            </button>
                        )}
                    </aside>
                </div>
            </div>
        </div>
    );
}
