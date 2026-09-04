<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\LinkIntelligence;

use Omnichannel\Addons\Seeding\LinkIntelligence\Dto\ExtractedLink;
use Omnichannel\Addons\Seeding\LinkIntelligence\Models\LinkResource;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Illuminate\Support\Facades\DB;

/**
 * Shared Link Library — find-or-create by normalized_url; sync topic pivots without deleting resources.
 */
final class LinkResourceService
{
    public function __construct(
        private readonly LinkExtractor $extractor = new LinkExtractor,
        private readonly UrlNormalizer $normalizer = new UrlNormalizer,
    ) {}

    /**
     * Extract from content, upsert LinkResources, sync topic pivot to exact set.
     *
     * @return list<LinkResource>
     */
    public function syncTopicLinks(SeedingTopic $topic, ?string $fullText, ?string $sourceHtml): array
    {
        $extracted = $this->extractor->extract($fullText, $sourceHtml);
        $resourceIds = [];

        foreach ($extracted as $link) {
            $resource = $this->findOrCreate($link);
            $resourceIds[] = (int) $resource->getKey();
        }

        $resourceIds = array_values(array_unique($resourceIds));

        DB::connection($topic->getConnectionName())->transaction(function () use ($topic, $resourceIds): void {
            $topic->linkResources()->sync($resourceIds);
        });

        if ($resourceIds === []) {
            return [];
        }

        return LinkResource::query()
            ->whereIn('id', $resourceIds)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function findOrCreate(ExtractedLink $link): LinkResource
    {
        $hash = hash('sha256', $link->normalizedUrl);

        $existing = LinkResource::query()
            ->where('normalized_url_hash', $hash)
            ->first();

        if ($existing instanceof LinkResource) {
            return $existing;
        }

        return LinkResource::query()->create([
            'original_url' => $link->originalUrl,
            'normalized_url' => $link->normalizedUrl,
            'normalized_url_hash' => $hash,
            'domain' => $link->domain,
            'title' => null,
            'description' => null,
            'fetch_status' => null,
            'fetched_at' => null,
            'metadata_json' => null,
        ]);
    }

    /**
     * @return NormalizedUrl|null
     *
     * @phpstan-type NormalizedUrl array{original_url: string, normalized_url: string, domain: string}
     */
    public function normalizeUrl(string $raw): ?array
    {
        return $this->normalizer->normalize($raw);
    }
}
