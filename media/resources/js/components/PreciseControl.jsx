import React, { useCallback } from 'react';

function clampNumber(raw, min, max, step) {
    let n = typeof raw === 'number' ? raw : parseFloat(String(raw).replace(',', '.'));

    if (Number.isNaN(n)) {
        n = min;
    }

    if (step > 0 && step < 1) {
        const decimals = String(step).includes('.') ? String(step).split('.')[1].length : 0;
        const factor = 10 ** decimals;
        n = Math.round(n * factor) / factor;
    } else if (step >= 1) {
        n = Math.round(n);
    }

    return Math.min(max, Math.max(min, n));
}

/**
 * Range + number input đồng bộ, giá trị luôn nằm trong [min, max].
 *
 * @param {{
 *   min: number,
 *   max: number,
 *   step?: number,
 *   value: number,
 *   onChange: (n: number) => void,
 *   suffix?: string,
 *   className?: string,
 *   inputWidth?: string,
 * }} props
 */
export default function PreciseControl({
    min,
    max,
    step = 1,
    value,
    onChange,
    suffix = '',
    className = '',
    inputWidth = '4.25rem',
}) {
    const apply = useCallback(
        (raw) => {
            onChange(clampNumber(raw, min, max, step));
        },
        [min, max, step, onChange],
    );

    const displayValue = clampNumber(value, min, max, step);

    return (
        <div className={`wm-precise-control ${className}`.trim()}>
            <input
                type="range"
                className="wm-precise-control__range"
                min={min}
                max={max}
                step={step}
                value={displayValue}
                onChange={(e) => apply(e.target.value)}
            />
            <div className="wm-precise-control__number-wrap">
                <input
                    type="number"
                    className="wm-precise-control__number"
                    style={{ width: inputWidth }}
                    min={min}
                    max={max}
                    step={step}
                    value={displayValue}
                    onChange={(e) => apply(e.target.value)}
                    onBlur={(e) => apply(e.target.value)}
                />
                {suffix ? <span className="wm-precise-control__suffix">{suffix}</span> : null}
            </div>
        </div>
    );
}
