<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Known decision-class model identities.
 *
 * Lives in AI Settings ownership so Agent Runtime never hardcodes model names.
 * Leaf ids are exact. Vendor prefixes (vendor/jev) resolve to the leaf.
 */
final class DecisionModelIdentityCatalog
{
    /**
     * @return list<string>
     */
    public static function knownLeaves(): array
    {
        return ['jev', 'laya'];
    }

    /**
     * @return list<string>|null decision capability keys, or null when the id is not a decision model
     */
    public static function capabilitiesFor(string $model): ?array
    {
        if (! self::isDecisionModelId($model)) {
            return null;
        }

        return array_map(
            static fn (AiModelCapability $capability): string => $capability->value,
            AiModelCapability::decision(),
        );
    }

    public static function isDecisionModelId(string $model): bool
    {
        $leaf = self::leaf($model);

        return $leaf !== '' && in_array($leaf, self::knownLeaves(), true);
    }

    public static function leaf(string $model): string
    {
        $normalized = strtolower(BuiltInModelCapabilityCatalog::normalizeModel($model));
        if ($normalized === '') {
            return '';
        }
        if (str_contains($normalized, '/')) {
            $normalized = substr($normalized, (int) strrpos($normalized, '/') + 1);
        }
        if (str_contains($normalized, ':')) {
            $normalized = substr($normalized, 0, (int) strpos($normalized, ':'));
        }

        return $normalized;
    }
}
