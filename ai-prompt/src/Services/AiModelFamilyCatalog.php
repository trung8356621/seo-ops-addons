<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelFamily;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\BuiltInModelCapabilityCatalog;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;

/**
 * Maps exact provider model IDs to user-facing families. Does not rewrite raw IDs.
 */
final class AiModelFamilyCatalog
{
    public const AUTOMATIC = '__automatic__';

    /**
     * @return list<AiModelFamily>
     */
    public function all(): array
    {
        return [
            new AiModelFamily('deepseek.chat', 'DeepSeek Chat', ApiConnectionProviders::DEEPSEEK, 'text', ['deepseek-chat'], 1, 2, 3),
            new AiModelFamily('deepseek.reasoner', 'DeepSeek Reasoner', ApiConnectionProviders::DEEPSEEK, 'text', ['deepseek-reasoner'], 2, 3, 2),
            new AiModelFamily('gemini.flash', 'Gemini Flash', ApiConnectionProviders::GEMINI, 'text', [
                'gemini-3-flash-preview',
                'gemini-3.5-flash-preview',
                'gemini-3.1-flash-lite-preview',
                'gemini-3.1-flash-lite',
            ], 1, 2, 3),
            new AiModelFamily('gemini.pro', 'Gemini Pro', ApiConnectionProviders::GEMINI, 'text', [
                'gemini-3.1-pro-preview',
            ], 3, 3, 1),
            new AiModelFamily('claude.haiku', 'Claude Haiku', ApiConnectionProviders::CLAUDE, 'text', [
                'claude-3-5-haiku-20241022',
                'claude-3-haiku-20240307',
            ], 1, 1, 3),
            new AiModelFamily('claude.sonnet', 'Claude Sonnet', ApiConnectionProviders::CLAUDE, 'text', [
                'claude-sonnet-4-20250514',
                'claude-3-5-sonnet-20240620',
            ], 2, 2, 2),
            new AiModelFamily('claude.opus', 'Claude Opus', ApiConnectionProviders::CLAUDE, 'text', [
                'claude-opus-4-20250514',
            ], 3, 3, 1),
            new AiModelFamily('nano_banana', 'Nano Banana', ApiConnectionProviders::GEMINI, 'image', [
                'gemini-3.1-flash-image-preview',
            ], 1, 2, 3),
            new AiModelFamily('nano_banana_pro', 'Nano Banana Pro', ApiConnectionProviders::GEMINI, 'image', [
                'gemini-3-pro-image-preview',
            ], 2, 3, 2),
            new AiModelFamily('imagen', 'Imagen', ApiConnectionProviders::GEMINI, 'image', [
                'imagen-4.0-fast-generate-001',
                'imagen-4.0-generate-001',
                'imagen-4.0-ultra-generate-001',
            ], 2, 2, 2),
            new AiModelFamily('veo', 'Veo', ApiConnectionProviders::GEMINI, 'video', [
                'veo-3.1-fast-generate-preview',
                'veo-3.1-generate-preview',
                'veo-3.0-fast-generate-preview',
                'veo-3.0-generate-preview',
            ], 2, 2, 2),
        ];
    }

    public function find(string $familyKey): ?AiModelFamily
    {
        foreach ($this->all() as $family) {
            if ($family->familyKey === $familyKey) {
                return $family;
            }
        }

        return null;
    }

    public function familyForModelId(string $providerModelId): ?AiModelFamily
    {
        $normalized = BuiltInModelCapabilityCatalog::normalizeModel($providerModelId);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->all() as $family) {
            foreach ($family->memberModelIds as $member) {
                if (strcasecmp($member, $normalized) === 0) {
                    return $family;
                }
            }
        }

        if (str_contains($normalized, '/')) {
            $suffix = substr($normalized, (int) strrpos($normalized, '/') + 1);

            return $this->familyForModelId($suffix);
        }

        return null;
    }

    public function currentModelId(AiModelFamily $family): ?string
    {
        foreach ($family->memberModelIds as $id) {
            if (GeminiModelVersionPolicy::isEligibleForAutoRouting($id)) {
                return $id;
            }
        }

        return $family->memberModelIds[0] ?? null;
    }

    public function isSelectable(string $providerModelId): bool
    {
        if ($this->familyForModelId($providerModelId) === null) {
            return false;
        }

        return GeminiModelVersionPolicy::isEligibleForAutoRouting($providerModelId);
    }

    /**
     * @return array<string, string> family_key => display_name
     */
    public function optionMapForProfile(AiExecutionProfile $profile): array
    {
        $wanted = match ($profile->group()) {
            'image' => 'image',
            'video' => 'video',
            default => 'text',
        };

        $options = [self::AUTOMATIC => __('seo-content-ai::filament.ai_model_ux.automatic')];
        foreach ($this->all() as $family) {
            if ($family->modality !== $wanted || $family->lifecycle !== 'operational') {
                continue;
            }
            $options[$family->familyKey] = $family->displayName;
        }

        return $options;
    }

    /**
     * @param  list<string>  $familyKeys
     * @return list<AiModelFamily>
     */
    public function sortFamilies(array $familyKeys, AiUsageMode $mode): array
    {
        $families = [];
        foreach ($familyKeys as $key) {
            $family = $this->find($key);
            if ($family instanceof AiModelFamily) {
                $families[] = $family;
            }
        }

        usort($families, static function (AiModelFamily $a, AiModelFamily $b) use ($mode): int {
            if ($mode === AiUsageMode::Economy) {
                return [$a->costTier, -$a->speedTier, $a->qualityTier, $a->familyKey]
                    <=> [$b->costTier, -$b->speedTier, $b->qualityTier, $b->familyKey];
            }

            return [-$a->qualityTier, -$a->costTier, $a->familyKey]
                <=> [-$b->qualityTier, -$b->costTier, $b->familyKey];
        });

        return $families;
    }

    /**
     * @param  list<string>  $familyKeys
     * @return list<string>
     */
    public function expandToModelIds(array $familyKeys, AiUsageMode $mode): array
    {
        $ids = [];
        foreach ($this->sortFamilies($familyKeys, $mode) as $family) {
            $members = $family->memberModelIds;
            if ($mode === AiUsageMode::QualityFirst) {
                $members = array_reverse($members);
            }
            foreach ($members as $id) {
                if (! GeminiModelVersionPolicy::isEligibleForAutoRouting($id)) {
                    continue;
                }
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
