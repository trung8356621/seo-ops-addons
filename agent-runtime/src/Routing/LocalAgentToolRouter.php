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
