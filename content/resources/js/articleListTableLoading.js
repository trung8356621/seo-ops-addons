/**
 * Overlay loading khi bảng Articles (Filament table) gọi Livewire — filter, search, sort, pagination.
 */
export function initArticleListTableLoading() {
    const pageRoot = document.querySelector('.fi-resource-list-records-page');
    if (!pageRoot) {
        return;
    }

    let pending = 0;

    const shell = () => document.querySelector('.article-list-table-shell');

    const show = () => {
        pending += 1;
        const el = shell();
        if (el) {
            el.classList.add('is-table-loading');
            el.setAttribute('aria-busy', 'true');
        }
    };

    const hide = () => {
        pending = Math.max(0, pending - 1);
        if (pending !== 0) {
            return;
        }

        const el = shell();
        if (el) {
            el.classList.remove('is-table-loading');
            el.removeAttribute('aria-busy');
        }
    };

    let registered = false;

    const register = () => {
        if (registered || typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }

        registered = true;

        Livewire.hook('commit', ({ component, succeed, fail }) => {
            if (!component?.el?.closest?.('.fi-resource-list-records-page')) {
                return;
            }

            show();
            succeed(() => hide());
            fail(() => hide());
        });
    };

    if (typeof Livewire !== 'undefined') {
        register();
    } else {
        document.addEventListener('livewire:init', register, { once: true });
    }
}
