import React from 'react';
import { createRoot } from 'react-dom/client';
import AutomationWorkflowViewerApp from './components/automation-workflow-viewer/AutomationWorkflowViewerApp';
import '../css/automation-workflow-viewer.css';

function parseProps(el) {
    try {
        return JSON.parse(el.dataset.props ?? '{}');
    } catch (error) {
        console.warn('Invalid automation-workflow-viewer props JSON', error);
        return {};
    }
}

function mount(el) {
    if (!el) {
        return;
    }

    // Remount when Livewire replaces node with new wire:key / props.
    if (el._awvRoot && el.dataset.awvPropsSig === el.dataset.props) {
        return;
    }

    if (typeof el._awvCleanup === 'function') {
        el._awvCleanup();
    }

    el.dataset.awvMounted = '1';
    el.dataset.awvPropsSig = el.dataset.props ?? '';
    const props = parseProps(el);
    const root = createRoot(el);
    el._awvRoot = root;

    const render = (nextProps) => {
        root.render(
            <AutomationWorkflowViewerApp
                workflow={nextProps.workflow ?? null}
                executionsUrl={nextProps.executions_url ?? ''}
                operationsUrl={nextProps.operations_url ?? ''}
                onOpenComponents={() => {
                    window.dispatchEvent(new CustomEvent('automation-flows:open-components'));
                }}
            />,
        );
    };

    render(props);

    const onReload = (event) => {
        const detail = event.detail ?? {};
        render({
            ...props,
            ...detail,
            workflow: detail.workflow ?? props.workflow ?? null,
        });
    };

    window.addEventListener('automation-workflow-viewer:reload', onReload);
    el._awvCleanup = () => {
        window.removeEventListener('automation-workflow-viewer:reload', onReload);
        try {
            root.unmount();
        } catch (_) {
            // ignore
        }
        delete el._awvRoot;
        delete el.dataset.awvMounted;
        delete el.dataset.awvPropsSig;
    };
}

function boot() {
    document.querySelectorAll('[data-automation-workflow-viewer]').forEach((el) => mount(el));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', () => {
    boot();
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', () => {
            queueMicrotask(boot);
        });
        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(() => {
                queueMicrotask(boot);
            });
        });
    }
});
