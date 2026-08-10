import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';

import { createPortal } from 'react-dom';

import { getArticleImageSelection } from '../utils/articleImageExtension';
import { applyWordPressImageSize, detectWordPressImageSize } from '@wordpress-addon/utils/wordpressImageSize.js';
import { isLocalSeoMediaSrc, resolveFullWordPressImageUrl } from '@wordpress-addon/utils/wordpressImageUrl.js';
import { executeEditorCommand } from '@content-addon/utils/editorCommands/index.js';
import { t } from '@content-addon/utils/i18n.js';

import ImageMetaEditForm from './imageMeta/ImageMetaEditForm';



export default function ImageMetaEditPanel({ editor, anchorRect, onClose }) {

    const panelRef = useRef(null);

    const [position, setPosition] = useState({ top: 0, left: 0 });

    const [alt, setAlt] = useState('');

    const [title, setTitle] = useState('');

    const [caption, setCaption] = useState('');

    const [align, setAlign] = useState('none');

    const [size, setSize] = useState('full');



    const selection = getArticleImageSelection(editor);



    useEffect(() => {

        if (!selection) return;

        setAlt(selection.attrs.alt ?? '');

        setTitle(selection.attrs.title ?? '');

        setCaption(selection.attrs.caption ?? '');

        setAlign(selection.attrs.align ?? 'none');

        setSize(selection.attrs.size ?? detectWordPressImageSize(selection.attrs.src));

    }, [selection?.attrs?.src, selection?.attrs?.alt, selection?.attrs?.title, selection?.attrs?.caption, selection?.attrs?.align, selection?.attrs?.size]);



    useLayoutEffect(() => {

        if (!anchorRect || !panelRef.current) return;



        const width = panelRef.current.offsetWidth;

        const left = anchorRect.left + anchorRect.width / 2 - width / 2;

        const top = anchorRect.bottom + 12;



        setPosition({

            top: Math.min(top, window.innerHeight - panelRef.current.offsetHeight - 8),

            left: Math.max(8, Math.min(left, window.innerWidth - width - 8)),

        });

    }, [anchorRect]);



    useEffect(() => {

        const onDocMouseDown = (e) => {

            if (panelRef.current?.contains(e.target)) return;

            onClose();

        };

        document.addEventListener('mousedown', onDocMouseDown);

        return () => document.removeEventListener('mousedown', onDocMouseDown);

    }, [onClose]);



    const applyMeta = () => {

        const src = String(selection?.attrs?.src ?? '');

        const wpSrc = isLocalSeoMediaSrc(src) ? '' : resolveFullWordPressImageUrl(src);

        const sized = applyWordPressImageSize({ src, wpSrc }, size);



        executeEditorCommand('update_image_attributes', {
            editor,
            attrs: {
                src: sized.src,
                alt: alt.trim(),
                title: title.trim(),
                caption: caption.trim(),
                align: align || 'none',
                size,
            },
        }, { notifyOnFailure: true });

        onClose();

    };



    if (!anchorRect || !selection) return null;



    const panel = (

        <div

            ref={panelRef}

            className="seo-image-meta-panel"

            style={{ top: `${position.top}px`, left: `${position.left}px` }}

            onMouseDown={(e) => e.stopPropagation()}

        >

            <ImageMetaEditForm

                idPrefix="seo-img"

                src={selection.attrs.src ?? ''}

                size={size}

                onSizeChange={setSize}

                align={align}

                onAlignChange={setAlign}

                alt={alt}

                onAltChange={setAlt}

                title={title}

                onTitleChange={setTitle}

                caption={caption}

                onCaptionChange={setCaption}

                onCancel={onClose}

                onApply={applyMeta}

            />

        </div>

    );



    return createPortal(panel, document.body);

}

