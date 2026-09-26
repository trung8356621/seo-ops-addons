<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Registry;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextFormatter;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextProjection;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;

/**
 * Read-only registry of allowlisted context slices.
 *
 * Not an AI planner. Future planner may only select registered keys.
 */
final class ContextRegistry
{
    /** @var array<string, ContextSliceProvider> */
    private array $providers = [];

    /**
     * @param  iterable<ContextSliceProvider>  $providers
     */
    public function __construct(
        iterable $providers,
        private readonly ContextProjection $projection = new ContextProjection,
        private readonly ContextFormatter $formatter = new ContextFormatter,
    ) {
        foreach ($providers as $provider) {
            $key = $provider->definition()->key;
            $this->providers[$key] = $provider;
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function definition(string $key): ContextSliceDefinition
    {
        return $this->requireProvider($key)->definition();
    }

    /**
     * @return list<ContextSliceDefinition>
     */
    public function definitions(): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            $out[] = $provider->definition();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function get(
        int $siteId,
        string $key,
        ContextView|string $view = ContextView::Summary,
        array $parameters = [],
    ): ContextSlice {
        $provider = $this->requireProvider($key);
        $definition = $provider->definition();
        $resolvedView = $view instanceof ContextView
            ? $view
            : ContextView::tryFromInput($view, ContextView::tryFrom($definition->defaultView) ?? ContextView::Summary);

        $this->assertParameters($definition, $parameters);

        $request = new ContextSliceRequest($siteId, $key, $resolvedView, $parameters);
        $slice = $provider->provide($request);

        return $this->projection->project($slice, $resolvedView, $request->limit());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function format(
        int $siteId,
        string $key,
        ContextView|string $view = ContextView::Summary,
        array $parameters = [],
    ): array {
        return $this->formatter->format($this->get($siteId, $key, $view, $parameters));
    }

    private function requireProvider(string $key): ContextSliceProvider
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException('Unknown context slice key: '.$key);
        }

        return $this->providers[$key];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function assertParameters(ContextSliceDefinition $definition, array $parameters): void
    {
        $allowed = array_flip([
            ...$definition->requiredParameters,
            ...$definition->optionalParameters,
            // Convenience aliases always allowed when period-aware / keyword slices.
            'period_key',
            'keyword_id',
        ]);
        foreach (array_keys($parameters) as $name) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Invalid context parameter name.');
            }
            if ($name === 'period' || $name === 'period_key') {
                if (! $definition->periodAware && ! in_array('period', $definition->optionalParameters, true)) {
                    throw new InvalidArgumentException('Parameter not allowed for '.$definition->key.': '.$name);
                }
                continue;
            }
            if (! isset($allowed[$name])) {
                throw new InvalidArgumentException('Parameter not allowed for '.$definition->key.': '.$name);
            }
        }

        foreach ($definition->requiredParameters as $required) {
            if ($required === 'keyword_ref') {
                $hasRef = isset($parameters['keyword_ref']) && is_string($parameters['keyword_ref']) && $parameters['keyword_ref'] !== '';
                $hasId = isset($parameters['keyword_id']) && is_numeric($parameters['keyword_id']);
                if (! $hasRef && ! $hasId) {
                    throw new InvalidArgumentException('Missing required parameter for '.$definition->key.': keyword_ref');
                }
                continue;
            }
            if (! array_key_exists($required, $parameters) || $parameters[$required] === null || $parameters[$required] === '') {
                throw new InvalidArgumentException('Missing required parameter for '.$definition->key.': '.$required);
            }
        }
    }
}
