<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;

/**
 * Global JEV picks a module. Internal JEV picks one READ or IMPROVE operation.
 * Soft preferences come from SemanticRoutingConfig. This class does not score text.
 */
final class LocalAgentToolRouter
{
    public function __construct(
        private readonly WeightedRouteEvaluator $evaluator,
        private readonly SemanticRoutingConfig $config = new SemanticRoutingConfig(),
        private readonly CatalogToolRouteAuthority $authority = new CatalogToolRouteAuthority(),
    ) {}

    public function route(string $message): LocalToolRoute
    {
        $document = $this->config->document();
        $routingVersion = (string) ($document['revision'] ?? '1').':'.hash(
            'sha256',
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
        if ($this->evaluator instanceof HybridRouteEvaluator) {
            return $this->routeHybrid($message, $this->evaluator->evaluateHybrid($message, $document), $routingVersion);
        }
        $global = $this->evaluator->evaluate($message, $this->enabled($this->config->globalGroups()));
        $diagnostics = ['global' => $this->trace($global), 'attempts' => [], 'routing_version' => $routingVersion];
        if ($global->status === 'unavailable') {
            return $this->unresolved('unavailable', $global, $diagnostics);
        }
        if ($global->status !== 'confident' || $global->winner === null) {
            return $this->unresolved($global->status === 'ambiguous' ? 'ambiguous' : 'none', $global, $diagnostics);
        }

        $modules = [$global->winner];
        foreach ($global->candidates as $candidate) {
            if ($candidate['ref'] !== $global->winner && ! in_array($candidate['ref'], $modules, true)) {
                $modules[] = $candidate['ref'];
            }
        }

        foreach (array_slice($modules, 0, 2) as $module) {
            if (! $this->config->knownModule($module)) {
                $diagnostics['attempts'][] = ['module' => $module, 'status' => 'not_applicable'];
                continue;
            }
            $internal = $this->evaluator->evaluate($message, $this->enabled($this->config->moduleGroups($module)));
            $diagnostics['attempts'][] = ['module' => $module, 'status' => $internal->status, 'winner' => $internal->winner];
            if ($internal->status !== 'confident' || $internal->winner === null) {
                continue;
            }
            $operation = $this->config->operation($internal->winner);
            if ($operation === null) {
                continue;
            }
            $diagnostics['internal'] = $this->trace($internal);
            $diagnostics['module'] = $module;
            $diagnostics['operation'] = $internal->winner;
            $diagnostics['family'] = $operation['family'];
            $diagnostics['service_id'] = $operation['service_id'] ?? null;

            return $this->fromOperation($module, $internal->winner, $operation, $internal, $diagnostics);
        }

        return $this->unresolved('none', $global, $diagnostics);
    }

    /** @param array<string, mixed> $result */
    private function routeHybrid(string $message, array $result, string $routingVersion): LocalToolRoute
    {
        $status = (string) ($result['status'] ?? 'unavailable');
        $diagnostics = [
            'global' => ['candidates' => (array) ($result['global_candidates'] ?? [])],
            'internal' => ['candidates' => (array) ($result['operation_candidates'] ?? [])],
            'decision_reason' => (string) ($result['reason'] ?? ''),
            'routing_version' => $routingVersion,
        ];
        if ($status !== 'confident') {
            if ($status === 'ambiguous') {
                $disambiguated = $this->resolveCrossFamilyAmbiguity($message, $result, $diagnostics);
                if ($disambiguated instanceof LocalToolRoute) {
                    return $disambiguated;
                }
                $entityResolved = $this->resolveEntityAmbiguity($message, $result, $diagnostics);
                if ($entityResolved instanceof LocalToolRoute) {
                    return $entityResolved;
                }
                $clarification = $this->buildAmbiguityClarification($result);
                if ($clarification !== null) {
                    $diagnostics['clarification'] = $clarification;
                }
            }
            $outcome = in_array($status, ['none', 'ambiguous', 'unsupported', 'unavailable'], true) ? $status : 'unavailable';
            return new LocalToolRoute($outcome, null, null, false, [], 'hybrid_weighted', null, null, null, false, [], $diagnostics);
        }
        $module = (string) ($result['module'] ?? '');
        $operationRef = (string) ($result['operation'] ?? '');
        $operation = $this->config->operation($operationRef);
        if (! $this->config->knownModule($module) || $operation === null) {
            return new LocalToolRoute('unsupported', null, null, false, [], 'hybrid_weighted', null, $module ?: null, null, false, [], $diagnostics);
        }
        $diagnostics['module'] = $module;
        $diagnostics['operation'] = $operationRef;
        $diagnostics['service_id'] = $operation['service_id'] ?? null;
        $internal = new WeightedEvaluation('confident', $operationRef, array_map(static fn (array $row): array => [
            'ref' => (string) ($row['operation'] ?? ''),
            'semantic_relevance' => (float) ($row['internal_semantic_score'] ?? 0),
            'weight' => 100.0,
            'score' => (float) ($row['internal_semantic_score'] ?? 0),
            'group_id' => (string) ($row['group_id'] ?? ''),
            'example' => (string) ($row['example'] ?? ''),
        ], array_values(array_filter((array) ($result['operation_candidates'] ?? []), 'is_array'))));

        return $this->fromOperation($module, $operationRef, $operation, $internal, $diagnostics);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $diagnostics
     */
    private function resolveCrossFamilyAmbiguity(string $message, array $result, array &$diagnostics): ?LocalToolRoute
    {
        $candidates = array_values(array_filter((array) ($result['operation_candidates'] ?? []), 'is_array'));
        if (count($candidates) < 2) {
            return null;
        }

        $scoreKey = isset($candidates[0]['internal_semantic_score']) ? 'internal_semantic_score' : 'score';
        $top1 = $candidates[0];
        $top2 = $candidates[1];
        $score1 = (float) ($top1[$scoreKey] ?? 0);
        $score2 = (float) ($top2[$scoreKey] ?? 0);

        if (abs($score1 - $score2) > 0.08) {
            return null;
        }

        $op1 = $this->config->operation((string) ($top1['operation'] ?? ''));
        $op2 = $this->config->operation((string) ($top2['operation'] ?? ''));
        if ($op1 === null || $op2 === null) {
            return null;
        }

        $family1 = (string) ($op1['family'] ?? '');
        $family2 = (string) ($op2['family'] ?? '');

        // Preserve genuine ambiguity within the same family.
        if ($family1 === $family2) {
            return null;
        }

        $hasReadIntent = (bool) preg_match('/(?:cho tôi xem|danh sách|liệt kê|thống kê|hiển thị|xem|kiểm tra|kho|bộ sưu tập|\blist\b|\bshow\b|\bview\b|\bdisplay\b|\binventory\b)/iu', $message);
        $hasImproveIntent = (bool) preg_match('/(?:đề xuất|gợi ý|cải thiện|tối ưu|hướng dẫn|nâng cao|sửa|\bimprove\b|\bsuggest\b|\brecommend\b|\boptimize\b)/iu', $message);

        $targetFamily = null;
        if ($hasReadIntent && ! $hasImproveIntent) {
            $targetFamily = 'READ';
        } elseif ($hasImproveIntent && ! $hasReadIntent) {
            $targetFamily = 'IMPROVE';
        }

        if ($targetFamily === null) {
            return null;
        }

        $chosen = null;
        $chosenOp = null;
        if ($family1 === $targetFamily) {
            $chosen = $top1;
            $chosenOp = $op1;
        } elseif ($family2 === $targetFamily) {
            $chosen = $top2;
            $chosenOp = $op2;
        }

        if ($chosen === null || $chosenOp === null) {
            return null;
        }

        $opRef = (string) ($chosen['operation'] ?? '');
        $module = explode('.', $opRef)[0] ?? '';
        if (! $this->config->knownModule($module)) {
            $module = (string) ($result['module'] ?? '');
        }

        $internal = new WeightedEvaluation('confident', $opRef, array_map(static fn (array $row): array => [
            'ref' => (string) ($row['operation'] ?? ''),
            'semantic_relevance' => (float) ($row['internal_semantic_score'] ?? 0),
            'weight' => 100.0,
            'score' => (float) ($row['internal_semantic_score'] ?? 0),
            'group_id' => (string) ($row['group_id'] ?? ''),
            'example' => (string) ($row['example'] ?? ''),
        ], $candidates));

        $diagnostics['module'] = $module;
        $diagnostics['operation'] = $opRef;
        $diagnostics['service_id'] = $chosenOp['service_id'] ?? null;
        $diagnostics['cross_family_disambiguation'] = [
            'target_family' => $targetFamily,
            'competing_families' => [$family1, $family2],
            'resolved_operation' => $opRef,
        ];

        return $this->fromOperation($module, $opRef, $chosenOp, $internal, $diagnostics);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $diagnostics
     */
    private function resolveEntityAmbiguity(string $message, array $result, array &$diagnostics): ?LocalToolRoute
    {
        $candidates = array_values(array_filter((array) ($result['operation_candidates'] ?? []), 'is_array'));
        if (count($candidates) < 2) {
            return null;
        }

        $minScore = (float) ($this->config->policy()['min_operation_score'] ?? 0.62);
        $margin = (float) ($this->config->policy()['final_margin'] ?? 0.08);

        $scoreKey = isset($candidates[0]['internal_semantic_score']) ? 'internal_semantic_score' : 'score';
        $top1 = $candidates[0];
        $top2 = $candidates[1];
        $score1 = (float) ($top1[$scoreKey] ?? 0);
        $score2 = (float) ($top2[$scoreKey] ?? 0);

        // Gate 2: Candidate scores satisfy existing minimum evidence requirements
        if ($score1 < $minScore || $score2 < $minScore) {
            return null;
        }

        // Gate 3: Competing operations are within configured ambiguity margin
        if (abs($score1 - $score2) > $margin) {
            return null;
        }

        $op1 = $this->config->operation((string) ($top1['operation'] ?? ''));
        $op2 = $this->config->operation((string) ($top2['operation'] ?? ''));
        if ($op1 === null || $op2 === null) {
            return null;
        }

        $family1 = (string) ($op1['family'] ?? '');
        $family2 = (string) ($op2['family'] ?? '');

        // Same-family gate: entity disambiguation strictly resolves same-family ambiguity
        if ($family1 === '' || $family1 !== $family2) {
            return null;
        }

        $entitiesConfig = $this->config->entities();
        if ($entitiesConfig === []) {
            return null;
        }

        $competing = [];
        foreach ($candidates as $candidate) {
            $score = (float) ($candidate[$scoreKey] ?? 0);
            if ($score >= $minScore && ($score1 - $score) <= $margin) {
                $op = $this->config->operation((string) ($candidate['operation'] ?? ''));
                if (($op['family'] ?? null) !== $family1) {
                    return null;
                }
                $competing[] = $candidate;
            }
        }

        if (count($competing) < 2) {
            return null;
        }

        $entityMap = [];
        foreach ($competing as $candidate) {
            $ref = (string) ($candidate['operation'] ?? '');
            $entity = $this->config->operationEntity($ref);
            if ($entity !== null) {
                $entityMap[$ref] = $entity;
            }
        }

        $distinctEntities = array_values(array_unique(array_values($entityMap)));
        if (count($distinctEntities) < 2) {
            return null;
        }

        // Gate 4: Explicit user wording uniquely identifies one entity type
        $matchedEntities = [];
        foreach ($distinctEntities as $entity) {
            $phrases = $entitiesConfig[$entity]['phrases'] ?? [];
            if ($this->matchesEntityPhrases($message, $phrases)) {
                $matchedEntities[] = $entity;
            }
        }

        if (count($matchedEntities) !== 1) {
            return null;
        }

        $targetEntity = $matchedEntities[0];

        // Gate 5: Exactly one eligible candidate matches that entity evidence
        $matchingCandidates = [];
        foreach ($competing as $candidate) {
            $ref = (string) ($candidate['operation'] ?? '');
            if (($entityMap[$ref] ?? null) === $targetEntity) {
                $matchingCandidates[] = $candidate;
            }
        }

        if (count($matchingCandidates) !== 1) {
            return null;
        }

        $chosen = $matchingCandidates[0];
        $opRef = (string) ($chosen['operation'] ?? '');
        $chosenOp = $this->config->operation($opRef);
        if ($chosenOp === null) {
            return null;
        }

        $module = explode('.', $opRef)[0] ?? '';
        if (! $this->config->knownModule($module)) {
            $module = (string) ($result['module'] ?? '');
        }

        $internal = new WeightedEvaluation('confident', $opRef, array_map(static fn (array $row): array => [
            'ref' => (string) ($row['operation'] ?? ''),
            'semantic_relevance' => (float) ($row['internal_semantic_score'] ?? 0),
            'weight' => 100.0,
            'score' => (float) ($row['internal_semantic_score'] ?? 0),
            'group_id' => (string) ($row['group_id'] ?? ''),
            'example' => (string) ($row['example'] ?? ''),
        ], $candidates));

        $diagnostics['module'] = $module;
        $diagnostics['operation'] = $opRef;
        $diagnostics['service_id'] = $chosenOp['service_id'] ?? null;
        $diagnostics['entity_disambiguation'] = [
            'target_entity' => $targetEntity,
            'competing_entities' => $distinctEntities,
            'resolved_operation' => $opRef,
        ];

        // Gate 6: Existing capability availability/authorization still approves execution
        return $this->fromOperation($module, $opRef, $chosenOp, $internal, $diagnostics);
    }

    /** @param list<string> $phrases */
    private function matchesEntityPhrases(string $message, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($phrase === '') {
                continue;
            }
            $quoted = preg_quote($phrase, '/');
            $pattern = '/(?:\b|(?<=^|[\s,.\-]))'.$quoted.'(?:\b|(?=$|[\s,.\-]))/iu';
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{vi: string, en: string}|null
     */
    private function buildAmbiguityClarification(array $result): ?array
    {
        $candidates = array_values(array_filter((array) ($result['operation_candidates'] ?? []), 'is_array'));
        if (count($candidates) < 2) {
            return null;
        }

        $minScore = (float) ($this->config->policy()['min_operation_score'] ?? 0.62);
        $margin = (float) ($this->config->policy()['final_margin'] ?? 0.08);
        $scoreKey = isset($candidates[0]['internal_semantic_score']) ? 'internal_semantic_score' : 'score';
        $score1 = (float) ($candidates[0][$scoreKey] ?? 0);

        $competing = [];
        foreach ($candidates as $candidate) {
            $score = (float) ($candidate[$scoreKey] ?? 0);
            if ($score >= $minScore && ($score1 - $score) <= $margin) {
                $competing[] = $candidate;
            }
        }

        if (count($competing) < 2) {
            return null;
        }

        $op1Ref = (string) ($competing[0]['operation'] ?? '');
        $op2Ref = (string) ($competing[1]['operation'] ?? '');
        $op1 = $this->config->operation($op1Ref);
        $op2 = $this->config->operation($op2Ref);
        if ($op1 === null || $op2 === null) {
            return null;
        }

        $fam1 = (string) ($op1['family'] ?? '');
        $fam2 = (string) ($op2['family'] ?? '');

        if ($fam1 === 'READ' && $fam2 === 'READ') {
            $label1Vi = $this->config->operationLabel($op1Ref, 'vi') ?? $this->entityFallbackLabel($op1, 'vi');
            $label2Vi = $this->config->operationLabel($op2Ref, 'vi') ?? $this->entityFallbackLabel($op2, 'vi');
            $label1En = $this->config->operationLabel($op1Ref, 'en') ?? $this->entityFallbackLabel($op1, 'en');
            $label2En = $this->config->operationLabel($op2Ref, 'en') ?? $this->entityFallbackLabel($op2, 'en');

            if ($label1Vi !== null && $label2Vi !== null && $label1Vi !== $label2Vi) {
                $firstVi = $label2Vi;
                $secondVi = $label1Vi;
                $firstEn = $label2En ?? $label2Vi;
                $secondEn = $label1En ?? $label1Vi;
                if (str_contains(mb_strtolower($label1Vi), 'topic')) {
                    $firstVi = $label1Vi;
                    $secondVi = $label2Vi;
                    $firstEn = $label1En ?? $label1Vi;
                    $secondEn = $label2En ?? $label2Vi;
                }

                return [
                    'vi' => "Bạn muốn xem {$firstVi} hay {$secondVi}?",
                    'en' => "Do you want to see the {$firstEn} or the {$secondEn}?",
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $operation */
    private function entityFallbackLabel(array $operation, string $language): ?string
    {
        $entity = (string) ($operation['entity'] ?? '');
        if ($entity === '') {
            return null;
        }
        $entities = $this->config->entities();
        $label = $entities[$entity]['label'][$language] ?? $entities[$entity]['label']['en'] ?? null;

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    /** @param list<array<string, mixed>> $groups @return list<array<string, mixed>> */
    private function enabled(array $groups): array
    {
        return array_values(array_filter($groups, static fn (array $group): bool => ($group['enabled'] ?? true) === true));
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $diagnostics */
    private function fromOperation(string $module, string $operationRef, array $operation, WeightedEvaluation $internal, array $diagnostics): LocalToolRoute
    {
        $matches = $this->matches($internal);
        $capability = $operation['capability'];
        $guidance = is_string($operation['guidance'] ?? null) ? $operation['guidance'] : null;
        if ($guidance !== null) {
            return new LocalToolRoute('confident', null, $internal->candidates[0]['score'] ?? null, false, $matches, 'weighted', $operation['family'], $module, $guidance, false, [], $diagnostics);
        }
        $declared = is_string($capability) ? $capability : null;
        if ($declared === null || ! AgentCapabilityCatalog::known($declared) || ! AgentCapabilityCatalog::isAvailable($declared)) {
            return new LocalToolRoute('unsupported', $declared ?? $operationRef, $internal->candidates[0]['score'] ?? null, false, $matches, 'weighted', $operation['family'], $module, null, false, [], $diagnostics);
        }
        if (! $this->authority->accepts($declared)) {
            return new LocalToolRoute('rejected', $declared, $internal->candidates[0]['score'] ?? null, false, $matches, 'weighted', $operation['family'], $module, null, false, [], $diagnostics);
        }

        $secondary = [];
        foreach ((array) ($operation['secondary'] ?? []) as $key) {
            if (is_string($key) && $this->authority->accepts($key)) {
                $secondary[] = $key;
            }
        }

        return new LocalToolRoute(
            'confident',
            $declared,
            $internal->candidates[0]['score'] ?? null,
            true,
            $matches,
            'weighted',
            (string) $operation['family'],
            $module,
            null,
            (bool) ($operation['answer_model'] ?? false),
            $secondary,
            $diagnostics,
        );
    }

    /** @param array<string, mixed> $diagnostics */
    private function unresolved(string $outcome, WeightedEvaluation $global, array $diagnostics): LocalToolRoute
    {
        return new LocalToolRoute($outcome, null, null, false, $this->matches($global), 'weighted', null, null, null, false, [], $diagnostics);
    }

    /** @return list<array{ref: string, score: float}> */
    private function matches(WeightedEvaluation $evaluation): array
    {
        $matches = [];
        foreach ($evaluation->candidates as $candidate) {
            $matches[] = ['ref' => $candidate['ref'], 'score' => $candidate['score']];
        }

        return $matches;
    }

    /** @return array<string, mixed> */
    private function trace(WeightedEvaluation $evaluation): array
    {
        return [
            'status' => $evaluation->status,
            'winner' => $evaluation->winner,
            'candidates' => $evaluation->candidates,
        ];
    }
}
