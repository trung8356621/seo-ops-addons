@php
    $resolver = app(\Omnichannel\Addons\Seo\Support\DomainContextResolver::class);
    $domainContext = \Omnichannel\Addons\Seo\Support\SeoAccessControl::domainContext();
@endphp
<style>
    .fi-main.is-domain-context-loading .fi-page,
    .fi-main.is-domain-context-loading .fi-wi {
        opacity: 0.55;
        transition: opacity 0.15s ease;
        pointer-events: none;
    }

    .seo-panel-loading-bar {
        position: sticky;
        top: 0;
        z-index: 45;
        height: 3px;
        margin-bottom: -3px;
        width: 100%;
        overflow: hidden;
        pointer-events: none;
        opacity: 0;
        background: transparent;
    }

    .seo-panel-loading-bar.is-active {
        opacity: 1;
    }

    .seo-panel-loading-bar__run {
        height: 100%;
        width: 40%;
        background: rgb(var(--primary-500, 59 130 246));
        animation: seo-panel-loading-run 1.1s ease-in-out infinite;
    }

    @keyframes seo-panel-loading-run {
        0% { transform: translateX(-120%); }
        100% { transform: translateX(320%); }
    }
</style>
<script>
    window.__SEO_ACCESSIBLE_DOMAINS__ = @json($resolver->accessibleDomainKeys());
    window.__SEO_SITE_IDS_BY_DOMAIN__ = @json($resolver->accessibleSiteIdsByDomainKey());
    window.__SEO_DOMAIN_CONTEXT_FROM_SERVER__ = @json($domainContext->domainKey);
    window.__SEO_DOMAIN_CONTEXT_KEY__ = window.__SEO_DOMAIN_CONTEXT_KEY__ || window.__SEO_DOMAIN_CONTEXT_FROM_SERVER__;
</script>
@vite('addons/seo/resources/js/domain-context.js')
