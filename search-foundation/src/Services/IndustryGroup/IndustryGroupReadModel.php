<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\IndustryGroup;

use Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\MatchResearchRegistry;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroupSemanticDefinition;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;

/**
 * Read-only Industry Group projection over MatchResearchRegistry.
 * Does not re-read raw Industry JSON and does not persist rows.
 */
final class IndustryGroupReadModel implements IndustryGroupProvider
{
    public function __construct(private readonly MatchResearchRegistry $registry) {}

    public function list(
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): array {
        $groups = [];
        foreach ($this->registry->list($siteId, MatchResearchOrigin::Industry, $industryContextKey) as $resource) {
            $group = $this->project($resource, $locale);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function find(
        string $key,
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): ?IndustryGroup {
        $resource = $this->registry->find($key, $siteId, $industryContextKey);
        if ($resource === null) {
            return null;
        }

        return $this->project($resource, $locale);
    }

    public function semanticDefinitions(
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): array {
        return array_map(
            static fn (IndustryGroup $group): IndustryGroupSemanticDefinition => $group->semanticDefinition(),
            $this->list($siteId, $industryContextKey, $locale),
        );
    }

    public static function qualifies(MatchResearchResource $resource): bool
    {
        if ($resource->origin !== MatchResearchOrigin::Industry) {
            return false;
        }
        if ($resource->kind !== MatchResearchKind::Concept) {
            return false;
        }

        $group = (string) ($resource->provenance['group'] ?? $resource->payload['group'] ?? '');

        return IndustryGroupType::tryFromGroup($group) !== null;
    }

    private function project(MatchResearchResource $resource, ?string $locale): ?IndustryGroup
    {
        if (! self::qualifies($resource)) {
            return null;
        }

        $groupRaw = (string) ($resource->provenance['group'] ?? $resource->payload['group'] ?? '');
        $groupType = IndustryGroupType::tryFromGroup($groupRaw);
        if ($groupType === null) {
            return null;
        }

        $requested = trim((string) ($locale ?? $resource->sourceLocale));
        if ($requested === '') {
            $requested = $resource->sourceLocale;
        }

        $localized = false;
        $effectiveLocale = $resource->sourceLocale;
        $payload = $resource->payload;

        if ($requested === $resource->sourceLocale) {
            $localized = true;
            $effectiveLocale = $resource->sourceLocale;
        } elseif (isset($resource->locales[$requested]) && is_array($resource->locales[$requested])) {
            $overlay = $resource->locales[$requested];
            $localized = true;
            $effectiveLocale = $requested;
            $payload = array_merge($payload, $overlay);
        } else {
            // Explicit missing localization — fall back to source terms.
            $localized = false;
            $effectiveLocale = $resource->sourceLocale;
        }

        $name = trim((string) ($payload['name'] ?? $resource->label));
        if ($name === '') {
            $name = $resource->label;
        }

        return new IndustryGroup(
            key: $resource->key,
            groupType: $groupType,
            sourceLocale: $resource->sourceLocale,
            name: $name,
            aliases: IndustryGroup::dedupeExamples(array_map('strval', (array) ($payload['aliases'] ?? []))),
            positiveExamples: IndustryGroup::dedupeExamples(array_map('strval', (array) ($payload['positive_examples'] ?? []))),
            negativeExamples: IndustryGroup::dedupeExamples(array_map('strval', (array) ($payload['negative_examples'] ?? []))),
            matchMode: $resource->matchMode,
            provenance: $resource->provenance,
            stale: (bool) ($resource->provenance['stale'] ?? false),
            enabled: $resource->enabled,
            industryContextKey: isset($resource->provenance['industry_context_key'])
                ? (string) $resource->provenance['industry_context_key']
                : null,
            requestedLocale: $requested,
            effectiveLocale: $effectiveLocale,
            localized: $localized,
            locales: $resource->locales,
        );
    }
}
