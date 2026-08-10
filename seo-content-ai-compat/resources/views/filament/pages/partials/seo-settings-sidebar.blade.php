@php
    use Omnichannel\Addons\Seo\Support\SeoSettingsMenu;
    $cssPath = base_path('addons/seo/resources/css/seo-settings.css');
@endphp

@if(is_readable($cssPath))
    <style>{!! file_get_contents($cssPath) !!}</style>
@endif

<nav class="seo-settings-nav h-full" aria-label="{{ __('SEO settings') }}">
    @foreach(SeoSettingsMenu::items() as $item)
        <a
            href="{{ $item['url'] }}"
            class="seo-settings-nav__item {{ ($active ?? '') === $item['id'] ? 'is-active' : '' }}"
        >
            <x-filament::icon :icon="$item['icon']" class="seo-settings-nav__icon" />
            <span>{{ __($item['label']) }}</span>
        </a>
    @endforeach
</nav>
