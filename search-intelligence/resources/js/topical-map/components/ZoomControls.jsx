/**
 * Zoom in / out / reset for ECharts tree & graph roam.
 */
export default function ZoomControls({
    zoomPercent = 100,
    disabled = false,
    onZoomIn,
    onZoomOut,
    onReset,
}) {
    return (
        <div className="tm-zoom" role="group" aria-label="Chart zoom">
            <button
                type="button"
                className="tm-zoom__btn"
                onClick={onZoomOut}
                disabled={disabled}
                title="Zoom out"
                aria-label="Zoom out"
            >
                −
            </button>
            <span className="tm-zoom__indicator" aria-live="polite">
                {Math.round(zoomPercent)}%
            </span>
            <button
                type="button"
                className="tm-zoom__btn"
                onClick={onZoomIn}
                disabled={disabled}
                title="Zoom in"
                aria-label="Zoom in"
            >
                +
            </button>
            <button
                type="button"
                className="tm-zoom__btn tm-zoom__btn--reset"
                onClick={onReset}
                disabled={disabled}
                title="Reset zoom"
                aria-label="Reset zoom"
            >
                Fit
            </button>
        </div>
    );
}
