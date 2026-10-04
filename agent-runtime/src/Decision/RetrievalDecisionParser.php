<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

use InvalidArgumentException;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseTemplateCatalog;

final class RetrievalDecisionParser
{
    /** @var list<string> */
    private const MODULES = ['site', 'articles', 'internal_links', 'external_links', 'keywords', 'topics', 'content_projects', 'gsc'];

    /** @var list<string> */
    private const PARAMETERS = ['period', 'task', 'limit_min', 'limit_max', 'article_ref', 'topic_ref'];

    public function parse(string $raw): RetrievalDecision
    {
        $decoded = $this->decodeObject($raw);
        $intent = trim((string) ($decoded['intent'] ?? ''));
        if ($intent === '') {
            throw new InvalidArgumentException('Retrieval decision is missing intent.');
        }

        $isScoreLegacy = ! array_key_exists('primary_capability', $decoded) && ! array_key_exists('capabilities', $decoded) && isset($decoded['needs']);
        $isModuleLegacy = ! array_key_exists('primary_capability', $decoded) && ! array_key_exists('capabilities', $decoded) && (array_key_exists('primary_module', $decoded) || array_key_exists('modules', $decoded));
        $isLegacyDecision = $isScoreLegacy || $isModuleLegacy;
        if (! $isScoreLegacy && (! array_key_exists('is_in_scope', $decoded) || ! is_bool($decoded['is_in_scope']))) {
            throw new InvalidArgumentException('Retrieval decision is_in_scope is required and must be boolean.');
        }
        $isInScope = $isScoreLegacy ? true : $decoded['is_in_scope'];

        if ($isLegacyDecision) {
            [$primaryModule, $modules] = $this->legacyModules($decoded, $isInScope, $isScoreLegacy);
            $capabilities = array_values(array_unique(array_filter(array_map(
                static fn (string $module): ?string => AgentCapabilityCatalog::fromLegacyModule($module),
                $modules,
            ))));
            $primaryCapability = $primaryModule === null ? null : AgentCapabilityCatalog::fromLegacyModule($primaryModule);
        } else {
            [$primaryCapability, $capabilities] = $this->capabilities($decoded, $isInScope);
            $modules = AgentCapabilityCatalog::modulesFor($capabilities);
            $primaryModule = $primaryCapability === null
                ? null
                : (AgentCapabilityCatalog::get($primaryCapability)['modules'][0] ?? null);
        }

        $parameters = [];
        $paramsRaw = $decoded['parameters'] ?? [];
        if (! is_array($paramsRaw)) {
            throw new InvalidArgumentException('Retrieval decision parameters must be an object.');
        }
        foreach ($paramsRaw as $key => $value) {
            $key = trim((string) $key);
            if (! in_array($key, self::PARAMETERS, true) || is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('Retrieval decision parameters must be scalar.');
            }
            $parameters[$key] = is_string($value) ? trim($value) : $value;
        }
        $this->validateParameters($parameters);

        $responseTemplate = trim((string) ($decoded['response_template'] ?? ($isScoreLegacy ? 'text' : '')));
        if (! AgentResponseTemplateCatalog::supports($responseTemplate)) {
            throw new InvalidArgumentException('Retrieval decision response_template is missing or unknown.');
        }

        $requiresParameterExtraction = (bool) ($decoded['requires_parameter_extraction'] ?? false);
        $requiresUserConfirmation = (bool) ($decoded['requires_user_confirmation'] ?? false);
        if (! $isInScope && ($parameters !== [] || $requiresParameterExtraction || $requiresUserConfirmation || $responseTemplate !== 'text')) {
            throw new InvalidArgumentException('Out-of-scope retrieval decision must use the canonical no-retrieval text shape.');
        }

        return new RetrievalDecision(
            isInScope: $isInScope,
            intent: $intent,
            primaryCapability: $primaryCapability,
            capabilities: $capabilities,
            primaryModule: $primaryModule,
            modules: $modules,
            parameters: $parameters,
            requiresParameterExtraction: $requiresParameterExtraction,
            requiresUserConfirmation: $requiresUserConfirmation,
            responseTemplate: $responseTemplate,
        );
    }

