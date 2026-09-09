import React from 'react';
import { Link2, Plus } from 'lucide-react';

/**
 * @param {{
 *   filter: string,
 *   search: string,
 *   counts: Record<string, number>,
 *   canMutate: boolean,
 *   onFilter: (filter: string) => void,
 *   onSearch: (value: string) => void,
 *   onCreate: () => void,
 *   onOpenLinkPool: () => void,
 * }} props
 */
export default function FeedToolbar({
    filter,
    search,
    counts,
    canMutate,
    onFilter,
    onSearch,
    onCreate,
    onOpenLinkPool,
}) {
    const tabs = [
        { id: 'all', label: 'Tất cả', count: counts.all ?? 0 },
        { id: 'draft', label: 'Mới', count: counts.draft ?? 0 },
        { id: 'recent', label: 'Đã dùng gần đây', count: counts.recent ?? 0 },
        { id: 'archived', label: 'Lưu trữ', count: counts.archived ?? 0 },
    ];

    return (
        <div className="seeding-ws__toolbar">
            <div className="seeding-ws__toolbar-row">
                <div className="seeding-ws__tabs" role="tablist">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            role="tab"
                            aria-selected={filter === tab.id}
                            className={`seeding-ws__tab${filter === tab.id ? ' is-active' : ''}`}
                            onClick={() => onFilter(tab.id)}
                        >
                            {tab.label} ({tab.count})
                        </button>
                    ))}
                </div>
                <div className="seeding-ws__toolbar-actions">
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--ghost"
                        onClick={onOpenLinkPool}
                    >
                        <Link2 size={14} />
                        Link của tôi
                    </button>
                    <button
                        type="button"
                        className="seeding-ws__btn seeding-ws__btn--primary"
                        onClick={onCreate}
                        disabled={!canMutate}
                    >
                        <Plus size={14} />
                        Tạo chủ đề
                    </button>
                </div>
            </div>
            <div className="seeding-ws__search">
                <input
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    placeholder="Tìm kiếm chủ đề..."
                />
            </div>
        </div>
    );
}
