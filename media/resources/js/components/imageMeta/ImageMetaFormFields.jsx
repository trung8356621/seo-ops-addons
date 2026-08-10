import React from 'react';
import SeoSelect from '@content-addon/components/SeoSelect.jsx';
import { IMAGE_ALIGN_OPTIONS } from '../../utils/blockImageUtils';import { WP_IMAGE_SIZE_OPTIONS } from '@wordpress-addon/utils/wordpressImageSize.js';
import { t } from '@content-addon/utils/i18n.js';

export function ImageMetaFieldLabel({ htmlFor, children }) {
    return (
        <label className="seo-image-meta-label" htmlFor={htmlFor}>
            {children}
        </label>
    );
}

export function ImageMetaTextInput({ id, value, onChange, placeholder }) {
    return (
        <input
            id={id}
            type="text"
            className="seo-image-meta-input"
            value={value}
            onChange={onChange}
            placeholder={placeholder}
        />
    );
}

export function ImageMetaTextarea({ id, value, onChange, rows = 2, placeholder }) {
    return (
        <textarea
            id={id}
            className="seo-image-meta-textarea"
            rows={rows}
            value={value}
            onChange={onChange}
            placeholder={placeholder}
        />
    );
}

export function ImageMetaSelect({ id, value, onChange, children }) {
    return (
        <SeoSelect id={id} value={value} onChange={onChange} size="compact" selectClassName="seo-image-meta-select-field">
            {children}
        </SeoSelect>
    );
}
export function ImageMetaAlignSelect({ id, value, onChange }) {
    return (
        <ImageMetaSelect id={id} value={value} onChange={onChange}>
            {IMAGE_ALIGN_OPTIONS.map(({ id: optionId, labelKey }) => (
                <option key={optionId} value={optionId}>
                    {t(labelKey)}
                </option>
            ))}
        </ImageMetaSelect>
    );
}

export function ImageMetaSizeSelect({ id, value, onChange }) {
    return (
        <ImageMetaSelect id={id} value={value} onChange={onChange}>
            {WP_IMAGE_SIZE_OPTIONS.map(({ id: optionId, labelKey }) => (
                <option key={optionId} value={optionId}>
                    {t(labelKey)}
                </option>
            ))}
        </ImageMetaSelect>
    );
}

export function ImageMetaFormActions({ onCancel, onApply, applyLabel }) {
    return (
        <div className="seo-image-meta-actions">
            <button type="button" className="seo-image-meta-btn" onClick={onCancel}>
                {t('cancel')}
            </button>
            <button type="button" className="seo-image-meta-btn is-primary" onClick={onApply}>
                {applyLabel ?? t('apply')}
            </button>
        </div>
    );
}
