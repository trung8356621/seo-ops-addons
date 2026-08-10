import React from 'react';
import PreciseControl from './PreciseControl';

/**
 * @param {{ label: string, value: object, onChange: (v: object) => void }} props
 */
export default function GradientColorPicker({ label, value, onChange }) {
    const config = value ?? { type: 'solid', color1: '#ffffff', color2: '#ff2d55', gradType: 'linear', angle: 0 };

    const updateVal = (key, val) => {
        onChange({ ...config, [key]: val });
    };

    return (
        <div className="wm-gradient-picker">
            <div className="wm-gradient-picker__head">
                <span className="wm-gradient-picker__label">{label}</span>
                <div className="wm-gradient-picker__toggle">
                    <button
                        type="button"
                        className={config.type === 'solid' ? 'is-active' : ''}
                        onClick={() => updateVal('type', 'solid')}
                    >
                        Solid
                    </button>
                    <button
                        type="button"
                        className={config.type === 'gradient' ? 'is-active' : ''}
                        onClick={() => updateVal('type', 'gradient')}
                    >
                        Gradient
                    </button>
                </div>
            </div>

            <div className="wm-gradient-picker__colors">
                <div className="wm-gradient-picker__swatch">
                    <label>Màu chính</label>
                    <div className="wm-gradient-picker__row">
                        <input
                            type="color"
                            value={config.color1 || '#ff2d55'}
                            onChange={(e) => updateVal('color1', e.target.value)}
                        />
                        <span>{config.color1}</span>
                    </div>
                </div>

                {config.type === 'gradient' ? (
                    <div className="wm-gradient-picker__swatch">
                        <label>Màu chuyển</label>
                        <div className="wm-gradient-picker__row">
                            <input
                                type="color"
                                value={config.color2 || '#3a2df5'}
                                onChange={(e) => updateVal('color2', e.target.value)}
                            />
                            <span>{config.color2}</span>
                        </div>
                    </div>
                ) : null}
            </div>

            {config.type === 'gradient' ? (
                <div className="wm-gradient-picker__grad">
                    <div className="wm-gradient-picker__grad-type">
                        <span>Kiểu:</span>
                        <button
                            type="button"
                            className={config.gradType === 'linear' ? 'is-active' : ''}
                            onClick={() => updateVal('gradType', 'linear')}
                        >
                            Tuyến tính
                        </button>
                        <button
                            type="button"
                            className={config.gradType === 'radial' ? 'is-active' : ''}
                            onClick={() => updateVal('gradType', 'radial')}
                        >
                            Tỏa tròn
                        </button>
                    </div>
                    {config.gradType === 'linear' ? (
                        <div className="wm-field">
                            <label>Góc xoay gradient</label>
                            <PreciseControl
                                min={0}
                                max={360}
                                step={1}
                                value={config.angle || 0}
                                onChange={(n) => updateVal('angle', n)}
                                suffix="°"
                            />
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
