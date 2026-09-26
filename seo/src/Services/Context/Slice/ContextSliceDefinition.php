<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

/**
 * Allowlisted parameter + view metadata for a registered slice.
 *
 * Aliases (e.g. period_key, keyword_id) must be listed explicitly on the slice
 * that owns them — never applied globally by the Registry.
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
     * @return list<string>
     */
    public function allowedParameters(): array
    {
        return array_values(array_unique([
            ...$this->requiredParameters,
            ...$this->optionalParameters,
        ]));
    }

    public function allowsParameter(string $name): bool
    {
        return in_array($name, $this->allowedParameters(), true);
    }

    public function allowsView(string $view): bool
    {
        return in_array($view, $this->views, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'description' => $this->description,
            'scope' => $this->scope,
            'parameters' => $this->allowedParameters(),
            'required_parameters' => $this->requiredParameters,
            'optional_parameters' => $this->optionalParameters,
            'views' => $this->views,
            'default_view' => $this->defaultView,
            'period_aware' => $this->periodAware,
        ];
    }
}
