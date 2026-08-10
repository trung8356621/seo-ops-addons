import React, { useCallback, useEffect, useRef, useState } from 'react';
import { t } from '../utils/i18n';

const MIN_H = 96;
const MAX_H = 720;
const DEFAULT_H = 180;
const STORAGE_PREFIX = 'seo-block-editor-h:';

function readStoredHeight(blockId) {
    try {
        const raw = sessionStorage.getItem(`${STORAGE_PREFIX}${blockId}`);
        const n = parseInt(raw, 10);
        if (Number.isFinite(n) && n >= MIN_H && n <= MAX_H) {
            return n;
        }
    } catch {
        /* ignore */
    }
    return DEFAULT_H;
}

export function useBlockEditorHeight(blockId) {
    const [minHeight, setMinHeight] = useState(() => readStoredHeight(blockId));

    useEffect(() => {
        setMinHeight(readStoredHeight(blockId));
    }, [blockId]);

    const persistHeight = useCallback(
        (height) => {
            try {
                sessionStorage.setItem(`${STORAGE_PREFIX}${blockId}`, String(height));
            } catch {
                /* ignore */
            }
        },
        [blockId],
    );

    return { minHeight, setMinHeight, persistHeight, minH: MIN_H, maxH: MAX_H };
}

/** Resize handle for editor block. */
export default function BlockEditorResizeHandle({ minHeight, onMinHeightChange, onResizeEnd, minH, maxH }) {
    const draggingRef = useRef(false);

    const onPointerDown = useCallback(
        (event) => {
            event.preventDefault();
            event.stopPropagation();

            const startY = event.clientY;
            const startH = minHeight;
            draggingRef.current = true;

            const onMove = (ev) => {
                const next = Math.min(maxH, Math.max(minH, startH + (ev.clientY - startY)));
                onMinHeightChange(next);
            };

            const onUp = (ev) => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                draggingRef.current = false;
                const finalH = Math.min(maxH, Math.max(minH, startH + (ev.clientY - startY)));
                onMinHeightChange(finalH);
                onResizeEnd?.(finalH);
            };

            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp);
        },
        [minHeight, onMinHeightChange, onResizeEnd, minH, maxH],
    );

    return (
        <div
            className="seo-block-editor-resize"
            role="separator"
            aria-orientation="vertical"
            aria-label={t('editor_resize_aria')}
            title={t('editor_resize_title')}
            onMouseDown={(e) => e.preventDefault()}
            onPointerDown={onPointerDown}
        />
    );
}
