/**
 * Zoom in / out / Fit for ECharts tree & graph roam.
 * Not shown in Treemap mode (fixed full-site overview — no scale/drill).
 */
export default function ZoomControls({
    zoomPercent = 100,
    disabled = false,
    scaleDisabled = false,
    onZoomIn,
    onZoomOut,
    onReset,
}) {
    const noScale = Boolean(disabled || scaleDisabled);
    return (
        <div className="tm-zoom" role="group" aria-label="Chart zoom">
            <button
                type="button"
                className="tm-zoom__btn"
                onClick={onZoomOut}
                disabled={noScale}
                title="Zoom out"
                aria-label="Zoom out"
            >
                −
            </button>
            <span className="tm-zoom__indicator" aria-live="polite">
                {`${Math.round(zoomPercent)}%`}
            </span>
            <button
                type="button"
                className="tm-zoom__btn"
                onClick={onZoomIn}
                disabled={noScale}
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
                aria-label="Fit"
            >
                Fit
            </button>
        </div>
    );
}