    /** @param array<string, mixed> $decoded @return array{string|null, list<string>} */
    private function capabilities(array $decoded, bool $isInScope): array
    {
        $primaryRaw = $decoded['primary_capability'] ?? null;
        $primary = $primaryRaw === null ? null : trim((string) $primaryRaw);
        $capabilities = $decoded['capabilities'] ?? null;
        if (! is_array($capabilities) || ! array_is_list($capabilities)) {
            throw new InvalidArgumentException('Retrieval decision capabilities are invalid.');
        }
        $normalized = array_map(static fn (mixed $value): string => trim((string) $value), $capabilities);
        if ($normalized === []) {
            if ($primary !== null) {
                throw new InvalidArgumentException('A no-capability decision must use a null primary_capability.');
            }

            return [null, []];
        }
        if (! $isInScope || count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException('Retrieval decision capabilities must be a valid unique list.');
        }
        foreach ($normalized as $capability) {
            if (! AgentCapabilityCatalog::isSelectable($capability)) {
                throw new InvalidArgumentException('Retrieval decision contains an unknown or hidden capability.');
            }
        }
        if ($primary === null || ! AgentCapabilityCatalog::isSelectable($primary) || ! in_array($primary, $normalized, true)) {
            throw new InvalidArgumentException('primary_capability must be selectable and included in capabilities.');
        }

        return [$primary, $normalized];
    }

    /** @param array<string, mixed> $decoded @return array{string|null, list<string>} */
    private function legacyModules(array $decoded, bool $isInScope, bool $isScoreLegacy): array
    {
        if (! $isScoreLegacy) {
            $primaryRaw = $decoded['primary_module'] ?? null;
            $primary = $primaryRaw === null ? null : trim((string) $primaryRaw);
            $modules = $decoded['modules'] ?? null;
            if (! is_array($modules) || ! array_is_list($modules)) {
                throw new InvalidArgumentException('Retrieval decision modules are invalid.');
            }
            $normalized = array_map(static fn (mixed $v): string => trim((string) $v), $modules);
            if ($normalized === []) {
                if ($primary !== null) {
                    throw new InvalidArgumentException('A no-retrieval decision must use a null primary_module.');
                }

                return [null, []];
            }
            if (! $isInScope || ! in_array($primary, self::MODULES, true) || count($normalized) !== count(array_unique($normalized))) {
                throw new InvalidArgumentException('Retrieval decision modules must be a valid unique list.');
            }
            foreach ($normalized as $module) {
                if (! in_array($module, self::MODULES, true)) {
                    throw new InvalidArgumentException('Retrieval decision contains an unknown module.');
                }
            }
            if (! in_array($primary, $normalized, true)) {
                throw new InvalidArgumentException('primary_module must be included in modules.');
            }

            return [$primary, $normalized];
        }

        // Transitional normalization for persisted/in-flight legacy decisions.
        $needs = $decoded['needs'] ?? null;
        if (! is_array($needs) || $needs === []) {
            throw new InvalidArgumentException('Retrieval decision is missing modules.');
        }
        $scores = [];
        foreach ($needs as $name => $score) {
            $name = trim((string) $name);
            if (! in_array($name, self::MODULES, true) || ! is_numeric($score) || (float) $score < 0 || (float) $score > 1) {
                throw new InvalidArgumentException('Retrieval decision legacy needs are invalid.');
            }
            $scores[$name] = (float) $score;
        }
        arsort($scores);
        $primary = (string) array_key_first($scores);
        $modules = array_keys(array_filter($scores, static fn (float $score): bool => $score >= 0.5));
        if (! in_array($primary, $modules, true)) {
            array_unshift($modules, $primary);
        }

        return [$primary, array_values(array_unique($modules))];
    }

    /** @param array<string, scalar|null> $parameters */
    private function validateParameters(array $parameters): void
    {
        if (isset($parameters['period']) && (! is_string($parameters['period']) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $parameters['period']) !== 1)) {
            throw new InvalidArgumentException('Retrieval decision period must be YYYY-MM.');
        }
        foreach (['limit_min', 'limit_max'] as $key) {
            if (isset($parameters[$key]) && (! is_int($parameters[$key]) || $parameters[$key] < 1 || $parameters[$key] > 100)) {
                throw new InvalidArgumentException($key.' must be an integer between 1 and 100.');
            }
        }
        if (isset($parameters['limit_min'], $parameters['limit_max']) && $parameters['limit_min'] > $parameters['limit_max']) {
            throw new InvalidArgumentException('limit_min must not exceed limit_max.');
        }
        if (isset($parameters['article_ref']) && (! is_string($parameters['article_ref']) || preg_match('/^article:[1-9]\d*$/', $parameters['article_ref']) !== 1)) {
            throw new InvalidArgumentException('article_ref is invalid.');
        }
        if (isset($parameters['topic_ref']) && (! is_string($parameters['topic_ref']) || preg_match('/^topic:[1-9]\d*$/', $parameters['topic_ref']) !== 1)) {
            throw new InvalidArgumentException('topic_ref is invalid.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $match) === 1) {
            $raw = $match[1];
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new InvalidArgumentException('Retrieval decision is not a JSON object.');
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Retrieval decision JSON is invalid.');
        }

        return $decoded;
    }
}
