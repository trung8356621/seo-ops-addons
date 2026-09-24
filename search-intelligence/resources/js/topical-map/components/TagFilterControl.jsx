import { useEffect, useMemo, useRef, useState } from 'react';

/** Show at most this many selected chips; beyond that → first (CHIP_LIMIT - 1) + "+N". */
const CHIP_LIMIT = 3;

/**
 * Compact Tags control: [Tags ▾] + selected chips + popover checklist.
 */
export default function TagFilterControl({
    labels,
    tagFacets,
    untaggedCount,
    selectedTagIds,
    showUntagged,
    tagFilterAll,
    onToggleAll,
    onToggleUntagged,
    onToggleTag,
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const rootRef = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const facets = Array.isArray(tagFacets) ? tagFacets : [];
    const showSearch = facets.length > 10;
    const q = query.trim().toLocaleLowerCase();

    const visibleFacets = useMemo(() => {
        if (!q) {
            return facets;
        }
        return facets.filter((f) => String(f.name || '').toLocaleLowerCase().includes(q));
    }, [facets, q]);

    const selectedFacets = facets.filter((f) => selectedTagIds.includes(Number(f.id)));
    const selectedItems = [];
    if (!tagFilterAll) {
        for (const f of selectedFacets) {
            selectedItems.push({ key: `tag-${f.id}`, id: Number(f.id), label: f.name, kind: 'tag' });
        }
        if (showUntagged) {
            selectedItems.push({ key: 'untagged', id: 0, label: labels.untagged || 'Untagged', kind: 'untagged' });
        }
    }

    const chipCap = selectedItems.length > CHIP_LIMIT ? CHIP_LIMIT - 1 : selectedItems.length;
    const visibleChips = selectedItems.slice(0, chipCap);
    const overflow = Math.max(0, selectedItems.length - visibleChips.length);

    const removeChip = (item) => {
        if (item.kind === 'untagged') {
            onToggleUntagged();
            return;
        }
        onToggleTag(item.id);
    };

    return (
        <div className="tm-tags" ref={rootRef}>
            <button
                type="button"
                className={`tm-btn tm-tags__trigger ${open ? 'is-active' : ''}`}
                aria-expanded={open ? 'true' : 'false'}
                aria-haspopup="dialog"
                onClick={() => setOpen((v) => !v)}
            >
                {labels.tags || 'Tags'}
                <span className="tm-tags__caret" aria-hidden="true">▾</span>
            </button>

            <div className="tm-tags__chips" aria-label="Selected tags">
                {tagFilterAll || selectedItems.length === 0 ? (
                    <span className="tm-tags__all-hint">{labels.tagsAll || 'All tags'}</span>
                ) : (
                    <>
                        {visibleChips.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                className="tm-chip tm-chip--selected"
                                onClick={() => removeChip(item)}
                                title={`Remove ${item.label}`}
                            >
                                <span>{item.label}</span>
                                <span className="tm-chip__x" aria-hidden="true">×</span>
                            </button>
                        ))}
                        {overflow > 0 ? (
                            <button
                                type="button"
                                className="tm-chip tm-chip--more"
                                onClick={() => setOpen(true)}
                            >
                                +{overflow}
                            </button>
                        ) : null}
                    </>
                )}
            </div>

            {open ? (
                <div className="tm-tags__popover" role="dialog" aria-label={labels.tags || 'Tags'}>
                    <div className="tm-tags__popover-head">
                        <strong>{labels.tags || 'Tags'}</strong>
                        <div className="tm-tags__popover-actions">
                            <button
                                type="button"
                                className="tm-btn tm-btn--ghost"
                                onClick={onToggleAll}
                                disabled={Boolean(tagFilterAll)}
                            >
                                Clear
                            </button>
                            <button
                                type="button"
                                className="tm-btn tm-btn--ghost"
                                onClick={onToggleAll}
                                disabled={Boolean(tagFilterAll)}
                            >
                                {labels.tagsAll || 'All'}
                            </button>
                        </div>
                    </div>

                    {showSearch ? (
                        <input
                            type="search"
                            className="tm-tags__search"
                            placeholder="Search tags…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    ) : null}

                    <div className="tm-tags__list">
                        <label className="tm-tags__row">
                            <input
                                type="checkbox"
                                checked={Boolean(tagFilterAll)}
                                onChange={onToggleAll}
                            />
                            <span className="tm-tags__row-name">{labels.tagsAll || 'All'}</span>
                        </label>

                        {visibleFacets.map((facet) => {
                            const id = Number(facet.id);
                            const checked = !tagFilterAll && selectedTagIds.includes(id);
                            return (
                                <label key={id} className="tm-tags__row">
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={() => onToggleTag(id)}
                                    />
                                    <span className="tm-tags__row-name">{facet.name}</span>
                                    <span className="tm-tags__row-count">
                                        {Number(facet.topic_count || 0)}
                                    </span>
                                </label>
                            );
                        })}

                        <label className="tm-tags__row">
                            <input
                                type="checkbox"
                                checked={!tagFilterAll && Boolean(showUntagged)}
                                onChange={onToggleUntagged}
                            />
                            <span className="tm-tags__row-name">{labels.untagged || 'Untagged'}</span>
                            <span className="tm-tags__row-count">{Number(untaggedCount || 0)}</span>
                        </label>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
