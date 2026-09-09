import React from 'react';

/**
 * Personal Flexible Seeding metrics (local document).
 *
 * @param {{
 *   metrics: {
 *     topics: number,
 *     genToday: number,
 *     contentsToday: number,
 *     topicsUsedToday: number,
 *   },
 * }} props
 */
export default function MetricCards({ metrics }) {
    const cards = [
        { key: 'topics', label: 'Chủ đề', value: metrics.topics, hint: 'Đang trên feed' },
        { key: 'gen', label: 'Lượt Gen hôm nay', value: metrics.genToday, hint: 'Theo batch' },
        { key: 'contents', label: 'Nội dung đã tạo', value: metrics.contentsToday, hint: 'Theo output' },
        { key: 'topicsUsed', label: 'Topic đã dùng', value: metrics.topicsUsedToday, hint: 'Distinct hôm nay' },
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
