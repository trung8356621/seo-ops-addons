<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup;

use Omnichannel\Addons\SearchFoundation\Contracts\IndustryGroup\IndustryGroupProvider;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryMatchRuleProvider;
use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching\ConceptDecisionPolicy;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching\ConceptDefinition;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching\ConceptMatchEntity;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching\ConceptMatchingClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticDisabledException;

/**
 * Bridge: Industry Groups → Concept Matching API → typed evidence.
 *
 * Python is matching authority. No MatchRuleMatcher / local string fallback.
 * Transport health/alerts flow through DI-bound SemanticAnalyticsClient only.
 */
final class IndustryGroupSemanticMatcher
{
    public const MATCHING_STRATEGY = 'hybrid';

    /** V1: lexical evidence is authoritative; do not invent cosine membership. */
    public const SEMANTIC_FALLBACK = false;

    public const DEFAULT_MATCH_MODE = 'phrase';

    public function __construct(
        private readonly IndustryGroupProvider $industryGroups,
        private readonly ConceptMatchingClient $conceptMatching,
        private readonly ?IndustryMatchRuleProvider $matchRules = null,
    ) {}

    /**
     * @param  list<IndustryGroupMatchEntity>  $entities
     */
    public function match(
        string $scopeRef,
        array $entities,
        ?int $siteId = null,
        ?string $industryContextKey = null,
        ?string $locale = null,
    ): IndustryGroupMatchResult {
        if (! (bool) config('semantic.enabled', false)) {
            throw SemanticDisabledException::disabled();
        }

        if ($entities === []) {
            return IndustryGroupMatchResult::emptyNoGroups($scopeRef, 'no_entities');
        }

        $groups = $this->industryGroups->list($siteId, $industryContextKey, $locale);
        $active = [];
        $staleSkipped = [];
        $disabledSkipped = [];
        foreach ($groups as $group) {
            if (! $group->enabled) {
                $disabledSkipped[] = $group->key;

                continue;
            }
            if ($group->stale) {
                $staleSkipped[] = $group->key;

                continue;
            }
            $active[] = $group;
        }

        if ($active === []) {
            return new IndustryGroupMatchResult(
                scopeRef: $scopeRef,
                entities: array_map(
                    static fn (IndustryGroupMatchEntity $e): IndustryGroupEntityMatchResult => new IndustryGroupEntityMatchResult(
                        ref: $e->ref,
                        text: $e->text,
                        evidence: [],
                    ),
                    $entities,
                ),
                conceptsUsed: 0,
                staleGroupsSkipped: count($staleSkipped),
                disabledGroupsSkipped: count($disabledSkipped),
                calledPython: false,
                staleGroupKeysSkipped: $staleSkipped,
                disabledGroupKeysSkipped: $disabledSkipped,
                reason: $this->emptyReason($groups, $staleSkipped, $disabledSkipped, $industryContextKey),
            );
        }

        $groupTypeByKey = [];
        $concepts = [];
        foreach ($active as $group) {
            $groupTypeByKey[$group->key] = $group->groupType->value;
            $concepts[] = $this->toConceptDefinition($group);
        }

        $matchEntities = array_map(
            static fn (IndustryGroupMatchEntity $e): ConceptMatchEntity => new ConceptMatchEntity(
                ref: $e->ref,
                text: $e->text,
            ),
            $entities,
        );

        // Lexical-first Industry Group policy — no baked min_positive_score.
        $policy = new ConceptDecisionPolicy(semanticFallback: self::SEMANTIC_FALLBACK);

        $raw = $this->conceptMatching->analyzeBatched(
            scopeRef: $scopeRef,
            entities: $matchEntities,
            concepts: $concepts,
            language: $locale,
            decisionPolicy: $policy,
        );

        $entityResults = [];
        foreach ($raw->entities as $entityResult) {
            $evidence = [];
            foreach ($entityResult->concepts as $concept) {
                $evidence[] = new IndustryGroupMatchEvidence(
                    entityRef: $entityResult->ref,
                    industryGroupKey: $concept->key,
                    groupType: $groupTypeByKey[$concept->key] ?? null,
                    lexicalMatched: $concept->lexical->matched,
                    lexicalNegativeMatched: $concept->lexical->negativeMatched,
                    lexicalMatchedExamples: $concept->lexical->matchedExamples,
                    positiveMax: $concept->positiveMax,
                    positiveTopKMean: $concept->positiveTopKMean,
                    negativeMax: $concept->negativeMax,
                    margin: $concept->margin,
                    bestPositiveExample: $concept->bestPositiveExample,
                    bestNegativeExample: $concept->bestNegativeExample,
                    suggestedMatch: $concept->suggestedMatch,
                    matchingStrategy: $concept->matchingStrategy,
                );
            }
            $entityResults[] = new IndustryGroupEntityMatchResult(
                ref: $entityResult->ref,
                text: $entityResult->text,
                evidence: $evidence,
            );
        }

        return new IndustryGroupMatchResult(
            scopeRef: $scopeRef,
            entities: $entityResults,
            conceptsUsed: count($concepts),
            staleGroupsSkipped: count($staleSkipped),
            disabledGroupsSkipped: count($disabledSkipped),
            calledPython: true,
            analysisId: $raw->analysisId,
            staleGroupKeysSkipped: $staleSkipped,
            disabledGroupKeysSkipped: $disabledSkipped,
        );
    }

    /**
     * @param  list<IndustryGroup>  $groups
     * @param  list<string>  $staleSkipped
     * @param  list<string>  $disabledSkipped
     */
    private function emptyReason(array $groups, array $staleSkipped, array $disabledSkipped, ?string $industryContextKey): string
    {
        if ($groups !== []) {
            if ($staleSkipped !== [] && $disabledSkipped === []) {
                return 'match_revision_stale';
            }

            return 'no_active_industry_groups';
        }

        $status = $this->matchRules?->statusForKey($industryContextKey) ?? 'no_industry_groups';

        return in_array($status, ['no_match_revision', 'match_revision_inactive', 'match_revision_stale', 'no_taxonomy_groups'], true)
            ? $status
            : 'no_industry_groups';
    }

    private function toConceptDefinition(IndustryGroup $group): ConceptDefinition
    {
        $definition = $group->semanticDefinition();
        $matchMode = trim((string) ($definition->matchMode ?? ''));
        if ($matchMode === '') {
            $matchMode = self::DEFAULT_MATCH_MODE;
        }

        return new ConceptDefinition(
            key: $definition->key,
            positiveExamples: $definition->positiveExamples,
            negativeExamples: $definition->negativeExamples,
            matchMode: $matchMode,
            matchingStrategy: self::MATCHING_STRATEGY,
        );
    }
}
