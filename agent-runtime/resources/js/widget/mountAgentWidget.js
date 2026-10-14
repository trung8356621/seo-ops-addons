import { createRoot } from 'react-dom/client';
import { Component, createElement } from 'react';
import { AgentWidget } from './AgentWidget.jsx';

class AgentMountBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { failed: false };
    }

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        if (!this.state.failed) {
            return this.props.children;
        }

        return createElement('div', { className: 'agent-mount-error', role: 'alert' },
            createElement('p', null, 'Không thể khởi tạo Agent.'),
            createElement('button', {
                type: 'button',
                onClick: () => this.setState({ failed: false }),
            }, 'Thử lại'),
        );
    }
}

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
    root.render(createElement(AgentMountBoundary, null, createElement(AgentWidget, options)));
    container.dataset.agentMounted = '1';

    return {
        unmount() {
            root.unmount();
            if (container.isConnected) {
                delete container.dataset.agentMounted;
            }
        },
    };
}

export default mountAgentWidget;
