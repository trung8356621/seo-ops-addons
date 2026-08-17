<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\BuiltInModelCapabilityCatalog;
use Omnichannel\Addons\Media\Support\ImageCapability;
use Omnichannel\Addons\Media\Support\ImageCapabilityResolver;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use App\Models\ApiConnection;

/**
 * Canonical model-level capability truth. UI must not invent capabilities.
 */
final class ModelCapabilityRegistry
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(
        private readonly ImageCapabilityResolver $imageCapabilityResolver = new ImageCapabilityResolver(),
    ) {}

    public function supports(ApiConnection|string $connection, string $model, string $capability): bool
    {
        return in_array($capability, $this->capabilitiesFor($connection, $model), true);
    }

    /**
     * @return list<string>
     */
    public function capabilitiesFor(ApiConnection|string $connection, string $model): array
    {
        $provider = $connection instanceof ApiConnection
            ? (string) $connection->provider
            : (string) $connection;
        $model = BuiltInModelCapabilityCatalog::normalizeModel($model);
        $cacheKey = strtolower($provider).'|'
            .($connection instanceof ApiConnection ? (string) $connection->id : 'p')
            .'|'.$model;
        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $base = BuiltInModelCapabilityCatalog::forProviderModel($provider, $model)
            ?? $this->detectedCapabilities($connection, $model)
            ?? $this->fromGeminiRegistry($provider, $model)
            ?? $this->fromOpenRouterGateway($provider, $model)
            ?? $this->providerTextOnlyPolicy($provider, $model)
            ?? [];

        return $this->memo[$cacheKey] = $this->applyManualOverlay($connection, $model, $base);
    }

    public function isEligibleForAutomaticRouting(ApiConnection|string $connection, string $model): bool
    {
        return $this->capabilitiesFor($connection, $model) !== [];
    }

    /**
     * @param  list<string>  $required
     */
    public function satisfiesAll(ApiConnection|string $connection, string $model, array $required): bool
    {
        if ($required === [] || ! $this->isEligibleForAutomaticRouting($connection, $model)) {
            return false;
        }

        $have = $this->capabilitiesFor($connection, $model);
        foreach ($required as $capability) {
            if (! in_array($capability, $have, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * OpenRouter model ids are vendor-prefixed (google/…, deepseek/…). Resolve via upstream catalogs.
     *
     * @return list<string>|null
     */
    private function fromOpenRouterGateway(string $provider, string $model): ?array
    {
        if (strtolower($provider) !== ApiConnectionProviders::OPENROUTER) {
            return null;
        }

        $normalized = strtolower(trim($model));
        if ($normalized === '') {
            return [];
        }

        if (str_starts_with($normalized, 'google/')) {
            return $this->fromGeminiRegistry(
                ApiConnectionProviders::GEMINI,
                substr($model, strlen('google/')),
            );
        }

        if (str_starts_with($normalized, 'deepseek/')) {
            $suffix = substr($model, strlen('deepseek/'));

            return BuiltInModelCapabilityCatalog::forProviderModel(ApiConnectionProviders::DEEPSEEK, $suffix)
                ?? $this->providerTextOnlyPolicy(ApiConnectionProviders::DEEPSEEK, $suffix);
        }

        if (str_starts_with($normalized, 'anthropic/')) {
            $suffix = substr($model, strlen('anthropic/'));

            return BuiltInModelCapabilityCatalog::forProviderModel(ApiConnectionProviders::CLAUDE, $suffix)
                ?? $this->fromGeminiRegistry(ApiConnectionProviders::CLAUDE, $suffix);
        }

        $family = (new AiModelFamilyCatalog())->familyForModelId($model);
        if ($family === null) {
            return null;
        }

        return match ($family->modality) {
            'image' => [AiModelCapability::ImageGenerate->value],
            'video' => [AiModelCapability::VideoGenerate->value],
            default => [
                AiModelCapability::TextGenerate->value,
                AiModelCapability::StructuredOutput->value,
            ],
        };
    }

    /**
     * DeepSeek (and other text-only providers) never claim multimedia.
     *
     * @return list<string>|null
     */
    private function providerTextOnlyPolicy(string $provider, string $model): ?array
    {
        if (strtolower($provider) !== ApiConnectionProviders::DEEPSEEK) {
            return null;
        }

        if ($model === '' || str_contains($model, 'image') || str_contains($model, 'video')) {
            return [];
        }

        $caps = [AiModelCapability::TextGenerate->value];
        if (str_contains($model, 'reason')) {
            $caps[] = AiModelCapability::TextReasoning->value;
        }

        return $caps;
    }

    /**
     * @return list<string>|null
     */
    private function fromGeminiRegistry(string $provider, string $model): ?array
    {
        if (strtolower($provider) !== ApiConnectionProviders::GEMINI) {
            if (strtolower($provider) === ApiConnectionProviders::CLAUDE && str_starts_with(strtolower($model), 'claude')) {
                return [
                    AiModelCapability::TextGenerate->value,
                    AiModelCapability::TextReasoning->value,
                    AiModelCapability::StructuredOutput->value,
                ];
            }

            return null;
        }

        if (! GoogleAiModelRegistry::isRegistered($model)) {
            $slug = strtolower($model);
            if (str_starts_with($slug, 'imagen-')) {
                return [AiModelCapability::ImageGenerate->value];
            }
            if (str_starts_with($slug, 'veo-')) {
                return [AiModelCapability::VideoGenerate->value];
            }

            return [];
        }

        $legacy = $this->imageCapabilityResolver->resolve($model);
        $mapped = $this->mapImageCapabilities($legacy, $model);

        if ($mapped === []) {
            return [];
        }

        if (in_array(AiModelCapability::TextGenerate->value, $mapped, true)
            && (str_contains(strtolower($model), 'pro') || str_contains(strtolower($model), 'flash'))) {
            if (! in_array(AiModelCapability::TextReasoning->value, $mapped, true)
                && (str_contains(strtolower($model), 'pro') || str_contains(strtolower($model), 'thinking'))) {
                $mapped[] = AiModelCapability::TextReasoning->value;
            }
            $mapped[] = AiModelCapability::StructuredOutput->value;
            $mapped[] = AiModelCapability::ToolCall->value;
        }

        return array_values(array_unique($mapped));
    }

    /**
     * @param  list<string>  $legacy
     * @return list<string>
     */
    private function mapImageCapabilities(array $legacy, string $model): array
    {
        $out = [];
        foreach ($legacy as $item) {
            $mapped = match ($item) {
                ImageCapability::TextGeneration->value => AiModelCapability::TextGenerate->value,
                ImageCapability::ImageInput->value => AiModelCapability::VisionInput->value,
                ImageCapability::ImageGeneration->value, ImageCapability::GeneralImage->value => AiModelCapability::ImageGenerate->value,
                ImageCapability::TypographySupported->value, ImageCapability::TypographyRecommended->value => AiModelCapability::ImageTypography->value,
                ImageCapability::VideoGeneration->value => AiModelCapability::VideoGenerate->value,
                ImageCapability::Unknown->value => null,
                default => null,
            };
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        unset($model);

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $base
     * @return list<string>
     */
    private function applyManualOverlay(ApiConnection|string $connection, string $model, array $base): array
    {
        $rows = $this->capabilityRows($connection, $model, AiCapabilitySource::Manual);
        if ($rows === []) {
            return array_values(array_unique($base));
        }

        $set = array_fill_keys($base, true);
        foreach ($rows as $row) {
            $key = trim((string) $row->capability);
            if ($key === '') {
                continue;
            }
            if ((bool) $row->enabled) {
                $set[$key] = true;
            } else {
                unset($set[$key]);
            }
        }

        return array_keys($set);
    }

    /**
     * @return list<string>|null
     */
    private function detectedCapabilities(ApiConnection|string $connection, string $model): ?array
    {
        $rows = $this->capabilityRows($connection, $model, AiCapabilitySource::Detected);
        if ($rows === []) {
            return null;
        }

        return $this->enabledKeys($rows);
    }

    /**
     * @return list<AiModelCapabilityRow>
     */
    private function capabilityRows(ApiConnection|string $connection, string $model, AiCapabilitySource $source): array
    {
        if (! class_exists(AiModelCapabilityRow::class) || ! function_exists('app')) {
            return [];
        }

        try {
            $query = AiModelCapabilityRow::query()->where('source', $source->value)->where('model_key', $model);
            if ($connection instanceof ApiConnection) {
                $seoModelIds = SeoAiModel::query()
                    ->where('api_connection_id', $connection->id)
                    ->where('raw_model_name', $model)
                    ->pluck('id')
                    ->all();
                if ($seoModelIds !== []) {
                    $query->where(function ($inner) use ($connection, $seoModelIds): void {
                        $inner->whereIn('seo_ai_model_id', $seoModelIds)
                            ->orWhere('api_connection_id', $connection->id);
                    });
                } else {
                    $query->where('api_connection_id', $connection->id);
                }
            }

            return $query->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
