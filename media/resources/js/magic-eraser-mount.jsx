import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import '../css/magic-eraser.css';
import { createRoot } from 'react-dom/client';
import MagicEraserApp from './components/MagicEraserApp';
import { normalizeSeoMediaUrl } from './utils/seoMediaApi';

function MagicEraserMount() {
    const [open, setOpen] = useState(false);
    const [imageUrl, setImageUrl] = useState('');
    const [imageId, setImageId] = useState(null);

    const openEditor = (detail) => {
        const id = Number(detail?.imageId);
        const url = detail?.imageUrl;
        if (!url || !id || Number.isNaN(id)) {
            return;
        }
        setImageUrl(url);
        setImageId(id);
        setOpen(true);
    };

    useEffect(() => {
        const onWindowOpen = (e) => openEditor(e.detail ?? {});

        window.addEventListener('seo-open-magic-eraser', onWindowOpen);

        return () => window.removeEventListener('seo-open-magic-eraser', onWindowOpen);
    }, []);

    useEffect(() => {
        if (typeof Livewire === 'undefined') {
            return undefined;
        }

        const onLivewireOpen = (payload) => {
            const detail = Array.isArray(payload) ? payload[0] : payload;
            openEditor(detail ?? {});
        };

        Livewire.on('seo-open-magic-eraser-browser', onLivewireOpen);

        return () => {
            Livewire.off?.('seo-open-magic-eraser-browser', onLivewireOpen);
        };
    }, []);

    if (!open || !imageId) {
        return null;
    }

    return createPortal(
        <MagicEraserApp
            imageUrl={imageUrl}
            imageId={imageId}
            seoMediaId={imageId}
            onClose={() => {
                setOpen(false);
                setImageUrl('');
                setImageId(null);
            }}
            onSave={(url) => {
                const normalized = normalizeSeoMediaUrl(url) || url;
                if (typeof Livewire !== 'undefined') {
                    Livewire.dispatch('seo-magic-eraser-saved', {
                        url: normalized,
                        imageId,
                    });
                    Livewire.dispatch('seo-media-library-refresh');
                }
            }}
        />,
        document.body,
    );
}

function mount() {
    const el = document.getElementById('seo-magic-eraser-root');
    if (!el) {
        return;
    }

    let root = el.__seoMagicEraserRoot;
    if (!root) {
        root = createRoot(el);
        el.__seoMagicEraserRoot = root;
    }

    root.render(<MagicEraserMount />);
}

mount();

document.addEventListener('livewire:navigated', mount);

if (typeof Livewire !== 'undefined') {
    Livewire.hook('morph.updated', () => {
        window.requestAnimationFrame(mount);
    });
}
