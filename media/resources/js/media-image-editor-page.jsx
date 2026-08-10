import React from 'react';
import '../css/magic-eraser.css';
import '../css/image-splitter.css';
import { createRoot } from 'react-dom/client';
import MagicEraserApp from './components/MagicEraserApp';
import { MEDIA_EDITOR_TAB_ERASER, MEDIA_EDITOR_TAB_SPLITTER } from './components/MediaEditorTabBar';

function parseIntOrNull(value) {
    if (!value) return null;
    const n = Number.parseInt(String(value), 10);
    return Number.isFinite(n) && n > 0 ? n : null;
}

function readBootstrap() {
    const el = document.getElementById('seo-media-image-editor-root');
    if (!el) {
        return {
            imageUrl: '',
            imageId: null,
            wpAttachmentId: 0,
            pendingWpSync: false,
            libraryUrl: '/seo/media-library',
            siteId: null,
            articleId: null,
            seoMediaId: null,
            slug: '',
            initialTab: MEDIA_EDITOR_TAB_ERASER,
            canDeleteOriginal: true,
        };
    }

    const imageId = el.dataset.imageId ? Number(el.dataset.imageId) : null;
    const tabRaw = (el.dataset.initialTab ?? '').trim().toLowerCase();
    const initialTab = tabRaw === 'splitter' ? MEDIA_EDITOR_TAB_SPLITTER : MEDIA_EDITOR_TAB_ERASER;

    return {
        imageUrl: el.dataset.imageUrl ?? '',
        imageId,
        wpAttachmentId: Number(el.dataset.wpAttachmentId ?? 0),
        pendingWpSync: el.dataset.pendingWpSync === '1',
        libraryUrl: el.dataset.libraryUrl ?? '/seo/media-library',
        siteId: parseIntOrNull(el.dataset.siteId),
        articleId: parseIntOrNull(el.dataset.articleId),
        seoMediaId: parseIntOrNull(el.dataset.seoMediaId) ?? imageId,
        slug: el.dataset.slug ?? '',
        initialTab,
        canDeleteOriginal: el.dataset.canDeleteOriginal !== '0',
    };
}

function notifyOpener(payload) {
    if (!window.opener || window.opener.closed) {
        return;
    }

    window.opener.postMessage(
        {
            type: 'seo-magic-eraser-saved',
            ...payload,
        },
        window.location.origin,
    );
}

function mount() {
    const el = document.getElementById('seo-media-image-editor-root');
    if (!el) {
        return;
    }

    const props = readBootstrap();

    let root = el.__seoMediaImageEditorRoot;
    if (!root) {
        root = createRoot(el);
        el.__seoMediaImageEditorRoot = root;
    }

    root.render(
        <MagicEraserApp
            standalone
            initialTab={props.initialTab}
            imageUrl={props.imageUrl}
            imageId={props.imageId}
            siteId={props.siteId}
            articleId={props.articleId}
            seoMediaId={props.seoMediaId}
            wpAttachmentId={props.wpAttachmentId}
            slug={props.slug}
            canDeleteOriginal={props.canDeleteOriginal}
            onSave={(url) => {
                const isWpStaging = props.wpAttachmentId > 0;
                notifyOpener({
                    url,
                    imageId: props.imageId,
                    pendingWpSync: isWpStaging,
                });
                window.close();
            }}
            onClose={() => {
                window.close();
            }}
        />,
    );
}

mount();
