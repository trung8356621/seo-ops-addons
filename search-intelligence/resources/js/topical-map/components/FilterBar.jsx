export default function FilterBar({
    labels,
    tagFacets,
    untaggedCount,
    selectedTagIds,
    showUntagged,
    tagFilterAll,
    mcpMin,
    mcpMax,
    renderer,
    onToggleAll,
    onToggleUntagged,
    onToggleTag,
    onMcpChange,
    onRendererChange,
}) {
    return (
        <div className="tm-filters">
            <div className="tm-tag-filters" role="group" aria-label={labels.tags}>
                <span className="tm-tag-filters__label">{labels.tags}:</span>
                <button
                    type="button"
                    className={`tm-chip ${tagFilterAll ? 'is-active' : ''}`}
                    onClick={onToggleAll}
                >
                    {labels.tagsAll}
                </button>
                {(tagFacets || []).map((facet) => {
                    const id = Number(facet.id);
                    const active = !tagFilterAll && selectedTagIds.includes(id);
                    return (
                        <button
                            key={id}
                            type="button"
                            className={`tm-chip ${active ? 'is-active' : ''}`}
                            onClick={() => onToggleTag(id)}
                        >
                            {facet.name} {Number(facet.topic_count || 0)}
                        </button>
                    );
                })}
                <button
                    type="button"
                    className={`tm-chip ${(!tagFilterAll && showUntagged) ? 'is-active' : ''}`}
                    onClick={onToggleUntagged}
                >
                    {labels.untagged} {Number(untaggedCount || 0)}
                </button>
            </div>

            <div className="tm-mcp-filter" aria-label="MCP %">
                <span className="tm-mcp-filter__label">
                    MCP: {Math.round(mcpMin)}% – {Math.round(mcpMax)}%
                </span>
                <div className="tm-mcp-filter__sliders">
                    <label className="tm-mcp-filter__field">
                        <span>Min</span>
                        <input
                            type="range"
                            min={0}
                            max={100}
                            value={mcpMin}
                            onChange={(e) => onMcpChange(Number(e.target.value), mcpMax)}
                        />
                    </label>
                    <label className="tm-mcp-filter__field">
                        <span>Max</span>
                        <input
                            type="range"
                            min={0}
                            max={100}
                            value={mcpMax}
                            onChange={(e) => onMcpChange(mcpMin, Number(e.target.value))}
                        />
                    </label>
                </div>
            </div>

            <div className="tm-renderer" role="tablist" aria-label="Topical Map renderer">
                {['tree', 'network', 'sunburst'].map((mode) => (
                    <button
                        key={mode}
                        type="button"
                        role="tab"
                        className={`tm-btn ${renderer === mode ? 'is-active' : ''}`}
                        aria-selected={renderer === mode ? 'true' : 'false'}
                        onClick={() => onRendererChange(mode)}
                    >
                        {labels[mode]}
                    </button>
                ))}
            </div>
        </div>
    );
}
