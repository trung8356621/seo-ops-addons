@php
    use Omnichannel\Addons\Content\Support\SystemDateTime;
    $cfg = SystemDateTime::frontendConfig();
@endphp
<script>
    window.__SYSTEM_DATETIME__ = @json($cfg);
    window.dispatchEvent(new CustomEvent('seo-datetime-config', { detail: window.__SYSTEM_DATETIME__ }));
    document.addEventListener('livewire:init', () => {
        Livewire.on('seo-datetime-settings-updated', (payload) => {
            const config = Array.isArray(payload) ? (payload[0]?.config ?? payload[0]) : (payload?.config ?? payload);
            if (config && typeof config === 'object') {
                window.__SYSTEM_DATETIME__ = Object.assign({}, window.__SYSTEM_DATETIME__ || {}, config);
                if (window.SystemDateTime && typeof window.SystemDateTime.setSystemDateTimeConfig === 'function') {
                    window.SystemDateTime.setSystemDateTimeConfig(window.__SYSTEM_DATETIME__);
                }
                window.dispatchEvent(new CustomEvent('seo-datetime-config', { detail: window.__SYSTEM_DATETIME__ }));
            }
        });
    });
</script>
@vite('addons/content/resources/js/utils/systemDateTime.js')
