<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Runtime;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Decision\RetrievalDecision;
use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final readonly class AgentToolConfirmationProposal
{
    /** @param list<string> $capabilities @param array<string, scalar|null> $parameters @param list<string> $toolCapabilities @param array<string, mixed> $scope */
    public function __construct(
        public string $intent,
        public string $primaryCapability,
        public array $capabilities,
        public array $parameters,
        public string $responseTemplate,
        public string $responseLanguage,
        public array $toolCapabilities,
        public array $scope,
    ) {}

    public static function fromDecision(RetrievalDecision $decision, AgentProjectScope $scope): ?self
    {
        $tools = AgentCapabilityCatalog::toolCapabilities($decision->capabilities);
        if ($tools === []) {
            return null;
        }

        return new self(
            $decision->intent,
            (string) $decision->primaryCapability,
            $decision->capabilities,
            $decision->parameters,
            $decision->responseTemplate,
            $decision->responseLanguage,
            $tools,
            $scope->toArray(),
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $capabilities = self::stringList($payload['capabilities'] ?? null);
        $tools = self::stringList($payload['tool_capabilities'] ?? null);
        $parameters = $payload['parameters'] ?? null;
        $scope = $payload['scope'] ?? null;
        if ($capabilities === [] || $tools === [] || ! is_array($parameters) || ! is_array($scope)) {
            throw new \InvalidArgumentException('Persisted confirmation proposal is invalid.');
        }

        return new self(
            trim((string) ($payload['intent'] ?? '')),
            trim((string) ($payload['primary_capability'] ?? '')),
            $capabilities,
            $parameters,
            trim((string) ($payload['response_template'] ?? '')),
            in_array(($payload['response_language'] ?? null), ['vi', 'en'], true)
                ? (string) $payload['response_language']
                : self::legacyResponseLanguage(),
            $tools,
            $scope,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'primary_capability' => $this->primaryCapability,
            'capabilities' => $this->capabilities,
            'parameters' => $this->parameters,
            'response_template' => $this->responseTemplate,
            'response_language' => $this->responseLanguage,
            'tool_capabilities' => $this->toolCapabilities,
            'scope' => $this->scope,
        ];
    }

    /** @return array{intent: string, tool_capabilities: list<string>, parameters: array<string, scalar|null>} */
    public function clientPayload(): array
    {
        return [
            'intent' => $this->intent,
            'tool_capabilities' => $this->toolCapabilities,
            'parameters' => $this->parameters,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    private static function legacyResponseLanguage(): string
    {
        $locale = function_exists('app') ? strtolower((string) app()->getLocale()) : 'en';

        return in_array($locale, ['vi', 'en'], true) ? $locale : 'en';
    }
}
