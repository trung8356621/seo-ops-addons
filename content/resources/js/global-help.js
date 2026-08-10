import '../css/global-help.css';
import { registerGlobalHelpStore, installGlobalHelpWindowBridge } from './help/globalHelpStore';

let helpBooted = false;

/**
 * Register Alpine.store('help') for Filament SEO Global Help.
 * Safe to call after Alpine already started (module may load deferred).
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
function bootGlobalHelp(Alpine) {
    if (!Alpine || helpBooted) {
        return;
    }
    helpBooted = true;

    const store = registerGlobalHelpStore(Alpine);
    installGlobalHelpWindowBridge(store);
    store.syncContextFromLocation();
}

document.addEventListener('alpine:init', () => {
    bootGlobalHelp(window.Alpine);
});

if (window.Alpine) {
    bootGlobalHelp(window.Alpine);
}
