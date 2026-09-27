import { mountAgentWidget } from '../widget/mountAgentWidget.js';
import { AgentWidget } from '../widget/AgentWidget.jsx';
import { normalizeHostContext } from '../host/hostContext.js';
import { buildProjectItems, scopePayload, switchProject } from '../projects/projectCatalog.js';

export { AgentWidget, mountAgentWidget, normalizeHostContext, buildProjectItems, scopePayload, switchProject };
export const AgentApp = AgentWidget;

function readRoot() {
    return document.getElementById('agent-runtime-root');
}

function parseHostContext(root) {
    if (!root) {
        return normalizeHostContext({ appKey: 'standalone' });
    }
    try {
        const raw = root.dataset.hostContext ? JSON.parse(root.dataset.hostContext) : {};
        return normalizeHostContext(raw);
    } catch {
        return normalizeHostContext({ appKey: 'standalone' });
    }
}

// Standalone harness auto-mount on internal Filament harness page
const root = readRoot();
if (root) {
    const hostContext = parseHostContext(root);
    mountAgentWidget(root, {
        hostContext,
        projectsUrl: root.dataset.projectsUrl,
        turnUrl: root.dataset.turnUrl,
        copyUrl: root.dataset.copyUrl,
        csrf: root.dataset.csrf || '',
        mode: 'standalone',
    });
}

// Global browser window registry for embeddable hosts (drawers, WordPress, modals)
if (typeof window !== 'undefined') {
    window.AgentRuntime = {
        mount: mountAgentWidget,
        AgentWidget,
        normalizeHostContext,
    };
}
