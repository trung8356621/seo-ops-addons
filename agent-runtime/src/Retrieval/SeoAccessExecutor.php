<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

/**
 * HTTP consumer of the site-scoped SEO Access contract.
 * Does not call SEO read models and does not accept model-supplied URLs.
 */
final class SeoAccessExecutor
{
    public function __construct(
        private readonly SeoAccessTransport $transport,
        private readonly SeoAccessCredential $credential,
        private readonly SeoAccessUrlPolicy $policy,
        private readonly string $apiBase,
    ) {}

    public function execute(RetrievalPlan $plan): RetrievalBundle
    {
        if ($plan->globalUnsupported || $plan->scope->isGlobal() || ! $plan->scope->isSite()) {
            return RetrievalBundle::unsupportedGlobal($plan->scope);
        }

        $warnings = [];
        if ($plan->parameterExtractionDeferred) {
            $warnings[] = 'parameter_extraction_deferred';
        }

        $bearer = $this->credential->bearer();
        if ($bearer === null) {
            $warnings[] = 'seo_access_credential_not_configured';

            return new RetrievalBundle($plan->scope, [], $warnings);
        }

        try {
            $mintUrl = $this->policy->mintUrl($this->apiBase);
        } catch (\InvalidArgumentException) {
            $warnings[] = 'seo_access_base_rejected';

            return new RetrievalBundle($plan->scope, [], $warnings);
        }

        $minted = $this->mint($mintUrl, $bearer, (int) $plan->scope->siteId);
        if ($minted === null) {
            $warnings[] = 'seo_access_mint_failed';

            return new RetrievalBundle($plan->scope, [], $warnings);
        }

        $sources = [];
        foreach ($plan->steps as $step) {
            $sources[] = $this->readResource($plan->scope, $minted['access_url'], $minted['expires_at'], $mintUrl, $bearer, $step);
        }

        return new RetrievalBundle($plan->scope, $sources, $warnings);
    }

    /**
     * @return array{access_url: string, expires_at: string}|null
     */
    private function mint(string $mintUrl, string $bearer, int $siteId): ?array
    {
        $response = $this->transport->request('POST', $mintUrl, [], $bearer, ['site_id' => $siteId]);
        $data = is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
        $accessUrl = trim((string) ($data['access_url'] ?? ''));
        if (($response['status'] ?? 0) !== 200 || $accessUrl === '') {
            return null;
        }

        return [
            'access_url' => $accessUrl,
            'expires_at' => (string) ($data['expires_at'] ?? ''),
        ];
    }

    private function readResource(
        AgentProjectScope $scope,
        string $accessUrl,
        string $expiresAt,
        string $mintUrl,
        string $bearer,
        RetrievalStep $step,
    ): RetrievalSource {
        $path = $this->resourcePath($step);
        $url = rtrim($accessUrl, '/').$path;
        if ($step->query !== []) {
            $url .= '?'.http_build_query($step->query);
        }

        try {
            $this->policy->assertResourceUrl($accessUrl, strtok($url, '?') ?: $url);
        } catch (\InvalidArgumentException) {
            return new RetrievalSource($step->resource, 'error', 'GET '.$path, [], 'url_rejected');
        }

        $response = $this->transport->request('GET', $url, $step->query);
        if (($response['status'] ?? 0) === 401) {
            $reminted = $this->mint($mintUrl, $bearer, (int) $scope->siteId);
            if ($reminted !== null) {
                $accessUrl = $reminted['access_url'];
                $url = rtrim($accessUrl, '/').$path;
                if ($step->query !== []) {
                    $url .= '?'.http_build_query($step->query);
                }
                $this->policy->assertResourceUrl($accessUrl, strtok($url, '?') ?: $url);
                $response = $this->transport->request('GET', $url, $step->query);
            }
        }

        unset($expiresAt);
        $requestLabel = 'GET '.$path.($step->query === [] ? '' : '?'.http_build_query($step->query));
        $data = is_array($response['json']['data'] ?? null) ? $response['json']['data'] : [];
        if (($response['status'] ?? 0) !== 200) {
            return new RetrievalSource($step->resource, 'error', $requestLabel, $data, 'http_'.$response['status']);
        }

        if (array_key_exists('available', $data) && $data['available'] === false) {
            return new RetrievalSource(
                $step->resource,
                'unavailable',
                $requestLabel,
                $data,
                (string) ($data['reason'] ?? 'unavailable'),
            );
        }

        return new RetrievalSource($step->resource, 'ok', $requestLabel, $data);
    }

    private function resourcePath(RetrievalStep $step): string
    {
        if ($step->resource === 'keywords' && is_string($step->topicRef) && preg_match('/^topic:[1-9]\d*$/', $step->topicRef) === 1) {
            return '/keywords/topics/'.$step->topicRef;
        }

        return '/'.$step->resource;
    }
}
