<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\TagsInput;
use Omnichannel\Addons\Seo\Support\SettingsTagNormalizer;

/**
 * SettingsTagsInput Form Component
 *
 * Dedicated Filament TagsInput component for Settings list fields.
 * Pairs with settingsTagsInputFormComponent in Alpine to provide immediate
 * frontend canonicalization, deduplication, and invalid rejection,
 * while maintaining backend safety rules and dehydration canonicalization.
 */
class SettingsTagsInput extends TagsInput
{
    public const TRUSTED_DOMAIN = SettingsTagNormalizer::TYPE_TRUSTED_DOMAIN;
    public const STRICT_DOMAIN = SettingsTagNormalizer::TYPE_STRICT_DOMAIN;
    public const EXTENSION = SettingsTagNormalizer::TYPE_EXTENSION;
    public const PHRASE = SettingsTagNormalizer::TYPE_PHRASE;

    protected string $view = 'seo::filament.forms.components.settings-tags-input';

    protected string | Closure $normalizerType = self::TRUSTED_DOMAIN;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nestedRecursiveRules([
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    $fail(__('Validation failed'));

                    return;
                }

                $type = $this->getNormalizerType();
                $valid = match ($type) {
                    self::TRUSTED_DOMAIN => SettingsTagNormalizer::normalizeTrustedDomainTag($value) !== null,
                    self::STRICT_DOMAIN => SettingsTagNormalizer::normalizeStrictDomainTag($value) !== null,
                    self::EXTENSION => SettingsTagNormalizer::normalizeExtensionTag($value) !== null,
                    self::PHRASE => SettingsTagNormalizer::normalizePhraseTag($value) !== null,
                    default => true,
                };

                if (! $valid) {
                    $message = match ($type) {
                        self::TRUSTED_DOMAIN => __('seo-content-ai::filament.settings_editor.invalid_trusted_domain', ['domain' => $value]),
                        self::STRICT_DOMAIN => __('seo-content-ai::filament.settings_general.invalid_social_domain', ['domain' => $value]),
                        self::EXTENSION => __('seo-content-ai::filament.settings_general.invalid_extension', ['extension' => $value]),
                        default => __('Validation failed for :value', ['value' => $value]),
                    };

                    $fail($message);
                }
            },
        ]);

        $this->dehydrateStateUsing(static function (SettingsTagsInput $component, $state) {
            if (! is_array($state)) {
                return $state;
            }

            $type = $component->getNormalizerType();

            return match ($type) {
                self::TRUSTED_DOMAIN => SettingsTagNormalizer::normalizeTrustedDomainTags($state),
                self::STRICT_DOMAIN => SettingsTagNormalizer::normalizeStrictDomainTags($state),
                self::EXTENSION => SettingsTagNormalizer::normalizeExtensionTags($state),
                self::PHRASE => SettingsTagNormalizer::normalizePhraseTags($state),
                default => $state,
            };
        });
    }

    public function normalizer(string | Closure $type): static
    {
        $this->normalizerType = $type;

        return $this;
    }

    public function getNormalizerType(): string
    {
        return (string) $this->evaluate($this->normalizerType);
    }
}
