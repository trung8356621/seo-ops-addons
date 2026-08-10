<div
    id="global-help-modal"
    class="global-help-modal"
    data-help-modal
    data-help-modal-host
    x-data="{
        titleId: 'global-help-modal-title',
        init() {
            const onKeyDown = (event) => {
                const help = window.Alpine?.store?.('help');
                if (!help?.isOpen) {
                    return;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    help.close();
                    return;
                }
                if (event.key !== 'Tab') {
                    return;
                }
                const dialog = this.$refs.dialog;
                if (!(dialog instanceof HTMLElement)) {
                    return;
                }
                const focusables = dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex=\'-1\'])');
                const list = [...focusables].filter((node) => node instanceof HTMLElement && !node.hasAttribute('disabled') && node.offsetParent !== null);
                if (list.length === 0) {
                    return;
                }
                const first = list[0];
                const last = list[list.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            };
            document.addEventListener('keydown', onKeyDown);
            this._helpKeyDown = onKeyDown;
            this.$watch(() => window.Alpine?.store?.('help')?.isOpen, (open) => {
                if (open) {
                    this.$nextTick(() => this.$refs.closeBtn?.focus?.());
                }
            });
        },
        destroy() {
            if (this._helpKeyDown) {
                document.removeEventListener('keydown', this._helpKeyDown);
            }
            const help = window.Alpine?.store?.('help');
            if (help?.isOpen) {
                help.unlockBody();
            }
        },
    }"
    x-cloak
    x-show="$store.help && $store.help.isOpen"
    x-bind:aria-hidden="!($store.help && $store.help.isOpen)"
>
    <button
        type="button"
        class="global-help-modal__backdrop"
        data-help-modal-backdrop
        aria-label="{{ __('seo-content-ai::filament.help.close_aria') }}"
        x-on:click="$store.help.close()"
    ></button>

    <div
        class="global-help-modal__dialog"
        data-help-modal-dialog
        role="dialog"
        aria-modal="true"
        x-bind:aria-labelledby="titleId"
        x-ref="dialog"
    >
        <header class="global-help-modal__header" data-help-modal-header>
            <div class="global-help-modal__heading">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                <h2 x-bind:id="titleId" x-text="($store.help && $store.help.modalTitle) || 'Hướng dẫn hệ thống'">Hướng dẫn hệ thống</h2>
            </div>
            <button
                type="button"
                class="global-help-modal__close"
                data-help-modal-close
                aria-label="{{ __('seo-content-ai::filament.help.close') }}"
                x-ref="closeBtn"
                x-on:click="$store.help.close()"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        <div class="global-help-modal__toolbar" data-help-modal-toolbar>
            <button
                type="button"
                class="help-mobile-back"
                data-help-mobile-back
                x-show="$store.help.mobileView !== 'groups'"
                x-on:click="$store.help.mobileBack()"
            >
                ← {{ __('seo-content-ai::filament.help.back') }}
            </button>

            @include('seo-content-ai::filament.hooks.partials.help-search')
        </div>

        <div
            class="global-help-modal__body"
            data-help-modal-body
            x-bind:data-mobile-view="$store.help.mobileView"
        >
            @include('seo-content-ai::filament.hooks.partials.help-group-navigation')
            @include('seo-content-ai::filament.hooks.partials.help-topic-accordion')
        </div>
    </div>
</div>
