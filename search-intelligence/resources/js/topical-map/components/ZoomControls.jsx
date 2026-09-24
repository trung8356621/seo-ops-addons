/**
 * Zoom in / out / Fit for ECharts tree & graph roam.
 * Treemap: scale +/- disabled; Fit restores overview root.
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
                title={scaleDisabled && !disabled ? 'Scale not used in Treemap — use Fit / breadcrumb' : 'Zoom out'}
                aria-label="Zoom out"
            >
                −
            </button>
            <span className="tm-zoom__indicator" aria-live="polite">
                {scaleDisabled && !disabled ? '—' : `${Math.round(zoomPercent)}%`}
            </span>
            <button
                type="button"
                className="tm-zoom__btn"
                onClick={onZoomIn}
                disabled={noScale}
                title={scaleDisabled && !disabled ? 'Scale not used in Treemap — use Fit / breadcrumb' : 'Zoom in'}
                aria-label="Zoom in"
            >
                +
            </button>
            <button
                type="button"
                className="tm-zoom__btn tm-zoom__btn--reset"
                onClick={onReset}
                disabled={disabled}
                title={scaleDisabled ? 'Back to full Treemap overview' : 'Reset zoom'}
                aria-label="Fit"
            >
                Fit
            </button>
        </div>
    );
}
