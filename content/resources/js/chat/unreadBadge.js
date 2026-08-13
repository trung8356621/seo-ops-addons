/**
 * Lightweight global unread badge — NEVER calls /team/messages.
 * Visible ~30s, hidden ~60s, single-inflight.
 */
(function () {
    const ATTR = 'data-chat-unread-badge';
    let timer = null;
    let inflight = false;
    let lastCount = null;
    let url = '';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function targets() {
        return Array.from(document.querySelectorAll('[' + ATTR + ']'));
    }

    function apply(count) {
        lastCount = count;
        targets().forEach((el) => {
            const n = Math.max(0, Number(count) || 0);
            if (n <= 0) {
                el.hidden = true;
                el.textContent = '';
                return;
            }
            el.hidden = false;
            el.textContent = String(n > 99 ? '99+' : n);
        });

        // Filament nav item labeled Chat — append badge if data attribute present on li
        document.querySelectorAll('[data-chat-nav-item]').forEach((item) => {
            let badge = item.querySelector('[' + ATTR + ']');
            if (!badge) {
                badge = document.createElement('span');
                badge.setAttribute(ATTR, '1');
                badge.className = 'fi-badge ml-auto inline-flex items-center justify-center rounded-md bg-danger-600 px-1.5 text-xs font-medium text-white';
                item.appendChild(badge);
            }
            const n = Math.max(0, Number(count) || 0);
            badge.hidden = n <= 0;
            badge.textContent = n > 99 ? '99+' : String(n);
        });
    }

    async function refresh() {
        if (inflight || !url) return;
        // Skip message poll pages: only unread-count
        inflight = true;
        try {
            const res = await fetch(url, {
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            apply(data.unread ?? data.unread_count ?? 0);
        } catch (_) {
            // silent — keep lastCount
        } finally {
            inflight = false;
            schedule();
        }
    }

    function schedule() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
        const ms = document.hidden ? 60000 : 30000;
        timer = setTimeout(() => {
            if (document.hidden) {
                schedule();
                return;
            }
            refresh();
        }, ms);
    }

    function boot() {
        const cfg = document.getElementById('seo-chat-unread-config');
        if (!cfg) return;
        try {
            const props = JSON.parse(cfg.getAttribute('data-props') || '{}');
            url = props.unreadUrl || '';
        } catch (_) {
            url = '';
        }
        if (!url) return;

        // Mark Chat nav links
        document.querySelectorAll('a').forEach((a) => {
            const href = a.getAttribute('href') || '';
            if (/\/seo\/[^/]+\/chat(?:\?|$)/.test(href) || /\/chat(?:\?|$)/.test(href)) {
                const item = a.closest('.fi-sidebar-item') || a.parentElement;
                if (item) item.setAttribute('data-chat-nav-item', '1');
            }
        });

        refresh();
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                refresh();
            } else {
                schedule();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    document.addEventListener('livewire:navigated', boot);
})();
