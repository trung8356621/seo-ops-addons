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
</style>
<script>
    window.__SEO_ACCESSIBLE_DOMAINS__ = @json($resolver->accessibleDomainKeys());
    window.__SEO_DOMAIN_CONTEXT_FROM_SERVER__ = @json($domainContext->domainKey);
    window.__SEO_DOMAIN_CONTEXT_KEY__ = window.__SEO_DOMAIN_CONTEXT_KEY__ || window.__SEO_DOMAIN_CONTEXT_FROM_SERVER__;
</script>
@vite('addons/seo/resources/js/domain-context.js')
