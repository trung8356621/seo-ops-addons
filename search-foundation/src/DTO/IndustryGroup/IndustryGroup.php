<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup;

use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;

/**
 * Read-only Industry Group projection from active Industry Match & Research taxonomy.
 * Not a Keyword, Keyword Group, Topic, or Tag. No separate DB table.
 */
final class IndustryGroup
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $positiveExamples
     * @param  list<string>  $negativeExamples
     * @param  array<string, mixed>  $provenance
     * @param  array<string, array<string, mixed>>  $locales
     */
    public function __construct(
        public readonly string $key,
        public readonly IndustryGroupType $groupType,
        public readonly string $sourceLocale,
        public readonly string $name,
        public readonly array $aliases,
        public readonly array $positiveExamples,
        public readonly array $negativeExamples,
        public readonly ?string $matchMode,
        public readonly array $provenance,
        public readonly bool $stale,
        public readonly bool $enabled,
        public readonly ?string $industryContextKey,
        public readonly string $requestedLocale,
        public readonly string $effectiveLocale,
        public readonly bool $localized,
        public readonly array $locales = [],
    ) {}

    public function semanticDefinition(): IndustryGroupSemanticDefinition
    {
        $positives = self::dedupeExamples([
            $this->name,
            ...$this->aliases,
            ...$this->positiveExamples,
        ]);

        return new IndustryGroupSemanticDefinition(
            key: $this->key,
            name: $this->name,
            positiveExamples: $positives,
            negativeExamples: self::dedupeExamples($this->negativeExamples),
            groupType: $this->groupType->value,
            locale: $this->effectiveLocale,
            matchMode: $this->matchMode,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'industry_context_key' => $this->industryContextKey,
            'group_type' => $this->groupType->value,
            'group_type_label' => $this->groupType->label(),
            'source_locale' => $this->sourceLocale,
            'name' => $this->name,
            'aliases' => $this->aliases,
            'positive_examples' => $this->positiveExamples,
            'negative_examples' => $this->negativeExamples,
            'match_mode' => $this->matchMode,
            'provenance' => $this->provenance,
            'stale' => $this->stale,
            'enabled' => $this->enabled,
            'requested_locale' => $this->requestedLocale,
            'effective_locale' => $this->effectiveLocale,
            'localized' => $this->localized,
            'locales' => $this->locales,
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function dedupeExamples(array $values): array
    {
        $out = [];
        $seen = [];
        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            $dedupe = mb_strtolower($trimmed, 'UTF-8');
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $out[] = $trimmed;
        }

        return $out;
    }
}
