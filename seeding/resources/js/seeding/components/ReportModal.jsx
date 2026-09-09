import React, { useEffect, useRef, useState } from 'react';
import { Loader2, X } from 'lucide-react';
import { notifyError, notifyWarning } from '../services/toast';

/**
 * Report modal — paste proof image, confirm → parent uploads API.
 *
 * @param {{
 *   open: boolean,
 *   topic: Record<string, unknown>|null,
 *   comment: Record<string, unknown>|null,
 *   submitting?: boolean,
 *   onClose: () => void,
 *   onConfirm: (payload: { proof: Blob, previewUrl: string }) => void|Promise<void>,
 * }} props
 */
export default function ReportModal({
    open,
    topic,
    comment,
    submitting = false,
    onClose,
    onConfirm,
}) {
    const [previewUrl, setPreviewUrl] = useState(null);
    const [proofBlob, setProofBlob] = useState(null);
    const panelRef = useRef(null);

    useEffect(() => {
        if (!open) {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            setPreviewUrl(null);
            setProofBlob(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
    }, [previewUrl]);

    useEffect(() => {
        if (!open) return undefined;
        const onPaste = (event) => {
            const items = event.clipboardData?.items;
            if (!items) return;
            let found = false;
            for (const item of items) {
                if (!String(item.type || '').startsWith('image/')) continue;
                const blob = item.getAsFile();
                if (!blob) continue;
                found = true;
                event.preventDefault();
                if (previewUrl) URL.revokeObjectURL(previewUrl);
                const url = URL.createObjectURL(blob);
                setPreviewUrl(url);
                setProofBlob(blob);
                break;
            }
            if (!found) {
                notifyWarning('Không tìm thấy ảnh trong clipboard');
            }
        };
        window.addEventListener('paste', onPaste);
        return () => window.removeEventListener('paste', onPaste);
    }, [open, previewUrl]);

    if (!open || !topic || !comment) return null;

    const seedUrl = comment.selected_seed_url || comment.url || null;

    const confirm = async () => {
        if (!proofBlob) {
            notifyError('Hãy Ctrl+V ảnh proof trước');
            return;
        }
        await onConfirm({ proof: proofBlob, previewUrl });
    };

    return (
        <div className="seeding-ws__modal-backdrop" data-modal="report" role="dialog" aria-modal="true">
            <div className="seeding-ws__modal" ref={panelRef}>
                <div className="seeding-ws__panel-head">
                    <h2>Báo cáo</h2>
                    <button type="button" className="seeding-ws__icon-btn" onClick={onClose} aria-label="Đóng" disabled={submitting}>
                        <X size={16} />
                    </button>
                </div>

                <div className="seeding-ws__muted">Ctrl+V để dán ảnh proof từ clipboard</div>

                <div className="seeding-ws__report-snapshot">
                    <div className="seeding-ws__section-title">Comment</div>
                    <p className="seeding-ws__report-comment">{String(comment.content || '')}</p>
                    {seedUrl ? (
                        <p className="seeding-ws__output-link">{String(seedUrl)}</p>
                    ) : null}
                </div>

                <div className="seeding-ws__report-proof">
                    {previewUrl ? (
                        <img src={previewUrl} alt="Proof preview" className="seeding-ws__proof-preview" />
                    ) : (
                        <div className="seeding-ws__proof-empty">Chưa có ảnh — Ctrl+V để dán</div>
                    )}
                </div>

                <button
                    type="button"
                    className="seeding-ws__btn seeding-ws__btn--primary seeding-ws__btn--block"
                    disabled={submitting || !proofBlob}
                    onClick={confirm}
                >
                    {submitting ? <Loader2 size={14} className="seeding-ws__spin" /> : null}
                    {submitting ? 'Đang gửi…' : 'Xác nhận báo cáo'}
                </button>
            </div>
        </div>
    );
}
