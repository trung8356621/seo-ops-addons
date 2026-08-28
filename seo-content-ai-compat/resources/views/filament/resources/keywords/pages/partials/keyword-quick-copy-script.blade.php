@php
    $copyI18n = [
        'successTitle' => __('seo-content-ai::filament.keyword.quick_copy_success'),
        'failedTitle' => __('seo-content-ai::filament.keyword.quick_copy_failed'),
        'failedBody' => __('seo-content-ai::filament.keyword.quick_copy_failed_body'),
    ];
@endphp

<script>
(() => {
    if (window.__keywordDictionaryCopyBound) {
        return;
    }
    window.__keywordDictionaryCopyBound = true;

    const i18n = @json($copyI18n);

    const notify = (title, body, tone) => {
        if (!window.FilamentNotification) {
            return;
        }
        const n = new window.FilamentNotification().title(title);
        if (body) {
            n.body(body);
        }
        if (tone === 'success') {
            n.success();
        } else {
            n.warning();
        }
        n.send();
    };

    const copyText = async (text) => {
        if (!text) {
            return false;
        }
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (_error) {
            // fallback below
        }
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (_error) {
            return false;
        }
    };

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-keyword-copy-phrase]');
        if (!btn || !(btn instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        const text = btn.getAttribute('data-keyword-copy-phrase') || '';
        const ok = await copyText(text);
        if (ok) {
            notify(i18n.successTitle, text ? `“${text}”` : '', 'success');
            return;
        }
        notify(i18n.failedTitle, i18n.failedBody, 'warning');
    }, true);
})();
</script>
