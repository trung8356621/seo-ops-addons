/**
 * Shared SEO panel loading coordinator.
 * One full-width bar (refcount) + optional domain fade of .fi-page.
 */

const BAR_ID = 'seo-panel-loading-bar';
const DOMAIN_CLASS = 'is-domain-context-loading';
const BAR_ACTIVE = 'is-active';

let barCount = 0;
let domainCount = 0;

function ensureBar() {
    let el = document.getElementById(BAR_ID);
    if (el instanceof HTMLElement) {
        return el;
    }

    const main = document.querySelector('.fi-main');
    if (!main) {
        return null;
    }

    el = document.createElement('div');
    el.id = BAR_ID;
    el.className = 'seo-panel-loading-bar';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<div class="seo-panel-loading-bar__run"></div>';
    main.insertBefore(el, main.firstChild);

    return el;
}

function sync() {
    const main = document.querySelector('.fi-main');
    if (main) {
        main.classList.toggle(DOMAIN_CLASS, domainCount > 0);
    }

    const bar = ensureBar();
    if (bar) {
        bar.classList.toggle(BAR_ACTIVE, barCount > 0);
    }
}

export function beginPanelBar() {
    barCount += 1;
    sync();
}

export function endPanelBar() {
    barCount = Math.max(0, barCount - 1);
    sync();
}

export function beginDomainLoading() {
    if (domainCount === 0) {
        domainCount = 1;
        beginPanelBar();

        return;
    }

    sync();
}

export function endDomainLoading() {
    if (domainCount === 0) {
        return;
    }

    domainCount = 0;
    endPanelBar();
}

export function bootPanelLoading() {
    ensureBar();
    sync();
}

export function exposePanelLoadingApi() {
    window.SeoPanelLoading = {
        beginBar: beginPanelBar,
        endBar: endPanelBar,
        beginDomain: beginDomainLoading,
        endDomain: endDomainLoading,
        boot: bootPanelLoading,
    };
}
