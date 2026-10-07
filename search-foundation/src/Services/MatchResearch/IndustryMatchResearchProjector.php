<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchCapabilities;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;

final class IndustryMatchResearchProjector
{
    /** Taxonomy groups that qualify as Industry Groups (see IndustryGroupType). */
    private const TOPIC_RULE_GROUPS = ['generic_cores', 'service_intent_terms'];

    public function __construct(
        private readonly IndustryMatchRuleProvider $provider,
        private readonly IndustryMatchResearchKeyDeriver $keyDeriver = new IndustryMatchResearchKeyDeriver,
    ) {}

    /** @return list<MatchResearchResource> */
    public function project(?string $industryContextKey, string $sourceLocale = 'vi'): array
    {
        $rules = $this->provider->rulesForKey($industryContextKey);
        $provenance = $this->provider->provenanceForKey($industryContextKey) ?? [];
        if ($rules === [] && $provenance === []) {
            return [];
        }

        $resources = [];
        $taxonomyGroups = IndustryGroupType::values();
        foreach ([...$taxonomyGroups, ...self::TOPIC_RULE_GROUPS, 'aliases'] as $group) {
            $isTopicRule = in_array($group, self::TOPIC_RULE_GROUPS, true);
            $isAlias = $group === 'aliases';
            $isIndustryGroup = in_array($group, $taxonomyGroups, true);
            foreach ((array) ($rules[$group] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $canonical = trim((string) ($entry['canonical'] ?? ''));
                if ($canonical === '') {
                    continue;
                }
                $key = $this->keyDeriver->forEntity($group, $canonical, $sourceLocale);
                $resources[] = new MatchResearchResource(
                    key: $key,
                    origin: MatchResearchOrigin::Industry,
                    kind: MatchResearchKind::Concept,
                    sourceLocale: $sourceLocale,
                    label: $canonical,
                    description: $isTopicRule
                        ? 'Industry topic rule: '.$group
                        : ($isAlias
                            ? 'Industry alias group'
                            : ($isIndustryGroup ? 'Industry Group ('.$group.')' : 'Industry taxonomy: '.$group)),
                    matchMode: isset($entry['match_mode']) ? (string) $entry['match_mode'] : 'phrase',
                    payload: [
                        'group' => $group,
                        'name' => $canonical,
                        'aliases' => array_values(array_filter(array_map('strval', (array) ($entry['aliases'] ?? [])))),
                        'positive_examples' => [],
                        'negative_examples' => [],
                    ],
                    capabilities: new MatchResearchCapabilities(
                        canMatch: true,
                        // Industry Groups are concepts for future semantic evidence — not Keyword Tags.
                        canTag: false,
                        canExclude: $isTopicRule,
                        canRank: false,
                    ),
                    editable: false,
                    deletable: false,
                    provenance: array_merge($provenance, [
                        'group' => $group,
                        'industry_group' => $isIndustryGroup,
                    ]),
                );
            }
        }

        foreach ((array) ($rules['ambiguities'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $term = trim((string) ($entry['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $key = $this->keyDeriver->forAmbiguity($term, $sourceLocale);
            $resources[] = new MatchResearchResource(
                key: $key,
                origin: MatchResearchOrigin::Industry,
                kind: MatchResearchKind::Ambiguity,
                sourceLocale: $sourceLocale,
                label: $term,
                description: 'Industry ambiguity — do not treat as a tag.',
                matchMode: isset($entry['match_mode']) ? (string) $entry['match_mode'] : 'token',
                payload: [
                    'group' => 'ambiguities',
                    'name' => $term,
                    'aliases' => [],
                    'do_not_confuse_with' => array_values(array_filter(array_map('strval', (array) ($entry['do_not_confuse_with'] ?? [])))),
                    'positive_examples' => [],
                    'negative_examples' => [],
                ],
                capabilities: new MatchResearchCapabilities(
                    canMatch: true,
                    canTag: false,
                    canExclude: true,
                    canRank: false,
                ),
                editable: false,
                deletable: false,
                provenance: array_merge($provenance, ['group' => 'ambiguities']),
            );
        }

        return $resources;
    }
}
