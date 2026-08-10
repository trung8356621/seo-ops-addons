import React, { useState } from 'react';
import { AlignCenter, AlignLeft, AlignRight, Maximize2, Pencil, Trash2 } from 'lucide-react';
import { t } from '@content-addon/utils/i18n.js';
import {
    ImageMetaFieldLabel,
    ImageMetaFormActions,
    ImageMetaTextInput,
    ImageMetaTextarea,
} from './imageMeta/ImageMetaFormFields';

const ALIGN_OPTIONS = [
    { id: 'left', icon: AlignLeft, title: t('toolbar_align_left') },
    { id: 'center', icon: AlignCenter, title: t('toolbar_align_center') },
    { id: 'right', icon: AlignRight, title: t('toolbar_align_right') },
    { id: 'full', icon: Maximize2, title: t('image_align_full_width') },
];

function ImageMetaForm({ image, onSave, onCancel }) {
    const [alt, setAlt] = useState(image.alt ?? '');
    const [title, setTitle] = useState(image.title ?? '');
    const [caption, setCaption] = useState(image.caption ?? '');

    return (
        <div className="seo-block-image-meta-form">
            <ImageMetaFieldLabel htmlFor={`seo-block-panel-alt-${image.id}`}>{t('alt_text')}</ImageMetaFieldLabel>
            <ImageMetaTextInput
                id={`seo-block-panel-alt-${image.id}`}
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
            />

            <ImageMetaFieldLabel htmlFor={`seo-block-panel-title-${image.id}`}>{t('title')}</ImageMetaFieldLabel>
            <ImageMetaTextInput
                id={`seo-block-panel-title-${image.id}`}
                value={title}
                onChange={(e) => setTitle(e.target.value)}
            />

            <ImageMetaFieldLabel htmlFor={`seo-block-panel-caption-${image.id}`}>
                {t('caption')}
            </ImageMetaFieldLabel>
            <ImageMetaTextarea
                id={`seo-block-panel-caption-${image.id}`}
                value={caption}
                onChange={(e) => setCaption(e.target.value)}
            />

            <ImageMetaFormActions
                onCancel={onCancel}
                onApply={() =>
                    onSave({
                        ...image,
                        alt: alt.trim(),
                        title: title.trim(),
                        caption: caption.trim(),
                    })
                }
            />
        </div>
    );
}

export default function BlockImagesPanel({ images, onChange }) {
    const [editingId, setEditingId] = useState(null);

    if (!images?.length) {
        return null;
    }

    const updateImage = (id, patch) => {
        onChange(images.map((img) => (img.id === id ? { ...img, ...patch } : img)));
    };

    const removeImage = (id) => {
        onChange(images.filter((img) => img.id !== id));
        if (editingId === id) setEditingId(null);
    };

    return (
        <div className="seo-block-images-panel" onMouseDown={(e) => e.stopPropagation()}>
            <p className="seo-block-images-title">{`${t('image_block_label')} (${images.length})`}</p>
            <ul className="seo-block-images-list">
                {images.map((image) => (
                    <li key={image.id} className="seo-block-image-card">
                        <div className="seo-block-image-preview">
                            <img src={image.src} alt={image.alt || ''} />
                        </div>
                        <div className="seo-block-image-actions">
                            {ALIGN_OPTIONS.map(({ id, icon: Icon, title }) => (
                                <button
                                    key={id}
                                    type="button"
                                    className={`seo-image-toolbar-btn ${image.align === id ? 'is-active' : ''}`}
                                    title={title}
                                    onClick={() => updateImage(image.id, { align: id })}
                                >
                                    <Icon size={16} />
                                </button>
                            ))}
                            <button
                                type="button"
                                className="seo-image-toolbar-btn"
                                title="Alt, title, caption"
                                onClick={() => setEditingId(editingId === image.id ? null : image.id)}
                            >
                                <Pencil size={16} />
                            </button>
                            <button
                                type="button"
                                className="seo-image-toolbar-btn is-danger"
                                title={t('delete_image')}
                                onClick={() => removeImage(image.id)}
                            >
                                <Trash2 size={16} />
                            </button>
                        </div>
                        {editingId === image.id ? (
                            <ImageMetaForm
                                image={image}
                                onSave={(next) => {
                                    updateImage(image.id, next);
                                    setEditingId(null);
                                }}
                                onCancel={() => setEditingId(null)}
                            />
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}
