import React, { useMemo } from 'react';
import { BarChart3, PanelRightClose, PanelRightOpen } from 'lucide-react';
import { deriveTeamStats } from '../features/workspace/selectors';

/**
 * Module-level Seeding team / workload statistics panel.
 * Does NOT show topic detail — feed already owns that.
 *
 * @param {{
 *   open?: boolean,
 *   collapsed: boolean,
 *   topics: Array<Record<string, unknown>>,
 *   reports: Array<Record<string, unknown>>,
 *   onToggleCollapse: () => void,
 *   onOpenReport: () => void,
 * }} props
 */
export default function TeamStatsSidebar({
    open = true,
    collapsed,
    topics,
    reports,
    onToggleCollapse,
    onOpenReport,
}) {
    const stats = useMemo(() => deriveTeamStats(topics, reports), [topics, reports]);

    if (!open) return null;

    if (collapsed) {
        return (
            <aside className="seeding-ws__sidebar seeding-ws__sidebar--collapsed" data-sidebar="team-stats">
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Mở thống kê">
                    <PanelRightOpen size={18} />
                </button>
            </aside>
        );
    }

    return (
        <aside className="seeding-ws__sidebar" data-sidebar="team-stats">
            <div className="seeding-ws__sidebar-head">
                <h2>Thống kê Seeding</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Thu gọn">
                    <PanelRightClose size={16} />
                </button>
            </div>

            <p className="seeding-ws__sidebar-lead">Hôm nay team Seeding đang làm tới đâu?</p>

            <section className="seeding-ws__section" data-stats="today">
                <div className="seeding-ws__section-title">Thống kê hôm nay</div>
                <dl className="seeding-ws__stat-rows">
                    <div className="seeding-ws__stat-row">
                        <dt>Bình luận</dt>
                        <dd>{stats.today.commentsCreated}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Đã chia sẻ</dt>
                        <dd>{stats.today.shared}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Hoàn tất</dt>
                        <dd>{stats.today.completed}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Chủ đề mới</dt>
                        <dd>{stats.today.newTopics}</dd>
                    </div>
                </dl>
            </section>

            <section className="seeding-ws__section" data-stats="workload">
                <div className="seeding-ws__section-title">Workload</div>
                <dl className="seeding-ws__stat-rows">
                    <div className="seeding-ws__stat-row">
                        <dt>Đang làm</dt>
                        <dd>{stats.workload.inProgressTopics}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Chờ xử lý</dt>
                        <dd>{stats.workload.pendingDrafts}</dd>
                    </div>
                    <div className="seeding-ws__stat-row">
                        <dt>Comment đang claim</dt>
                        <dd>{stats.workload.inProgressComments}</dd>
                    </div>
                </dl>
            </section>

            <section className="seeding-ws__section" data-stats="employees">
                <div className="seeding-ws__section-title">Nhân viên</div>
                {stats.employees.length === 0 ? (
                    <div className="seeding-ws__sidebar-empty">Chưa có hoạt động Seeding.</div>
                ) : (
                    <ul className="seeding-ws__employee-list">
                        {stats.employees.map((emp) => (
                            <li key={emp.key} className="seeding-ws__employee-row">
                                <div className="seeding-ws__employee-name">{emp.displayName}</div>
                                <div className="seeding-ws__employee-meta">
                                    {emp.commentsCreated} bình luận
                                    {' · '}
                                    {emp.topicsOwned} topic
                                    {' · '}
                                    {emp.shares} share
                                </div>
                                {emp.inProgressClaims > 0 || emp.completedReports > 0 ? (
                                    <div className="seeding-ws__employee-workload">
                                        {emp.inProgressClaims > 0
                                            ? `Đang làm ${emp.inProgressClaims}`
                                            : `Hoàn tất ${emp.completedReports}`}
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <button
                type="button"
                className="seeding-ws__btn seeding-ws__btn--ghost seeding-ws__btn--block"
                onClick={onOpenReport}
            >
                <BarChart3 size={14} /> Xem báo cáo
            </button>
        </aside>
    );
}
