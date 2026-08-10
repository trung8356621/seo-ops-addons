import React, { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import ImageWatermarkEditor from './ImageWatermarkEditor';
import { fetchWatermarkSettings } from '../utils/watermarkApi';

export default function MediaLibraryTools({ sites = [], siteId = null }) {
    const [editorState, setEditorState] = useState(null);
    const [settingsCache, setSettingsCache] = useState(null);

    const openEditor = useCallback((detail) => {
        setEditorState({
            imageUrl: detail.imageUrl,
            imageId: detail.imageId ?? null,
            siteId: detail.siteId ?? siteId,
        });
    }, [siteId]);

    useEffect(() => {
        const onOpen = (event) => openEditor(event.detail ?? {});
        window.addEventListener('seo-open-watermark-editor', onOpen);

        return () => window.removeEventListener('seo-open-watermark-editor', onOpen);
    }, [openEditor]);

    useEffect(() => {
        if (!siteId) {
            setSettingsCache(null);
            return;
        }

        fetchWatermarkSettings(siteId)
            .then((settings) => setSettingsCache(settings))
            .catch(() => setSettingsCache(null));
    }, [siteId]);

    const handleSaveSuccess = () => {
        if (typeof Livewire !== 'undefined') {
            Livewire.dispatch('seo-media-library-refresh');
        }
        window.location.reload();
    };

    if (!editorState) {
        return null;
    }

    return createPortal(
        <ImageWatermarkEditor
            imageUrl={editorState.imageUrl}
            imageId={editorState.imageId}
            siteId={editorState.siteId}
            initialSettings={settingsCache}
            onClose={() => setEditorState(null)}
            onSaveSuccess={handleSaveSuccess}
        />,
        document.body,
    );
}
