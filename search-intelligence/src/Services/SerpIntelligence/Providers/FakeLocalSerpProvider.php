<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Providers;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderInterface;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpProviderResult;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;

/**
 * Local fake provider cho dev/test — không gọi vendor bên ngoài.
 */
final class FakeLocalSerpProvider implements SerpIntelligenceProviderInterface
{
    public function key(): string
    {
        return 'fake_local';
    }

    public function supports(SerpQueryRequest $request): bool
    {
        return $request->providerKey === $this->key();
    }

    public function collect(SerpQueryRequest $request): SerpProviderResult
    {
        $results = [];
        $query = $request->displayQuery !== '' ? $request->displayQuery : $request->normalizedQuery;

        for ($i = 1; $i <= 10; $i++) {
            $results[] = [
                'type' => 'organic',
                'position' => $i,
                'title' => "Fake result {$i} for {$query}",
                'url' => "https://example-{$i}.test/".rawurlencode($query),
                'snippet' => 'Synthetic SERP row for local testing.',
            ];
        }

        return new SerpProviderResult(
            providerKey: $this->key(),
            success: true,
            results: $results,
            metadata: ['synthetic' => true],
            collectedAt: date('c'),
        );
    }

    public function health(): array
    {
        return [
            'healthy' => true,
            'code' => null,
            'message' => null,
            'metadata' => ['provider' => $this->key(), 'synthetic' => true],
        ];
    }
}
