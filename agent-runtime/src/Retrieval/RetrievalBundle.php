<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

final readonly class RetrievalSource
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $name,
        public string $status,
        public string $request,
        public array $data = [],
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'name' => $this->name,
            'status' => $this->status,
            'request' => $this->request,
            'data' => $this->data,
        ];
        if ($this->reason !== null && $this->reason !== '') {
            $row['reason'] = $this->reason;
        }

        return $row;
    }
}

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
