import React, { useEffect, useRef, useState } from 'react';
import { Copy, ExternalLink, X } from 'lucide-react';
import { extractImageFromClipboard } from '../services/proofStore';

/**
 * App-global work drawer — survives Feed ↔ Detail navigation.
 * Local claim is NOT concurrency-safe (prototype only).
 *
 * @param {{
 *   open: boolean,
 *   topic: Record<string, unknown>|null,
 *   comment: Record<string, unknown>|null,
 *   onCopy: () => void,
 *   onRelease: () => void,
 *   onClose: () => void,
 *   onProofImage: (file: File) => Promise<void>|void,
 * }} props
 */
export default function GlobalWorkDrawer({
    open,
    topic,
    comment,
    onCopy,
    onRelease,
    onClose,
    onProofImage,
}) {
    const zoneRef = useRef(null);
    const [previewUrl, setPreviewUrl] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!open) {
            setPreviewUrl(null);
            setError(null);
            setBusy(false);
        }
    }, [open, comment?.id]);

    useEffect(() => {
        if (!open) return undefined;

        const onPaste = async (event) => {
            const file = extractImageFromClipboard(event.clipboardData);
            if (!file) return;
            event.preventDefault();
            setError(null);
            setBusy(true);
            try {
                const url = URL.createObjectURL(file);
                setPreviewUrl((prev) => {
                    if (prev) URL.revokeObjectURL(prev);
                    return url;
                });
                await onProofImage(file);
            } catch (e) {
                setError(e?.message || 'Không lưu được proof.');
            } finally {
                setBusy(false);
            }
        };

        window.addEventListener('paste', onPaste);
        return () => window.removeEventListener('paste', onPaste);
    }, [open, onProofImage]);

    if (!open || !comment) return null;

    return (
        <aside className="seeding-ws__drawer" data-drawer="global-work">
            <div className="seeding-ws__drawer-head">
                <h2>Đang thực hiện</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onClose} title="Thu nhỏ">
                    <X size={16} />
                </button>
            </div>

            <div className="seeding-ws__drawer-section">
                <div className="seeding-ws__section-title">Topic</div>
                <div className="seeding-ws__drawer-topic">{topic?.preview || topic?.full_text || '—'}</div>
            </div>

            <div className="seeding-ws__drawer-section">
                <div className="seeding-ws__section-title">Bình luận</div>
                <div className="seeding-ws__drawer-comment">{comment.text}</div>
                <button type="button" className="seeding-ws__btn seeding-ws__btn--primary" onClick={onCopy}>
                    <Copy size={14} /> Copy bình luận
                </button>
            </div>

            {topic?.social_url ? (
                <div className="seeding-ws__drawer-section">
                    <a
                        className="seeding-ws__btn seeding-ws__btn--ghost seeding-ws__btn--block"
                        href={topic.social_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Mở bài social <ExternalLink size={14} />
                    </a>
                </div>
            ) : null}

            <div className="seeding-ws__drawer-section">
                <div className="seeding-ws__section-title">Proof</div>
                <div
                    ref={zoneRef}
                    className={`seeding-ws__proof-zone${busy ? ' is-busy' : ''}`}
                    tabIndex={0}
                >
                    {previewUrl ? (
                        <img src={previewUrl} alt="Proof preview" className="seeding-ws__proof-preview" />
                    ) : (
                        <span>Ctrl + V ảnh chụp màn hình tại đây</span>
                    )}
                </div>
                {busy ? <div className="seeding-ws__muted">Đang lưu proof…</div> : null}
                {error ? <div className="seeding-ws__error">{error}</div> : null}
            </div>

            <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onRelease}>
                Bỏ việc
            </button>
            <p className="seeding-ws__proto-note">Claim local — chưa khóa multi-user.</p>
        </aside>
    );
}
