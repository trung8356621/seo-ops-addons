import React from 'react';
import '../css/watermark-editor.css';
import { createRoot } from 'react-dom/client';
import WatermarkEditorApp from './components/WatermarkEditorApp';

function readBootstrap() {
    const el = document.getElementById('seo-watermark-editor-root');
    if (!el) {
        return {
            siteId: null,
            siteDomain: '',
            imageUrl: '',
            imageId: null,
            backUrl: '',
            initialConfig: {},
            mediaSamples: [],
        };
    }

    let initialConfig = {};
    let mediaSamples = [];

    try {
        initialConfig = JSON.parse(el.dataset.initialConfig ?? '{}');
    } catch {
        initialConfig = {};
    }

    try {
        mediaSamples = JSON.parse(el.dataset.mediaSamples ?? '[]');
    } catch {
        mediaSamples = [];
    }

    return {
        siteId: el.dataset.siteId ? Number(el.dataset.siteId) : null,
        siteDomain: el.dataset.siteDomain ?? '',
        imageUrl: el.dataset.imageUrl ?? '',
        imageId: el.dataset.imageId ? Number(el.dataset.imageId) : null,
        backUrl: el.dataset.backUrl ?? '',
        initialConfig,
        mediaSamples,
    };
}

function mount() {
    const el = document.getElementById('seo-watermark-editor-root');
    if (!el) {
        return;
    }

    const props = readBootstrap();
    let root = el.__seoWatermarkEditorRoot;

    if (!root) {
        root = createRoot(el);
        el.__seoWatermarkEditorRoot = root;
    }

    root.render(
        <WatermarkEditorApp
            initialImageUrl={props.imageUrl}
            imageId={props.imageId}
            siteId={props.siteId}
            siteDomain={props.siteDomain}
            backUrl={props.backUrl}
            initialConfig={props.initialConfig}
            mediaSamples={props.mediaSamples}
        />,
    );
}

mount();

document.addEventListener('livewire:navigated', mount);

if (typeof Livewire !== 'undefined') {
    Livewire.hook('morph.updated', () => {
        window.requestAnimationFrame(mount);
    });
}
