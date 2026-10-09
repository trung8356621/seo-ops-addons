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
        $global = $this->evaluator->evaluate($message, $this->enabled($this->config->globalGroups()));
        $diagnostics = ['global' => $this->trace($global), 'attempts' => []];
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
            if (! SemanticOperationRegistry::knownModule($module)) {
                $diagnostics['attempts'][] = ['module' => $module, 'status' => 'not_applicable'];
                continue;
            }
            $internal = $this->evaluator->evaluate($message, $this->enabled($this->config->moduleGroups($module)));
            $diagnostics['attempts'][] = ['module' => $module, 'status' => $internal->status, 'winner' => $internal->winner];
            if ($internal->status !== 'confident' || $internal->winner === null) {
                continue;
            }
            $operation = SemanticOperationRegistry::operation($internal->winner);
            if ($operation === null) {
                continue;
            }
            $diagnostics['internal'] = $this->trace($internal);
            $diagnostics['module'] = $module;
            $diagnostics['operation'] = $internal->winner;
            $diagnostics['family'] = $operation['family'];

            return $this->fromOperation($module, $internal->winner, $operation, $internal, $diagnostics);
        }

        return $this->unresolved('none', $global, $diagnostics);
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
        if (! is_string($capability) || ! $this->authority->accepts($capability)) {
            return new LocalToolRoute('rejected', is_string($capability) ? $capability : $operationRef, $internal->candidates[0]['score'] ?? null, false, $matches, 'weighted', $operation['family'], $module, null, false, [], $diagnostics);
        }
        if (! AgentCapabilityCatalog::isAvailable($capability)) {
            return new LocalToolRoute('rejected', $capability, $internal->candidates[0]['score'] ?? null, false, $matches, 'weighted', $operation['family'], $module, null, false, [], $diagnostics);
        }

        $secondary = [];
        foreach ((array) ($operation['secondary'] ?? []) as $key) {
            if (is_string($key) && $this->authority->accepts($key)) {
                $secondary[] = $key;
            }
        }

        return new LocalToolRoute(
            'confident',
            $capability,
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
