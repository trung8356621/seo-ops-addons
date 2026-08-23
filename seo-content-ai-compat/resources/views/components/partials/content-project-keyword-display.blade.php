@if ($hasOverride && $original !== '—' && $effective !== '—' && $original !== $effective)
    <span class="cp-ops-kw-original" title="{{ $original }}">{{ \Illuminate\Support\Str::limit($original, 40) }}</span>
    <span class="cp-ops-kw-arrow" aria-hidden="true">→</span>
    <span class="cp-ops-kw-effective" title="{{ $effective }}">{{ \Illuminate\Support\Str::limit($effective, 40) }}</span>
    <span class="cp-ops-kw-badge">{{ __('seo-content-ai::filament.projects.keyword_override_badge') }}</span>
@else
    <span class="cp-ops-kw-text" title="{{ $effective !== '—' ? $effective : $original }}">{{ $effective !== '—' ? $effective : $original }}</span>
@endif
@if ($dirty)
    <span class="cp-ops-kw-dirty" title="{{ __('seo-content-ai::filament.projects.keyword_override_dirty_hint') }}">{{ __('seo-content-ai::filament.projects.keyword_override_dirty_badge') }}</span>
@endif
