@php
    $color = $getColor() ?? 'primary';
    $hasInlineLabel = $hasInlineLabel();
    $id = $getId();
    $isDisabled = $isDisabled();
    $isPrefixInline = $isPrefixInline();
    $isReorderable = (! $isDisabled) && $isReorderable();
    $isSuffixInline = $isSuffixInline();
    $prefixActions = $getPrefixActions();
    $prefixIcon = $getPrefixIcon();
    $prefixLabel = $getPrefixLabel();
    $statePath = $getStatePath();
    $suffixActions = $getSuffixActions();
    $suffixIcon = $getSuffixIcon();
    $suffixLabel = $getSuffixLabel();
    $normalizerType = $getNormalizerType();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
    :has-inline-label="$hasInlineLabel"
>
    <x-slot
        name="label"
        @class([
            'sm:pt-1.5' => $hasInlineLabel,
        ])
    >
        {{ $getLabel() }}
    </x-slot>

    <x-filament::input.wrapper
        :disabled="$isDisabled"
        :inline-prefix="$isPrefixInline"
        :inline-suffix="$isSuffixInline"
        :prefix="$prefixLabel"
        :prefix-actions="$prefixActions"
        :prefix-icon="$prefixIcon"
        :prefix-icon-color="$getPrefixIconColor()"
        :suffix="$suffixLabel"
        :suffix-actions="$suffixActions"
        :suffix-icon="$suffixIcon"
        :suffix-icon-color="$getSuffixIconColor()"
        :valid="! $errors->has($statePath)"
        :attributes="
            \Filament\Support\prepare_inherited_attributes($attributes)
                ->merge($getExtraAttributes(), escape: false)
                ->class(['fi-fo-tags-input', 'fi-fo-settings-tags-input'])
        "
    >
        <div
            x-data="settingsTagsInputFormComponent({
                        state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
                        splitKeys: @js($getSplitKeys()),
                        normalizerType: @js($normalizerType),
                    })"
            {{ $getExtraAlpineAttributeBag() }}
        >
            <x-filament::input
                autocomplete="off"
                :autofocus="$isAutofocused()"
                :disabled="$isDisabled"
                :id="$id"
                :inline-prefix="$isPrefixInline && (count($prefixActions) || $prefixIcon || filled($prefixLabel))"
                :inline-suffix="$isSuffixInline && (count($suffixActions) || $suffixIcon || filled($suffixLabel))"
                :list="$id . '-suggestions'"
                :placeholder="$getPlaceholder()"
                type="text"
                x-bind="input"
                :attributes="\Filament\Support\prepare_inherited_attributes($getExtraInputAttributeBag())"
            />

            <datalist id="{{ $id }}-suggestions">
                @foreach ($getSuggestions() as $suggestion)
                    <template
                        x-bind:key="@js($suggestion)"
                        x-if="! (state?.includes(@js($suggestion)) ?? true)"
                    >
                        <option value="{{ $suggestion }}" />
                    </template>
                @endforeach
            </datalist>

            <div
                @class([
                    '[&_.fi-badge-delete-button]:hidden' => $isDisabled,
                ])
            >
                <div wire:ignore>
                    <template x-cloak x-if="state?.length">
                        <div
                            @if ($isReorderable)
                                x-on:end.stop="reorderTags($event)"
                                x-sortable
                                data-sortable-animation-duration="{{ $getReorderAnimationDuration() }}"
                            @endif
                            class="fi-fo-tags-input-tags-ctn flex w-full flex-wrap gap-1.5 border-t border-t-gray-200 p-2 dark:border-t-white/10"
                        >
                            <template
                                x-for="(tag, index) in state"
                                x-bind:key="`${tag}-${index}`"
                                class="hidden"
                            >
                                <x-filament::badge
                                    :color="$color"
                                    :x-bind:x-sortable-item="$isReorderable ? 'index' : null"
                                    :x-sortable-handle="$isReorderable ? '' : null"
                                    @class([
                                        'cursor-move' => $isReorderable,
                                    ])
                                >
                                    {{ $getTagPrefix() }}

                                    <span
                                        x-text="tag"
                                        class="select-none text-start"
                                    ></span>

                                    {{ $getTagSuffix() }}

                                    <x-slot
                                        name="deleteButton"
                                        x-on:click.stop="deleteTag(tag)"
                                    ></x-slot>
                                </x-filament::badge>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </x-filament::input.wrapper>
</x-dynamic-component>

