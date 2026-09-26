<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\Context\Support\KeywordRelationshipSectionFilter;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;

/**
 * Keywords resource for SEO Access — landscape (GET) + relationship (POST read).
 */
class SeoAccessKeywordsComposer
{
    public const SCHEMA_LANDSCAPE = 'seo.access.keywords.v1';

    public const SCHEMA_RELATIONSHIP = 'seo.access.keywords.relationship.v1';

    private const LANDSCAPE_TOPIC_LIMIT = 20;

    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
        private readonly KeywordRelationshipGateway $relationship,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function landscape(int $siteId): array
    {
        $dto = $this->landscape->forSite($siteId, false);
        $topics = [];
        foreach ($dto->topics as $topic) {
            $topics[] = [
                'id' => $topic->id,
                'name' => $topic->name,
                'coverage' => $topic->coverage,
                'article_count' => $topic->articleCount,
            ];
        }

        return [
            'schema' => self::SCHEMA_LANDSCAPE,
            'site_ref' => 'site:'.$siteId,
            'generated_at' => now()->toIso8601String(),
            'source_updated_at' => $dto->sourceUpdatedAt,
            'landscape' => [
                'topic_count' => $dto->topicCount(),
                'topics' => ContextListSlice::fromAll($topics, self::LANDSCAPE_TOPIC_LIMIT),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function relationship(int $siteId, array $input): array
    {
        $keywordId = $this->relationship->resolveKeywordId($input);
        if ($keywordId <= 0) {
            throw new InvalidArgumentException('keyword_ref or keyword_id is required.');
        }

        $sections = null;
        if (array_key_exists('sections', $input)) {
            if (! is_array($input['sections'])) {
                throw new InvalidArgumentException('sections must be an array of allowlisted section names.');
            }
            /** @var list<string> $sections */
            $sections = array_values(array_filter(
                $input['sections'],
                static fn (mixed $s): bool => is_string($s) && trim($s) !== '',
            ));
        }

        $dto = $this->relationship->forKeyword($siteId, $keywordId);
        if ($dto === null) {
            return [
                'schema' => self::SCHEMA_RELATIONSHIP,
                'site_ref' => 'site:'.$siteId,
                'keyword_ref' => 'keyword:'.$keywordId,
                'available' => false,
                'relationship' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $data = KeywordRelationshipSectionFilter::apply($dto->toArray(), $sections);

        return [
            'schema' => self::SCHEMA_RELATIONSHIP,
            'site_ref' => 'site:'.$siteId,
            'keyword_ref' => 'keyword:'.$keywordId,
            'available' => true,
            'source_updated_at' => $dto->sourceUpdatedAt,
            'generated_at' => $dto->generatedAt,
            'relationship' => $data,
        ];
    }
}
