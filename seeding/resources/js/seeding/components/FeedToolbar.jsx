import React from 'react';
import { Plus } from 'lucide-react';

/**
 * @param {{
 *   filter: string,
 *   search: string,
 *   counts: Record<string, number>,
 *   canMutate: boolean,
 *   onFilter: (filter: string) => void,
 *   onSearch: (value: string) => void,
 *   onCreate: () => void,
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
}) {
    const tabs = [
        { id: 'work', label: 'Đang làm', count: counts.work ?? 0 },
        { id: 'draft', label: 'Mới', count: counts.draft ?? 0 },
        { id: 'completed', label: 'Hoàn tất', count: counts.completed ?? 0 },
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
