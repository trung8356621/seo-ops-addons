import { mountAgentWidget } from '../widget/mountAgentWidget.js';
import { AgentWidget } from '../widget/AgentWidget.jsx';
import { normalizeHostContext } from '../host/hostContext.js';
import { buildProjectItems, scopePayload, switchProject } from '../projects/projectCatalog.js';

export { AgentWidget, mountAgentWidget, normalizeHostContext, buildProjectItems, scopePayload, switchProject };
export const AgentApp = AgentWidget;

function readStandaloneRoot() {
    return document.getElementById('agent-runtime-root');
}

function parseHostContext(element) {
    if (!element) {
        return normalizeHostContext({ appKey: 'standalone' });
    }
    try {
        const raw = element.dataset.hostContext ? JSON.parse(element.dataset.hostContext) : {};
        return normalizeHostContext(raw);
    } catch {
        return normalizeHostContext({ appKey: 'standalone' });
    }
}

// Standalone harness auto-mount on internal Filament harness page
const standaloneRoot = readStandaloneRoot();
if (standaloneRoot) {
    const hostContext = parseHostContext(standaloneRoot);
    mountAgentWidget(standaloneRoot, {
        hostContext,
        projectsUrl: standaloneRoot.dataset.projectsUrl,
        turnUrl: standaloneRoot.dataset.turnUrl,
        threadsUrl: standaloneRoot.dataset.threadsUrl || '/agent-runtime/threads',
        copyUrl: standaloneRoot.dataset.copyUrl,
        modelDebugApplyUrl: standaloneRoot.dataset.modelDebugApplyUrl || '/agent-runtime/model-debug/apply',
        csrf: standaloneRoot.dataset.csrf || '',
        mode: 'standalone',
    });
}

// Canonical drawer mounting
let drawerHandle = null;

export function mountDrawer() {
    if (typeof document === 'undefined') {
        return null;
    }
    const drawerRoot = document.getElementById('agent-drawer-root');
    if (!drawerRoot) {
        return null;
    }
    if (drawerHandle) {
        return drawerHandle;
    }
    const hostContext = parseHostContext(drawerRoot);
    drawerHandle = mountAgentWidget(drawerRoot, {
        hostContext,
        projectsUrl: drawerRoot.dataset.projectsUrl,
        turnUrl: drawerRoot.dataset.turnUrl,
        threadsUrl: drawerRoot.dataset.threadsUrl || '/agent-runtime/threads',
        copyUrl: drawerRoot.dataset.copyUrl,
        modelDebugApplyUrl: drawerRoot.dataset.modelDebugApplyUrl || '/agent-runtime/model-debug/apply',
        csrf: drawerRoot.dataset.csrf || '',
        mode: 'drawer',
        onClose: () => {
            window.dispatchEvent(new CustomEvent('agent-drawer:close'));
        },
    });
    return drawerHandle;
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            mountDrawer();
        });
    } else {
        mountDrawer();
    }
}

// Global browser window registry for embeddable hosts (drawers, WordPress, modals)
if (typeof window !== 'undefined') {
    window.AgentRuntime = {
        mount: mountAgentWidget,
        mountDrawer,
        AgentWidget,
        normalizeHostContext,
    };
}
