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
        topic.created_at ? { id: 'created', text: 'Tạo chủ đề (local)', time: topic.created_at } : null,
        topic.shared_at ? { id: 'shared', text: 'Đẩy chia sẻ (local prototype)', time: topic.shared_at } : null,
        topic.updated_at ? { id: 'updated', text: 'Cập nhật nội dung', time: topic.updated_at } : null,
        topic.archived_at ? { id: 'archived', text: 'Lưu trữ', time: topic.archived_at } : null,
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
