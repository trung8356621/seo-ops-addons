<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;

/**
 * Decision-class identities discovered from a provider catalog.
 *
 * OpenRouter Jev ids are an external contract. Rows still have to arrive
 * through model discovery on a real connection. This catalog does not insert
 * SeoAiModel rows. Laya is not an OpenRouter model id.
 */
final class DecisionModelIdentityCatalog
{
    /**
     * Pinned OpenRouter Decisions models. `typesafe/jev-router` is text output on the
     * default catalog and is not a member. Dated 1.13 releases match {@see isOpenRouterJev()}.
     *
     * @return list<string>
     */
    public static function openRouterJevModelIds(): array
    {
        return [
            'typesafe/jev-latest',
            'typesafe/jev-1.13',
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return list<string>|null
     */
    public static function capabilitiesFor(string $model, array $capabilities = []): ?array
    {
        if (! self::isDecisionModel($model, $capabilities)) {
            return null;
        }

        return array_map(
            static fn (AiModelCapability $capability): string => $capability->value,
            AiModelCapability::decision(),
        );
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    public static function isDecisionModel(string $model, array $capabilities = []): bool
    {
        return self::isOpenRouterJev($model) || self::metadataDeclaresDecision($capabilities);
    }

    public static function isOpenRouterJev(string $model): bool
    {
        $id = strtolower(ltrim(trim($model), '~'));

        return preg_match('#^typesafe/jev-(?:latest|1\.13(?:-\d{8})?)$#', $id) === 1;
    }

    /**
     * Provider architecture says this row is a decision/System One model.
     *
     * @param  array<string, mixed>  $capabilities
     */
    public static function metadataDeclaresDecision(array $capabilities): bool
    {
        $modality = OpenRouterModelEconomics::architectureModality($capabilities);
        if ($modality === '') {
            return false;
        }

        return str_contains($modality, 'decision')
            || str_contains($modality, 'systemone')
            || str_contains($modality, 'system-one');
    }
}
