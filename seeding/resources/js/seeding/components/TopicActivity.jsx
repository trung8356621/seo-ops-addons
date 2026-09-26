import { auditT } from '../../i18n-audit.js';
import React from 'react';
import { relativeTime } from '../features/workspace/selectors';

/**
 * Activity + progress shells — Topic Detail only.
 *
 * @param {{ topic: Record<string, unknown> }} props
 */
export default function TopicActivity({ topic }) {
    const state = topic.state || 'draft';
    const events = [
        topic.created_at ? { id: 'created', text: auditT('audit_73f96c6a4c30'), time: topic.created_at } : null,
        topic.shared_at ? { id: 'shared', text: auditT('audit_7c497c06e568'), time: topic.shared_at } : null,
        topic.updated_at ? { id: 'updated', text: auditT('audit_bef6b79c2950'), time: topic.updated_at } : null,
        topic.archived_at ? { id: 'archived', text: auditT('audit_2298008b28ed'), time: topic.archived_at } : null,
    ].filter(Boolean);

    return (
        <div className="seeding-ws__detail-side">
            <section className="seeding-ws__panel">
                <h2>Tiến độ</h2>
                <div className="seeding-ws__progress-circles">
                    <div className="seeding-ws__circle">
                        <strong>{state === 'shared' ? 1 : 0}</strong>
                        <span>Đang chạy</span>
                    </div>
                    <div className="seeding-ws__circle">
                        <strong>{state === 'completed' ? 1 : 0}</strong>
                        <span>Hoàn tất</span>
                    </div>
                    <div className="seeding-ws__circle">
                        <strong>0</strong>
                        <span>Chờ proof</span>
                    </div>
                </div>
            </section>

            <section className="seeding-ws__panel seeding-ws__panel--grow" data-section="activity">
                <h2>Hoạt động</h2>
                {events.length === 0 ? (
                    <div className="seeding-ws__muted">Chưa có hoạt động local.</div>
                ) : (
                    <ul className="seeding-ws__activity">
                        {events.map((item) => (
                            <li key={item.id}>
                                <div>{item.text}</div>
                                <time>{relativeTime(item.time)}</time>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
