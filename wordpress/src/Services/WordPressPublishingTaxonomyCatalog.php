<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalog;
use Omnichannel\Addons\Publishing\Contracts\PublishingTaxonomyCatalogResult;

final class WordPressPublishingTaxonomyCatalog implements PublishingTaxonomyCatalog
{
    /** @var array<string, PublishingTaxonomyCatalogResult> */
    private array $memo = [];

    public function __construct(
        private readonly WordPressTaxonomyCatalogClient $client,
    ) {}

    public function getTerms(int $siteId, string $taxonomy): PublishingTaxonomyCatalogResult
    {
        $taxonomy = strtolower(trim($taxonomy));
        if (! in_array($taxonomy, self::SUPPORTED, true)) {
            return PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'unsupported_taxonomy',
                'Unsupported taxonomy.',
            );
        }

        $key = $siteId.':'.$taxonomy;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if ($siteId <= 0) {
            return $this->memo[$key] = PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'site_missing',
                'Missing site.',
            );
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return $this->memo[$key] = PublishingTaxonomyCatalogResult::unavailable(
                $taxonomy,
                'site_missing',
                'Site not found.',
            );
        }

        return $this->memo[$key] = $this->client->fetch($site, $taxonomy);
    }
}
