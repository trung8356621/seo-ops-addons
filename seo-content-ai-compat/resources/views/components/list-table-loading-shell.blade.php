@props([
    'preset' => '',
    'targets' => '',
])

{{--
    Overlay loading copied from /article (article-list-table-shell):
    keep layout, dim stale data, block pointer events, Filament spinner.
    JS allowlist = data-list-loading-preset + data-list-loading-targets.
--}}
<div
    {{ $attributes->class(['list-table-shell'])->merge([
        'data-list-table-shell' => '1',
        'data-list-loading-preset' => $preset,
        'data-list-loading-targets' => $targets,
    ]) }}
>
    <div class="list-table-shell__overlay" role="status" aria-live="polite">
        <x-filament::loading-indicator class="h-8 w-8" />
        <span class="list-table-shell__overlay-text">
            {{ __('seo-content-ai::filament.article_list.table_loading') }}
        </span>
    </div>
    {{ $slot }}
</div>

@once
    <style>
        .list-table-shell {
            position: relative;
        }

        .list-table-shell.is-table-loading > :not(.list-table-shell__overlay) {
            opacity: 0.45;
            pointer-events: none;
            transition: opacity 0.12s ease;
        }

        .list-table-shell__overlay {
            display: none;
            position: absolute;
            inset: 0;
            z-index: 25;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: rgb(255 255 255 / 72%);
            pointer-events: none;
        }

        .list-table-shell.is-table-loading .list-table-shell__overlay {
            display: flex;
        }

        .dark .list-table-shell__overlay {
            background: rgb(17 24 39 / 72%);
        }

        .list-table-shell__overlay-text {
            font-size: 0.8125rem;
            font-weight: 600;
            color: rgb(55 65 81);
        }

        .dark .list-table-shell__overlay-text {
            color: rgb(209 213 219);
        }
    </style>
    <script>
        (function bootListTableLoading() {
            if (window.__LIST_TABLE_LOADING_BOOTED__) {
                return;
            }
            window.__LIST_TABLE_LOADING_BOOTED__ = true;

            const PRESETS = {
                'filament-table': [
                    'gotoPage', 'previousPage', 'nextPage', 'resetPage', 'setPage',
                    'sortTable', 'resetTableFiltersForm', 'removeTableFilter', 'removeTableFilters',
                    'resetTableFilters', 'applyTableFilters', 'resetTable',
                    'changeTableRecordsPerPage', 'updatedTableSearch', 'updatedTableFilters',
                    'updatedTableRecordsPerPage', 'tableSearch', 'tableFilters', 'tableSort',
                    'tableSortColumn', 'tableSortDirection', 'tableRecordsPerPage',
                    'tableColumnSearches', 'paginators',
                    'onDomainContextChanged',
                ],
                'livewire-page': [
                    'gotoPage', 'previousPage', 'nextPage', 'resetPage', 'setPage',
                    'paginators', 'updatedPage', 'updatedPaginators', 'onDomainContextChanged',
                ],
            };

            const pendingByShell = new WeakMap();

            function splitList(value) {
                return String(value || '')
                    .split(',')
                    .map((item) => item.trim())
                    .filter(Boolean);
            }

            function targetSet(el) {
                const names = new Set(PRESETS[el.getAttribute('data-list-loading-preset')] || []);
                splitList(el.getAttribute('data-list-loading-targets')).forEach((name) => names.add(name));

                return names;
            }

            function rootName(key) {
                const text = String(key || '');
                const dot = text.indexOf('.');

                return dot === -1 ? text : text.slice(0, dot);
            }

            function commitNames(commit) {
                const names = [];
                const calls = Array.isArray(commit?.calls) ? commit.calls : [];
                calls.forEach((call) => {
                    const method = String(call?.method ?? '');
                    if (method === '$set' || method === 'set') {
                        names.push(String(call?.params?.[0] ?? ''));
                    } else if (method !== '') {
                        names.push(method);
                    }
                });
                const updates = commit?.updates && typeof commit.updates === 'object' ? commit.updates : {};
                Object.keys(updates).forEach((key) => names.push(key));

                return names;
            }

            function matchesTargets(commit, allowed) {
                if (allowed.size === 0) {
                    return false;
                }

                return commitNames(commit).some((name) => {
                    if (name === '' || /^poll/i.test(name)) {
                        return false;
                    }

                    return allowed.has(name) || allowed.has(rootName(name));
                });
            }

            function shellsFor(component) {
                const root = component?.el;
                if (!root || typeof root.querySelectorAll !== 'function') {
                    return [];
                }

                if (root.matches?.('[data-list-table-shell]')) {
                    return [root];
                }

                return Array.from(root.querySelectorAll('[data-list-table-shell]'));
            }

            function show(el) {
                pendingByShell.set(el, (pendingByShell.get(el) || 0) + 1);
                el.classList.add('is-table-loading');
                el.setAttribute('aria-busy', 'true');
                window.SeoPanelLoading?.beginBar?.();
            }

            function hide(el) {
                const next = Math.max(0, (pendingByShell.get(el) || 0) - 1);
                pendingByShell.set(el, next);
                if (next !== 0) {
                    return;
                }
                el.classList.remove('is-table-loading');
                el.removeAttribute('aria-busy');
                window.SeoPanelLoading?.endBar?.();
            }

            function register() {
                if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
                    return;
                }

                Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                    const shells = shellsFor(component).filter((el) => matchesTargets(commit, targetSet(el)));
                    if (shells.length === 0) {
                        return;
                    }

                    shells.forEach(show);
                    succeed(() => shells.forEach(hide));
                    fail(() => shells.forEach(hide));
                });
            }

            if (typeof Livewire !== 'undefined') {
                register();
            } else {
                document.addEventListener('livewire:init', register, { once: true });
            }
        })();
    </script>
@endonce
