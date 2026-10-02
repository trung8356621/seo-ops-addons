<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchRules;

use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;

final class IndustryMatchRuntime
{
    public const GROUPS = ['products', 'product_families', 'materials', 'services', 'audiences', 'use_cases', 'features', 'adjacent_products', 'generic_cores', 'service_intent_terms', 'aliases', 'ambiguities'];

    public function __construct(private readonly IndustryMatchRuleProvider $provider, private readonly MatchRuleMatcher $matcher) {}

    public function rulesForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        try {
            return $this->provider->rulesForSite($siteId);
        } catch (\Throwable) {
            return [];
        }
    }

    public function entriesForSite(int $siteId, array $groups): array
    {
        $rules = $this->rulesForSite($siteId);
        $entries = [];
        foreach ($groups as $group) {
            if (! in_array($group, self::GROUPS, true)) {
                continue;
            }
            foreach ((array) ($rules[$group] ?? []) as $entry) {
                if (is_array($entry) && trim((string) ($entry['canonical'] ?? '')) !== '') {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    public function matchingEntries(int $siteId, array $groups, string $phrase): array
    {
        return $this->matcher->matchingEntries($this->entriesForSite($siteId, $groups), $phrase);
    }

    public function termsForSite(int $siteId, array $groups): array
    {
        $terms = [];
        foreach ($this->entriesForSite($siteId, $groups) as $entry) {
            foreach ([(string) $entry['canonical'], ...array_map('strval', (array) ($entry['aliases'] ?? []))] as $term) {
                if (trim($term) !== '') {
                    $terms[] = $term;
                }
            }
        }

        return array_values(array_unique($terms));
    }
}
