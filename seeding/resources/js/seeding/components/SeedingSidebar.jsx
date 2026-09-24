import React, { useMemo } from 'react';
import { PanelRightClose, PanelRightOpen } from 'lucide-react';
import { derivePersonalSeedingStats } from '../features/workspace/selectors';
import LinkPoolPanel from './LinkPoolPanel';

/**
 * Right sidebar — GLOBAL/PERSONAL Seeder stats only.
 * Does NOT bind to active/selected Topic.
 * Creator may open DB-backed assignment list (LinkPoolPanel).
 *
 * @param {{
 *   open?: boolean,
 *   collapsed: boolean,
 *   topics: Array<Record<string, unknown>>,
 *   seedBatches: Array<Record<string, unknown>>,
 *   seedOutputs: Array<Record<string, unknown>>,
 *   seedLinks?: Array<Record<string, unknown>>,
 *   linkAssignments?: Array<Record<string, unknown>>,
 *   userId: number|string,
 *   linkPoolOpen?: boolean,
 *   canManageLinkPool?: boolean,
 *   onToggleCollapse: () => void,
 *   onOpenLinkPool?: () => void,
 *   onCloseLinkPool?: () => void,
 *   onAssignmentsChange?: (rows: Array<Record<string, unknown>>) => void,
 * }} props
 */
export default function SeedingSidebar({
    open = true,
    collapsed,
    topics,
    seedBatches,
    seedOutputs,
    seedLinks = [],
    linkAssignments = [],
    userId,
    linkPoolOpen = false,
    canManageLinkPool = false,
    onToggleCollapse,
    onOpenLinkPool,
    onCloseLinkPool,
    onAssignmentsChange,
}) {
    const stats = useMemo(
        () => derivePersonalSeedingStats({
            topics,
            batches: seedBatches,
            outputs: seedOutputs,
            seedLinks: linkAssignments.length > 0 ? linkAssignments : seedLinks,
            userId,
        }),
        [topics, seedBatches, seedOutputs, seedLinks, linkAssignments, userId],
    );

    if (!open) return null;

    if (collapsed) {
        return (
            <aside className="seeding-ws__sidebar seeding-ws__sidebar--collapsed" data-sidebar="personal-stats">
                <button
                    type="button"
                    className="seeding-ws__icon-btn"
                    onClick={onToggleCollapse}
                    title="Mở panel"
                    aria-label="Mở panel"
                    data-sidebar-reopen
                >
                    <PanelRightOpen size={18} />
                </button>
            </aside>
        );
    }

    return (
        <aside className="seeding-ws__sidebar" data-sidebar="personal-stats">
            <div className="seeding-ws__sidebar-stack">
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

                {canManageLinkPool ? (
                    <section className="seeding-ws__section" data-stats="links">
                        <div className="seeding-ws__section-title">Link seeding</div>
                        <dl className="seeding-ws__stat-rows">
                            <div className="seeding-ws__stat-row">
                                <dt>Link đang active</dt>
                                <dd>{stats.today.linksActive}</dd>
                            </div>
                        </dl>
                        {!linkPoolOpen && typeof onOpenLinkPool === 'function' ? (
                            <button
                                type="button"
                                className="seeding-ws__btn seeding-ws__btn--ghost seeding-ws__btn--block"
                                onClick={onOpenLinkPool}
                            >
                                Quản lý danh sách link
                            </button>
                        ) : null}
                    </section>
                ) : null}

                {linkPoolOpen && canManageLinkPool ? (
                    <div className="seeding-ws__sidebar-pool" data-drawer="link-pool">
                        <LinkPoolPanel
                            open
                            canManage={canManageLinkPool}
                            legacySeedLinks={seedLinks}
                            onClose={onCloseLinkPool}
                            onAssignmentsChange={onAssignmentsChange}
                        />
                    </div>
                ) : null}
            </div>
        </aside>
    );
}
