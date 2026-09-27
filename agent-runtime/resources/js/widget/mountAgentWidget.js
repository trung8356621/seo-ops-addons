import { createRoot } from 'react-dom/client';
import { createElement } from 'react';
import { AgentWidget } from './AgentWidget.jsx';

/**
 * Mounts the canonical Agent React widget into any container element.
 *
 * @param {HTMLElement} container
 * @param {Object} options
 * @param {Object} [options.hostContext] - Generic host configuration { appKey, scope, capabilities }
 * @param {Object} [options.endpoints] - { projectsUrl, turnUrl, copyUrl }
 * @param {string} [options.csrf] - CSRF token
 * @param {'standalone'|'embedded'|'drawer'} [options.mode]
 * @param {string} [options.className]
 * @returns {{ unmount: () => void }}
 */
export function mountAgentWidget(container, options = {}) {
    if (!container) {
        throw new Error('mountAgentWidget requires a valid container DOM element.');
    }

    const root = createRoot(container);
    root.render(createElement(AgentWidget, options));

    return {
        unmount() {
            root.unmount();
        },
    };
}

export default mountAgentWidget;
