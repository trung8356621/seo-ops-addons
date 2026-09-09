import React, { useMemo } from 'react';
import { PanelRightClose, PanelRightOpen } from 'lucide-react';
import { derivePersonalSeedingStats } from '../features/workspace/selectors';

/**
 * Personal Seeding stats — localStorage SoT (current user only).
 * Does NOT fake multi-employee team data.
 *
 * @param {{
 *   open?: boolean,
 *   collapsed: boolean,
 *   topics: Array<Record<string, unknown>>,
 *   seedBatches: Array<Record<string, unknown>>,
 *   seedOutputs: Array<Record<string, unknown>>,
 *   seedLinks: Array<Record<string, unknown>>,
 *   userId: number|string,
 *   onToggleCollapse: () => void,
 * }} props
 */
export default function TeamStatsSidebar({
    open = true,
    collapsed,
    topics,
    seedBatches,
    seedOutputs,
    seedLinks,
    userId,
    onToggleCollapse,
}) {
    const stats = useMemo(
        () => derivePersonalSeedingStats({
            topics,
            batches: seedBatches,
            outputs: seedOutputs,
            seedLinks,
            userId,
        }),
        [topics, seedBatches, seedOutputs, seedLinks, userId],
    );

    if (!open) return null;

    if (collapsed) {
        return (
            <aside className="seeding-ws__sidebar seeding-ws__sidebar--collapsed" data-sidebar="personal-stats">
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Mở thống kê">
                    <PanelRightOpen size={18} />
                </button>
            </aside>
        );
    }

    return (
        <aside className="seeding-ws__sidebar" data-sidebar="personal-stats">
            <div className="seeding-ws__sidebar-head">
                <h2>Seeding hôm nay</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Thu gọn">
                    <PanelRightClose size={16} />
                </button>
            </div>

            <p className="seeding-ws__sidebar-lead">Hoạt động của tôi (local)</p>

            <section className="seeding-ws__section" data-stats="today">
                <div className="seeding-ws__section-title">Hôm nay</div>
                <dl className="seeding-ws__stat-rows">
                    <div className="seeding-ws__stat-row">
                        <dt>Lượt Gen</dt>
                        <dd>{stats.today.genBatches}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Nội dung đã tạo</dt>
                        <dd>{stats.today.contents}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Topic đã dùng</dt>
                        <dd>{stats.today.topicsUsed}</dd>
                    </div>
                </dl>
            </section>

            <section className="seeding-ws__section" data-stats="links">
                <div className="seeding-ws__section-title">Link của tôi</div>
                <dl className="seeding-ws__stat-rows">
                    <div className="seeding-ws__stat-row">
                        <dt>Link đang active</dt>
                        <dd>{stats.today.linksActive}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Đã đạt ngưỡng hôm nay</dt>
                        <dd>{stats.today.linksAtLimit}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    );
}