@once
<script>
    (function () {
        const normalizeTag = function (raw, normalizerType) {
            if (!raw || typeof raw !== 'string') return null;

            if (normalizerType === 'extension') {
                const clean = raw.trim().toLowerCase().replace(/^\.+/, '');
                if (!clean || !/^[a-z0-9]{1,12}$/.test(clean)) return null;
                return clean;
            }

            if (normalizerType === 'phrase') {
                const clean = raw.trim().toLowerCase().replace(/\s+/g, ' ');
                return clean || null;
            }

            let val = raw.trim();
            if (!val || /\s/.test(val)) return null;

            const sm = val.match(/^[a-zA-Z][a-zA-Z0-9+.-]*:/);
            if (sm) {
                const s = sm[0].toLowerCase();
                if (s !== 'http:' && s !== 'https:') return null;
            }

            if (!val.includes('://') && !val.startsWith('//') && val.includes('@')) return null;

            let parseTarget = val;
            if (parseTarget.startsWith('//')) {
                parseTarget = 'https:' + parseTarget;
            } else if (parseTarget.includes('://') || (parseTarget.includes('/') && !parseTarget.startsWith('*.'))) {
                if (!parseTarget.includes('://')) parseTarget = 'https://' + parseTarget;
            }

            if (parseTarget.includes('://')) {
                try {
                    const u = new URL(parseTarget);
                    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
                    val = u.hostname;
                } catch {
                    return null;
                }
            }

            val = val.toLowerCase().replace(/\.+$/, '');

            if (normalizerType === 'trusted_domain' && val.startsWith('*.')) {
                const suffix = val.slice(2).replace(/\.+$/, '');
                if (!suffix || /[*\/#?]/.test(suffix)) return null;
                if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/.test(suffix)) return null;
                if (!suffix.includes('.') && suffix.length < 2) return null;
                return '*.' + suffix;
            }

            if (val.includes('*')) return null;

            if (val.startsWith('www.')) val = val.slice(4);
            val = val.replace(/\.+$/, '');

            if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/.test(val)) return null;

            return val;
        };

        function settingsTagsInputFormComponent({ state, splitKeys = [], normalizerType = 'trusted_domain' }) {
            return {
                newTag: '',
                state,
                splitKeys,
                normalizerType,

                init: function () {
                    if (Array.isArray(this.state)) {
                        const cleaned = [];
                        for (const t of this.state) {
                            const norm = normalizeTag(t, this.normalizerType);
                            if (norm && !cleaned.includes(norm)) cleaned.push(norm);
                        }
                        this.state = cleaned;
                    }
                },

                createTag: function () {
                    this.newTag = (this.newTag || '').trim();
                    if (this.newTag === '') return;

                    const normalized = normalizeTag(this.newTag, this.normalizerType);
                    if (!normalized) {
                        this.newTag = '';
                        return;
                    }

                    if (this.state.includes(normalized)) {
                        this.newTag = '';
                        return;
                    }

                    this.state.push(normalized);
                    this.newTag = '';
                },

                deleteTag: function (tagToDelete) {
                    this.state = this.state.filter((tag) => tag !== tagToDelete);
                },

                reorderTags: function (event) {
                    const reordered = this.state.splice(event.oldIndex, 1)[0];
                    this.state.splice(event.newIndex, 0, reordered);
                    this.state = [...this.state];
                },

                input: {
                    ['x-on:blur']: 'createTag()',
                    ['x-model']: 'newTag',
                    ['x-on:keydown'](event) {
                        if (['Enter', ...splitKeys].includes(event.key)) {
                            event.preventDefault();
                            event.stopPropagation();
                            this.createTag();
                        }
                    },
                    ['x-on:paste']() {
                        this.$nextTick(() => {
                            if (splitKeys.length === 0) {
                                this.createTag();
                                return;
                            }
                            const pattern = splitKeys
                                .map((key) => key.replace(/[/\-\\^$*+?.()|[\]{}]/g, '\\$&'))
                                .join('|');
                            this.newTag
                                .split(new RegExp(pattern, 'g'))
                                .forEach((tag) => {
                                    this.newTag = tag;
                                    this.createTag();
                                });
                        });
                    },
                },
            };
        }

        window.settingsTagsInputFormComponent = settingsTagsInputFormComponent;
        window.normalizeSettingsTag = normalizeTag;

        function registerAlpine() {
            if (window.Alpine && typeof window.Alpine.data === 'function') {
                window.Alpine.data('settingsTagsInputFormComponent', settingsTagsInputFormComponent);
            }
        }

        document.addEventListener('alpine:init', registerAlpine);
        if (window.Alpine) {
            registerAlpine();
        }
    })();
</script>
@endonce
