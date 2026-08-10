import React from 'react';
import { createRoot } from 'react-dom/client';
import AutomationWorkflowBuilderApp from './components/automation-workflow/AutomationWorkflowBuilderApp';
import '../css/automation-workflow-builder.css';
import '../../../content/resources/css/seo-select.css';

const rootElement = document.getElementById('automation-workflow-builder');

if (rootElement) {
    let props = {};

    try {
        const raw = rootElement.dataset.props ?? '{}';
        props = JSON.parse(raw);
    } catch (error) {
        console.warn('Invalid automation-workflow-builder props JSON', error);
    }

    createRoot(rootElement).render(
        <AutomationWorkflowBuilderApp
            initialGraph={props.graph ?? { nodes: [], edges: [] }}
            rule={props.rule ?? {}}
            registry={props.registry ?? { actions: [], events: [] }}
            permissions={props.permissions ?? {}}
            draftRevision={props.draft_revision ?? 0}
            backUrl={props.back_url ?? ''}
            backLabel={props.back_label ?? 'Back'}
        />,
    );
}
