<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;


final readonly class RetrievalBundle
{
    /**
     * @param  list<RetrievalSource>  $sources
     * @param  list<string>  $warnings
     */
    public function __construct(
        public AgentProjectScope $scope,
        public array $sources,
        public array $warnings = [],
    ) {}

    public static function unsupportedGlobal(AgentProjectScope $scope): self
    {
        return new self($scope, [], ['global_access_unsupported']);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $scope = AgentProjectScope::fromArray((array) ($payload['scope'] ?? []));
        $sources = [];
        foreach ((array) ($payload['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                continue;
            }
            $sources[] = new RetrievalSource(
                (string) ($source['name'] ?? ''),
                (string) ($source['status'] ?? ''),
                (string) ($source['request'] ?? ''),
                (array) ($source['data'] ?? []),
                isset($source['reason']) ? (string) $source['reason'] : null,
            );
        }

        return new self($scope, $sources, array_values((array) ($payload['warnings'] ?? [])));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->toArray(),
            'sources' => array_map(
                static fn (RetrievalSource $source): array => $source->toArray(),
                $this->sources,
            ),
            'warnings' => array_values($this->warnings),
        ];
    }
}
