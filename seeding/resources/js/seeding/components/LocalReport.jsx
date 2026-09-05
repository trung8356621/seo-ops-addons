import React, { useEffect, useState } from 'react';
import { getProof } from '../services/proofStore';
import { relativeTime } from '../features/workspace/selectors';

/**
 * Minimal local report/history surface.
 *
 * @param {{
 *   reports: Array<Record<string, unknown>>,
 *   topics: Array<Record<string, unknown>>,
 *   open: boolean,
 *   onClose: () => void,
 * }} props
 */
export default function LocalReport({ reports, topics, open, onClose }) {
    if (!open) return null;

    const topicTitle = (id) => {
        const t = topics.find((x) => String(x.localId) === String(id));
        return t?.preview || id;
    };

    return (
        <div className="seeding-ws__history" data-section="local-report">
            <div className="seeding-ws__section-head">
                <h2 className="seeding-ws__section-title">Báo cáo</h2>
                <button type="button" className="seeding-ws__btn seeding-ws__btn--ghost" onClick={onClose}>Đóng</button>
            </div>
            {reports.length === 0 ? (
                <div className="seeding-ws__muted">Chưa có báo cáo hoàn tất.</div>
            ) : (
                <ul className="seeding-ws__history-list">
                    {[...reports].reverse().map((r) => (
                        <li key={r.id} className="seeding-ws__history-row">
                            <ProofThumb proofId={r.proof_id} />
                            <div>
                                <div className="seeding-ws__history-topic">{topicTitle(r.topic_id)}</div>
                                <div className="seeding-ws__history-comment">{r.comment_text}</div>
                                <div className="seeding-ws__muted">
                                    {r.user_display_name || r.user_id || '—'} · {relativeTime(r.completed_at)}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ProofThumb({ proofId }) {
    const [url, setUrl] = useState(null);
    useEffect(() => {
        let revoked = null;
        let alive = true;
        (async () => {
            try {
                const rec = await getProof(proofId);
                if (!alive || !rec?.blob) return;
                const objectUrl = URL.createObjectURL(rec.blob);
                revoked = objectUrl;
                setUrl(objectUrl);
            } catch {
                /* ignore */
            }
        })();
        return () => {
            alive = false;
            if (revoked) URL.revokeObjectURL(revoked);
        };
    }, [proofId]);

    if (!url) return <div className="seeding-ws__proof-thumb seeding-ws__proof-thumb--empty" />;
    return <img className="seeding-ws__proof-thumb" src={url} alt="" />;
}
