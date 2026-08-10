@php
    $badge = $this->currentBadge();
    $cards = $this->recommendationCards();
@endphp

<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'recommendations'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_recommendations.title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_recommendations.intro') }}</p>
                </header>

                <section class="seo-rec-badge" aria-label="{{ __('seo-content-ai::filament.settings_recommendations.badge_title') }}">
                    <h2 class="seo-rec-badge__title">
                        <x-filament::icon icon="heroicon-o-sparkles" class="seo-rec-badge__icon" />
                        {{ __('seo-content-ai::filament.settings_recommendations.badge_title') }}
                    </h2>
                    <dl class="seo-rec-badge__grid">
                        <div class="seo-rec-badge__item">
                            <dt>{{ __('seo-content-ai::filament.settings_recommendations.badge_general') }}</dt>
                            <dd>{{ $badge['general_image'] }}</dd>
                        </div>
                        <div class="seo-rec-badge__item">
                            <dt>{{ __('seo-content-ai::filament.settings_recommendations.badge_typography') }}</dt>
                            <dd>{{ $badge['typography'] }}</dd>
                        </div>
                        <div class="seo-rec-badge__item">
                            <dt>{{ __('seo-content-ai::filament.settings_recommendations.badge_video') }}</dt>
                            <dd>{{ $badge['video'] }}</dd>
                        </div>
                    </dl>
                </section>

                <div class="seo-rec-cards">
                    @foreach ($cards as $card)
                        @php
                            $tone = (string) ($card['tone'] ?? 'info');
                        @endphp
                        <article
                            class="seo-rec-card seo-rec-card--{{ $tone }}"
                            wire:key="rec-card-{{ $card['id'] ?? $loop->index }}"
                        >
                            <header class="seo-rec-card__head">
                                <x-filament::icon :icon="$card['icon'] ?? 'heroicon-o-information-circle'" class="seo-rec-card__icon" />
                                <h3 class="seo-rec-card__title">{{ __($card['title_key']) }}</h3>
                            </header>

                            <div class="seo-rec-card__body">
                                @foreach ($card['blocks'] ?? [] as $block)
                                    @php $blockType = (string) ($block['type'] ?? ''); @endphp

                                    @if ($blockType === 'subheading')
                                        <h4 class="seo-rec-block__subheading">{{ __($block['key']) }}</h4>
                                    @elseif ($blockType === 'paragraph')
                                        <p class="seo-rec-block__paragraph">{{ __($block['key']) }}</p>
                                    @elseif ($blockType === 'pairs')
                                        <ul class="seo-rec-block__pairs">
                                            @foreach ($block['items'] ?? [] as $pair)
                                                <li>
                                                    <span class="seo-rec-block__pair-label">{{ __($pair['label_key']) }}</span>
                                                    <span class="seo-rec-block__pair-arrow" aria-hidden="true">→</span>
                                                    <span class="seo-rec-block__pair-value">{{ $pair['value'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @elseif ($blockType === 'bullets')
                                        @php $listStyle = (string) ($block['style'] ?? 'default'); @endphp
                                        <ul class="seo-rec-block__list seo-rec-block__list--{{ $listStyle }}">
                                            @foreach ($block['items'] ?? [] as $itemKey)
                                                <li>{{ __($itemKey) }}</li>
                                            @endforeach
                                        </ul>
                                    @elseif ($blockType === 'numbered')
                                        <ol class="seo-rec-block__numbered">
                                            @foreach ($block['items'] ?? [] as $itemKey)
                                                <li>{{ __($itemKey) }}</li>
                                            @endforeach
                                        </ol>
                                    @elseif ($blockType === 'flow')
                                        <div class="seo-rec-block__flow">
                                            @foreach ($block['steps'] ?? [] as $stepKey)
                                                <div class="seo-rec-block__flow-step">{{ __($stepKey) }}</div>
                                                @if (! $loop->last)
                                                    <div class="seo-rec-block__flow-arrow" aria-hidden="true">↓</div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @elseif ($blockType === 'status')
                                        <div class="seo-rec-block__status">
                                            <span class="seo-rec-block__status-badge">{{ $block['label'] ?? '' }}</span>
                                            @if (! empty($block['key']))
                                                <p class="seo-rec-block__paragraph">{{ __($block['key']) }}</p>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament-panels::page>
</div>
