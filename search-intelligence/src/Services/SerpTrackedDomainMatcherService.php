<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\Content\DataTransfer\SerpOrganicResult;

final class SerpTrackedDomainMatcherService
{
    public function __construct(
        private readonly GoogleSearchConsoleDomainMatcherService $hostNormalizer,
    ) {}

    /**
     * @param  list<SerpOrganicResult>  $organicResults
     * @return array{position: float|null, url: string|null}
     */
    public function findBestMatch(?string $trackedDomain, array $organicResults): array
    {
        $normalizedTracked = $this->hostNormalizer->normalizeHost((string) $trackedDomain);
        if ($normalizedTracked === '' || $organicResults === []) {
            return ['position' => null, 'url' => null];
        }

        $bestPosition = null;
        $bestUrl = null;

        foreach ($organicResults as $result) {
            $resultHost = $this->hostNormalizer->normalizeHost($result->link);
            if ($resultHost === '' || ! $this->hostMatches($normalizedTracked, $resultHost)) {
                continue;
            }

            $bestPosition = (float) $result->position;
            $bestUrl = $result->link;

            break;
        }

        return ['position' => $bestPosition, 'url' => $bestUrl];
    }

    private function hostMatches(string $tracked, string $resultHost): bool
    {
        if ($tracked === $resultHost) {
            return true;
        }

        return str_ends_with($resultHost, '.'.$tracked) || str_ends_with($tracked, '.'.$resultHost);
    }
}
