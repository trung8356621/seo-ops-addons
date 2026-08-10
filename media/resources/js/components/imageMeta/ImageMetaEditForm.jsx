import React from 'react';
import { supportsWordPressImageSizes } from '@wordpress-addon/utils/wordpressImageUrl.js';
import { t } from '@content-addon/utils/i18n.js';
import {
    ImageMetaAlignSelect,
    ImageMetaFieldLabel,
    ImageMetaFormActions,
    ImageMetaSizeSelect,
    ImageMetaTextInput,
    ImageMetaTextarea,
} from './ImageMetaFormFields';

/**
 * Shared image meta form (WordPress size, align, alt, title, caption) for editor popovers.
 */
export default function ImageMetaEditForm({
    idPrefix = 'seo-img',
    size = 'full',
    onSizeChange,
    align,
    onAlignChange,
    alt,
    onAltChange,
    title,
    onTitleChange,
    caption,
    onCaptionChange,
    onCancel,
    onApply,
    showSizeSelect = true,
    showAlignSelect = true,
    src = '',
    wpSrc = '',
    wpAttachmentId = null,
}) {
    const canPickWpSize = supportsWordPressImageSizes({ src, wpSrc, wpAttachmentId });

    return (
        <>
            <p className="seo-image-meta-panel-title">{t('edit_image')}</p>

            {showSizeSelect && canPickWpSize ? (
                <>
                    <ImageMetaFieldLabel htmlFor={`${idPrefix}-size`}>{t('image_size')}</ImageMetaFieldLabel>
                    <ImageMetaSizeSelect
                        id={`${idPrefix}-size`}
                        value={size}
                        onChange={(e) => onSizeChange?.(e.target.value)}
                    />
                </>
            ) : null}

            {showAlignSelect ? (
                <>
                    <ImageMetaFieldLabel htmlFor={`${idPrefix}-align`}>{t('image_align')}</ImageMetaFieldLabel>
                    <ImageMetaAlignSelect
                        id={`${idPrefix}-align`}
                        value={align}
                        onChange={(e) => onAlignChange(e.target.value)}
                    />
                </>
            ) : null}

            <ImageMetaFieldLabel htmlFor={`${idPrefix}-alt`}>{t('alt_text')}</ImageMetaFieldLabel>
            <ImageMetaTextInput
                id={`${idPrefix}-alt`}
                value={alt}
                onChange={(e) => onAltChange(e.target.value)}
                placeholder={t('image_alt_placeholder')}
            />

            <ImageMetaFieldLabel htmlFor={`${idPrefix}-title`}>{t('title')}</ImageMetaFieldLabel>
            <ImageMetaTextInput
                id={`${idPrefix}-title`}
                value={title}
                onChange={(e) => onTitleChange(e.target.value)}
                placeholder={t('image_title_placeholder')}
            />

            <ImageMetaFieldLabel htmlFor={`${idPrefix}-caption`}>{t('caption')}</ImageMetaFieldLabel>
            <ImageMetaTextarea
                id={`${idPrefix}-caption`}
                value={caption}
                onChange={(e) => onCaptionChange(e.target.value)}
                placeholder={t('image_caption_placeholder')}
            />

            <ImageMetaFormActions onCancel={onCancel} onApply={onApply} />
        </>
    );
}
