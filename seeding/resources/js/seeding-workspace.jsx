import React from 'react';
import { createRoot } from 'react-dom/client';
import SeedingWorkspace from './seeding/SeedingWorkspace';
import '../css/seeding-workspace.css';

function boot() {
    const el = document.getElementById('seeding-workspace-root');
    if (!el || el.dataset.booted === '1') {
        return;
    }

    let props = {};
    try {
        props = JSON.parse(el.dataset.props || '{}');
    } catch (error) {
        console.warn('Invalid seeding-workspace props', error);
    }

    el.dataset.booted = '1';
    createRoot(el).render(
        <SeedingWorkspace
            siteId={props.siteId ?? null}
            apiBase={props.apiBase ?? '/api/seo/seeding-topics'}
            canMutate={props.canMutate !== false}
        />,
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

document.addEventListener('livewire:navigated', () => {
    const el = document.getElementById('seeding-workspace-root');
    if (el) {
        delete el.dataset.booted;
    }
    boot();
});
