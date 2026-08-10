import React from 'react';

/**
 * Native select with a single chevron (wrapper avoids Filament / browser arrow stacking).
 *
 * @param {{
 *   id?: string,
 *   value?: string | number,
 *   onChange?: (event: React.ChangeEvent<HTMLSelectElement>) => void,
 *   options?: Array<{ value: string | number, label: React.ReactNode, disabled?: boolean }>,
 *   placeholder?: React.ReactNode,
 *   placeholderValue?: string,
 *   disabled?: boolean,
 *   required?: boolean,
 *   className?: string,
 *   selectClassName?: string,
 *   size?: 'default' | 'compact' | 'toolbar' | 'inline',
 *   children?: React.ReactNode,
 *   name?: string,
 *   'aria-label'?: string,
 * }} props
 */
export default function SeoSelect({
    id,
    value,
    onChange,
    options,
    placeholder,
    placeholderValue = '',
    disabled = false,
    required = false,
    className = '',
    selectClassName = '',
    size = 'default',
    children,
    name,
    'aria-label': ariaLabel,
}) {
    const wrapClass = [
        'seo-select-wrap',
        size !== 'default' ? `seo-select-wrap--${size}` : '',
        className,
    ]
        .filter(Boolean)
        .join(' ');

    const selectClass = ['seo-select', selectClassName].filter(Boolean).join(' ');

    const showPlaceholder = placeholder != null && placeholder !== false;

    return (
        <div className={wrapClass}>
            <select
                id={id}
                name={name}
                className={selectClass}
                value={value}
                onChange={onChange}
                disabled={disabled}
                required={required}
                aria-label={ariaLabel}
            >
                {showPlaceholder ? (
                    <option value={placeholderValue}>{placeholder}</option>
                ) : null}
                {Array.isArray(options)
                    ? options.map((option) => (
                          <option
                              key={String(option.value)}
                              value={String(option.value)}
                              disabled={Boolean(option.disabled)}
                          >
                              {option.label}
                          </option>
                      ))
                    : children}
            </select>
            <span className="seo-select-chevron" aria-hidden="true" />
        </div>
    );
}
