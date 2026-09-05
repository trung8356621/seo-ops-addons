import React from 'react';

/**
 * Distribution UI shell — Topic Detail only.
 * Assignment/quota algorithm intentionally not implemented.
 *
 * @param {{ topic: Record<string, unknown> }} props
 */
export default function DistributionPanel({ topic }) {
    const dist = topic?.distribution;
    const hasData = dist && typeof dist === 'object' && Array.isArray(dist.members) && dist.members.length > 0;

    return (
        <section className="seeding-ws__panel" data-section="distribution">
            <div className="seeding-ws__panel-head">
                <h2>Phân bổ</h2>
            </div>
            {!hasData ? (
                <div className="seeding-ws__muted">
                    Chưa có phân bổ. Assignment/quota sẽ cấu hình ở bước tiếp theo.
                </div>
            ) : (
                <>
                    <div className="seeding-ws__dist-summary">
                        <strong>{dist.people || 0} người / {dist.comments || 0} bình luận</strong>
                        <div className="seeding-ws__muted">Mỗi người {dist.perPerson || 2} bình luận</div>
                    </div>
                    <ul className="seeding-ws__member-list">
                        {dist.members.map((m) => (
                            <li key={m.id} className="seeding-ws__member-row">
                                <span className="seeding-ws__member-avatar">{String(m.name || '?').slice(0, 1)}</span>
                                <div className="seeding-ws__member-name">{m.name}</div>
                                <span className="seeding-ws__member-quota">{m.done}/{m.quota}</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
