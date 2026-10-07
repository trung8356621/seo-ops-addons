<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MatchRules;

use Omnichannel\Addons\SearchFoundation\Contracts\MatchResearch\SystemMatchResearchSource;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchCapabilities;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;

/**
 * Projects legacy GlobalMatchRuleRegistry + SeoKeywordSettingsService into Match & Research registry.
 * Preserves existing keys/values for CTA and all global rule consumers.
 */
final class SystemMatchResearchAdapter implements SystemMatchResearchSource
{
    public const SOURCE_LOCALE = 'vi';

    public function __construct(
        private readonly ?GlobalMatchRuleRegistry $registry = null,
        private readonly ?SeoKeywordSettingsService $settings = null,
    ) {}

    public function resources(): array
    {
        $definitions = ($this->registry ?? new GlobalMatchRuleRegistry)->definitions();
        $values = ($this->settings ?? new SeoKeywordSettingsService($this->registry))->globalMatchRules();
        $resources = [];
        foreach ($definitions as $legacyKey => $definition) {
            $resources[] = $this->project($legacyKey, $definition, $values[$legacyKey] ?? $definition['defaults']);
        }

        return $resources;
    }

    public function find(string $key): ?MatchResearchResource
    {
        $legacyKey = str_starts_with($key, 'system.') ? substr($key, strlen('system.')) : $key;
        $definitions = ($this->registry ?? new GlobalMatchRuleRegistry)->definitions();
        if (! isset($definitions[$legacyKey])) {
            return null;
        }
        $values = ($this->settings ?? new SeoKeywordSettingsService($this->registry))->globalMatchRules();

        return $this->project($legacyKey, $definitions[$legacyKey], $values[$legacyKey] ?? $definitions[$legacyKey]['defaults']);
    }

    /**
     * @param  array{key:string,label:string,scope:string,description:string,match_mode:string,editable:bool,defaults:list<string>}  $definition
     * @param  list<string>  $terms
     */
    private function project(string $legacyKey, array $definition, array $terms): MatchResearchResource
    {
        return new MatchResearchResource(
            key: 'system.'.$legacyKey,
            origin: MatchResearchOrigin::System,
            kind: MatchResearchKind::RuleSet,
            sourceLocale: self::SOURCE_LOCALE,
            label: (string) $definition['label'],
            description: (string) $definition['description'],
            matchMode: (string) $definition['match_mode'],
            payload: [
                'name' => (string) $definition['label'],
                'description' => (string) $definition['description'],
                'terms' => array_values($terms),
                'legacy_key' => $legacyKey,
            ],
            capabilities: $this->capabilitiesFor($legacyKey),
            editable: (bool) $definition['editable'],
            deletable: false,
            provenance: [
                'legacy_key' => $legacyKey,
                'storage' => SeoKeywordSettingsService::OPTION_KEY,
            ],
        );
    }

    private function capabilitiesFor(string $legacyKey): MatchResearchCapabilities
    {
        $isLinkStop = str_starts_with($legacyKey, 'link_');
        $isCta = str_starts_with($legacyKey, 'cta_');
        $isTopic = str_starts_with($legacyKey, 'topic_') || in_array($legacyKey, ['discourse_prefixes', 'sentence_hints'], true);

        return new MatchResearchCapabilities(
            canMatch: true,
            canTag: false,
            canExclude: $isLinkStop || $isCta || $isTopic || in_array($legacyKey, [
                'marketing_terms', 'location_terms', 'question_terms',
            ], true),
            canRank: false,
        );
    }
}
