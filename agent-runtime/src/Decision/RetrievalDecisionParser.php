<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

use InvalidArgumentException;
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

        [$primaryModule, $modules] = $this->modules($decoded);

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

        $isLegacyDecision = ! isset($decoded['primary_module'], $decoded['modules']) && isset($decoded['needs']);
        $responseTemplate = trim((string) ($decoded['response_template'] ?? ($isLegacyDecision ? 'text' : '')));
        if (! AgentResponseTemplateCatalog::supports($responseTemplate)) {
            throw new InvalidArgumentException('Retrieval decision response_template is missing or unknown.');
        }

        return new RetrievalDecision(
            intent: $intent,
            primaryModule: $primaryModule,
            modules: $modules,
            parameters: $parameters,
            requiresParameterExtraction: (bool) ($decoded['requires_parameter_extraction'] ?? false),
            requiresUserConfirmation: (bool) ($decoded['requires_user_confirmation'] ?? false),
            responseTemplate: $responseTemplate,
        );
    }

    /** @param array<string, mixed> $decoded @return array{string, list<string>} */
    private function modules(array $decoded): array
    {
        if (isset($decoded['primary_module']) || isset($decoded['modules'])) {
            $primary = trim((string) ($decoded['primary_module'] ?? ''));
            $modules = $decoded['modules'] ?? null;
            if (! in_array($primary, self::MODULES, true) || ! is_array($modules) || ! array_is_list($modules)) {
                throw new InvalidArgumentException('Retrieval decision modules are invalid.');
            }
            $normalized = array_map(static fn (mixed $v): string => trim((string) $v), $modules);
            if ($normalized === [] || count($normalized) !== count(array_unique($normalized))) {
                throw new InvalidArgumentException('Retrieval decision modules must be a unique non-empty list.');
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
