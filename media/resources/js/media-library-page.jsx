import React from 'react';
import '../css/media-library.css';
import { createRoot } from 'react-dom/client';
import MediaLibraryTools from './components/MediaLibraryTools';
import WordPressMediaRenameModal from '@wordpress-addon/components/WordPressMediaRenameModal.jsx';

function readBootstrap() {
    const el = document.getElementById('seo-media-library-react-root');
    if (!el) {
        return { sites: [], siteId: null, activeTab: 'original' };
    }

    let sites = [];
    try {
        sites = JSON.parse(el.dataset.sites ?? '[]');
    } catch {
        sites = [];
    }

    return {
        sites,
        siteId: el.dataset.siteId ? Number(el.dataset.siteId) : null,
        activeTab: el.dataset.activeTab ?? 'original',
    };
}

function ensureRenameModalRoot() {
    let el = document.getElementById('seo-wp-media-rename-root');
    if (!el) {
        el = document.createElement('div');
        el.id = 'seo-wp-media-rename-root';
        document.body.appendChild(el);
    }

    return el;
}

function mount() {
    const toolsEl = document.getElementById('seo-media-library-react-root');
    if (toolsEl) {
        const props = readBootstrap();
        let root = toolsEl.__seoMediaLibraryRoot;
        if (!root) {
            root = createRoot(toolsEl);
            toolsEl.__seoMediaLibraryRoot = root;
        }
        root.render(<MediaLibraryTools sites={props.sites} siteId={props.siteId} />);
    }

    const renameEl = ensureRenameModalRoot();
    let renameRoot = renameEl.__seoWpRenameRoot;
    if (!renameRoot) {
        renameRoot = createRoot(renameEl);
        renameEl.__seoWpRenameRoot = renameRoot;
    }
    renameRoot.render(<WordPressMediaRenameModal />);
}

mount();

document.addEventListener('livewire:navigated', mount);

if (typeof Livewire !== 'undefined') {
    Livewire.hook('morph.updated', () => {
        window.requestAnimationFrame(mount);
    });
}
