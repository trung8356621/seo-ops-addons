import { auditT } from '../../i18n-audit.js';
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
        { key: 'topics', label: auditT('audit_95f745575c44'), value: metrics.topics, hint: auditT('audit_52c95f3935f4') },
        { key: 'gen', label: auditT('audit_3f27f0b8fb6d'), value: metrics.genToday, hint: 'Theo batch' },
        { key: 'contents', label: auditT('audit_3be3e0db45cc'), value: metrics.contentsToday, hint: 'Theo output' },
        { key: 'topicsUsed', label: auditT('audit_697a5f602ef9'), value: metrics.topicsUsedToday, hint: auditT('audit_5af103e1e7c7') },
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
