<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

/**
 * Allowlisted parameter + view metadata for a registered slice.
 */
final class ContextSliceDefinition
{
    /**
     * @param  list<string>  $views
     * @param  list<string>  $requiredParameters
     * @param  list<string>  $optionalParameters
     */
    public function __construct(
        public readonly string $key,
        public readonly string $description,
        public readonly string $scope,
        public readonly array $views,
        public readonly string $defaultView,
        public readonly array $requiredParameters = [],
        public readonly array $optionalParameters = [],
        public readonly bool $periodAware = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'description' => $this->description,
            'scope' => $this->scope,
            'parameters' => array_values(array_unique([
                ...$this->requiredParameters,
                ...$this->optionalParameters,
            ])),
            'required_parameters' => $this->requiredParameters,
            'optional_parameters' => $this->optionalParameters,
            'views' => $this->views,
            'default_view' => $this->defaultView,
            'period_aware' => $this->periodAware,
        ];
    }
}
