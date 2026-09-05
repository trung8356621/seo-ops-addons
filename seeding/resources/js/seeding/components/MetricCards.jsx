import React from 'react';

/**
 * @param {{
 *   metrics: { work: number, shared: number, completed: number, todayComments: number },
 * }} props
 */
export default function MetricCards({ metrics }) {
    const cards = [
        { key: 'work', label: 'Đang làm', value: metrics.work, hint: 'Nháp + đang chạy' },
        { key: 'shared', label: 'Đã chia sẻ', value: metrics.shared, hint: 'Đang chạy' },
        { key: 'completed', label: 'Hoàn tất', value: metrics.completed, hint: 'Chủ đề xong' },
        { key: 'today', label: 'Bình luận hôm nay', value: metrics.todayComments, hint: 'Từ báo cáo local' },
    ];

    return (
        <div className="seeding-ws__metrics">
            {cards.map((card) => (
                <div key={card.key} className="seeding-ws__metric-card">
                    <div className="seeding-ws__metric-label">{card.label}</div>
                    <div className="seeding-ws__metric-value">{card.value}</div>
                    <div className="seeding-ws__metric-hint">{card.hint}</div>
                </div>
            ))}
        </div>
    );
}
