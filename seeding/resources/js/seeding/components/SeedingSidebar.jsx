import React, { useMemo } from 'react';
import { ExternalLink, PanelRightClose, PanelRightOpen } from 'lucide-react';
import {
    deriveActiveTopicLinkTargets,
    derivePersonalSeedingStats,
} from '../features/workspace/selectors';

/**
 * Right workspace sidebar — personal stats, or active-topic target progress.
 *
 * @param {{
 *   open?: boolean,
 *   collapsed: boolean,
 *   topics: Array<Record<string, unknown>>,
 *   seedBatches: Array<Record<string, unknown>>,
 *   seedOutputs: Array<Record<string, unknown>>,
 *   seedLinks: Array<Record<string, unknown>>,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 *   activeTopic?: Record<string, unknown>|null,
 *   userId: number|string,
 *   onToggleCollapse: () => void,
 *   onOpenLinkPool?: () => void,
 * }} props
 */
export default function SeedingSidebar({
    open = true,
    collapsed,
    topics,
    seedBatches,
    seedOutputs,
    seedLinks,
    linkPreviewCache = {},
    activeTopic = null,
    userId,
    onToggleCollapse,
    onOpenLinkPool,
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

    const targetRows = useMemo(
        () => (activeTopic ? deriveActiveTopicLinkTargets(activeTopic, linkPreviewCache) : []),
        [activeTopic, linkPreviewCache],
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
            <div className="seeding-ws__sidebar-head">
                <h2>{activeTopic ? 'Topic hiện tại' : 'Seeding hôm nay'}</h2>
                <button type="button" className="seeding-ws__icon-btn" onClick={onToggleCollapse} title="Thu gọn">
                    <PanelRightClose size={16} />
                </button>
            </div>

            {activeTopic ? (
                <section className="seeding-ws__section" data-stats="active-topic" data-sidebar-active-topic>
                    <p className="seeding-ws__sidebar-lead seeding-ws__sidebar-lead--tight">
                        {String(activeTopic.title || activeTopic.preview || 'Chủ đề đang Gen').trim() || 'Chủ đề đang Gen'}
                    </p>
                    {targetRows.length === 0 ? (
                        <p className="seeding-ws__sidebar-empty">Chưa có link/target trong topic.</p>
                    ) : (
                        <ul className="seeding-ws__topic-targets">
                            {targetRows.map((row) => (
                                <li key={row.key} className="seeding-ws__topic-target">
                                    <div className="seeding-ws__topic-target-main">
                                        <a
                                            className="seeding-ws__topic-target-title"
                                            href={row.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            title={row.url}
                                        >
                                            {row.title}
                                        </a>
                                        <a
                                            className="seeding-ws__topic-target-url"
                                            href={row.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            title={row.url}
                                        >
                                            {row.urlShort}
                                        </a>
                                    </div>
                                    <div className="seeding-ws__topic-target-meta">
                                        <span className="seeding-ws__topic-target-count">
                                            {row.completed} / {row.target || '—'}
                                        </span>
                                        <a
                                            className="seeding-ws__icon-btn"
                                            href={row.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            title={row.url}
                                            aria-label="Mở link"
                                        >
                                            <ExternalLink size={14} />
                                        </a>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ) : (
                <>
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
                        {typeof onOpenLinkPool === 'function' ? (
                            <button
                                type="button"
                                className="seeding-ws__btn seeding-ws__btn--ghost seeding-ws__btn--block"
                                onClick={onOpenLinkPool}
                            >
                                Quản lý Link Pool
                            </button>
                        ) : null}
                    </section>
                </>
            )}
        </aside>
    );
}
