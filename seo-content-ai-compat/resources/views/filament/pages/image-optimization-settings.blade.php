<x-filament-panels::page>
    @vite('addons/media/resources/css/image-optimization-settings.css')

    <div class="seo-image-opt">
        <div class="seo-image-opt__toolbar">
            <div class="seo-image-opt__toolbar-row">
                @unless ($this->hasLockedGlobalSite())
                    <label class="seo-image-opt__label" for="seo-image-opt-site">Apply to website:</label>
                    <x-select id="seo-image-opt-site" wire:model.live="siteId" class="text-sm">
                        <option value="">-- System default (Global) --</option>
                        @foreach ($this->sites as $site)
                            <option value="{{ $site->id }}">{{ $site->domain }}</option>
                        @endforeach
                    </x-select>
                @else
                    <span class="seo-image-opt__label">Apply to website:</span>
                    <span class="seo-image-opt__select">
                        {{ $this->currentSiteDomain() ?? ('Site #' . (int) ($siteId ?? 0)) }}
                    </span>
                @endunless
            </div>
            <p class="seo-image-opt__hint">Site-specific configuration overrides global system defaults.</p>
        </div>

        <div class="seo-image-opt__grid">
            <section class="seo-image-opt__card">
                <h3 class="seo-image-opt__card-title">Compression &amp; Format conversion</h3>
                <hr class="seo-image-opt__divider" />

                <label class="seo-image-opt__check">
                    <input type="checkbox" wire:model="data.auto_convert_webp" />
                    <span>
                        <strong>Convert to WebP when syncing to WordPress</strong>
                        <small>Local Laravel library keeps the original format (JPEG/PNG). WebP conversion runs on WordPress upload only.</small>
                    </span>
                </label>

                <div class="seo-image-opt__range-wrap">
                    <label class="seo-image-opt__range-label">
                        Image compression quality (Quality: {{ $data['quality'] ?? 80 }}%)
                    </label>
                    <input type="range" min="10" max="100" wire:model.live="data.quality" class="seo-image-opt__range" />
                    <div class="seo-image-opt__range-hints">
                        <span>Most compressed (10%)</span>
                        <span>Balanced (80%)</span>
                        <span>Original quality (100%)</span>
                    </div>
                </div>
            </section>

            <section class="seo-image-opt__card">
                <h3 class="seo-image-opt__card-title">Size limits (Resize)</h3>
                <hr class="seo-image-opt__divider" />

                <label class="seo-image-opt__check">
                    <input type="checkbox" wire:model.live="data.limit_dimensions" />
                    <span>
                        <strong>Enable size limits</strong>
                        <small>Automatically shrink oversized images to reduce storage usage</small>
                    </span>
                </label>

                @if ($data['limit_dimensions'] ?? false)
                    <p class="seo-image-opt__hint">
                        Enter only <strong>one</strong> dimension: width to limit by width, height to limit by height.
                        Leave the other empty to keep aspect ratio (no distortion/cropping).
                    </p>
                    <div class="seo-image-opt__dims">
                        <div>
                            <label class="seo-image-opt__field-label">Max width (px)</label>
                            <input
                                type="number"
                                min="0"
                                wire:model="data.max_width"
                                class="seo-image-opt__input"
                                placeholder="Example: 1024"
                            />
                        </div>
                        <div>
                            <label class="seo-image-opt__field-label">Max height (px)</label>
                            <input
                                type="number"
                                min="0"
                                wire:model="data.max_height"
                                class="seo-image-opt__input"
                                placeholder="Leave empty if only limiting width"
                            />
                        </div>
                    </div>
                @endif
            </section>

            <section class="seo-image-opt__card seo-image-opt__card--wide">
                <h3 class="seo-image-opt__card-title">SEO normalization for file name &amp; ALT tag</h3>
                <hr class="seo-image-opt__divider" />

                <div class="seo-image-opt__seo-grid">
                    <div class="seo-image-opt__seo-checks">
                        <label class="seo-image-opt__check">
                            <input type="checkbox" wire:model="data.clean_filename" />
                            <span>
                                <strong>Automatically sanitize file name</strong>
                                <small>Remove accents/special characters and replace spaces with hyphens</small>
                            </span>
                        </label>

                        <label class="seo-image-opt__check">
                            <input type="checkbox" wire:model.live="data.auto_alt_tag" />
                            <span>
                                <strong>Automatically generate ALT tag</strong>
                                <small>Auto-create SEO-friendly alternative text for uploaded images</small>
                            </span>
                        </label>
                    </div>

                    @if ($data['auto_alt_tag'] ?? false)
                        <div>
                            <label class="seo-image-opt__field-label">ALT tag pattern</label>
                            <input
                                type="text"
                                wire:model="data.alt_tag_pattern"
                                class="seo-image-opt__input"
                                placeholder="{post_title} - {focus_keyword}"
                            />
                            <p class="seo-image-opt__pattern-hint">
                                Variables: <code>{post_title}</code> = article title,
                                <code>{focus_keyword}</code> = SEO focus keyword.
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="seo-image-opt__actions">
            <x-filament::button
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
            >
                <span wire:loading.remove wire:target="save">Save settings</span>
                <span wire:loading wire:target="save">{{ __('seo-content-ai::filament.common.saving') }}</span>
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
