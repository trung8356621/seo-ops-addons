import React from 'react';
import { createRoot } from 'react-dom/client';
import SeedingWorkspace from './seeding/SeedingWorkspace';
import '../css/seeding-workspace.css';

/**
 * Standalone Seeding surface — feed workspace, no site/domain gate.
 */
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
            canMutate={props.canMutate !== false}
            bootstrap={props.bootstrap ?? null}
        />,
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
