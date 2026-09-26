<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\KeywordRelationship\KeywordRelationshipGateway;

final class KeywordsRelationshipSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly KeywordRelationshipGateway $relationship,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::KEYWORDS_RELATIONSHIP,
            description: 'On-demand one-keyword relationship graph. Never writes monthly snapshots.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Standard->value,
            requiredParameters: ['keyword_ref'],
            optionalParameters: ['keyword_id'],
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $input = $request->keywordInput();
        if ($input === []) {
            throw new InvalidArgumentException('keywords.relationship requires keyword_ref or keyword_id.');
        }
        $keywordId = $this->relationship->resolveKeywordId($input);
        if ($keywordId <= 0) {
            throw new InvalidArgumentException('keywords.relationship requires keyword_ref or keyword_id.');
        }

        $dto = $this->relationship->forKeyword($request->siteId, $keywordId);
        if ($dto === null) {
            return ContextSlice::make(
                ContextSliceKey::KEYWORDS_RELATIONSHIP,
                $request->siteId,
                [],
                null,
                false,
                null,
                false,
            );
        }

        $full = $dto->toArray();
        $data = match ($request->view) {
            ContextView::Summary => [
                'keyword' => $full['keyword'] ?? [],
                'topics' => $full['topics'] ?? [],
                'focus_articles' => $full['focus_articles'] ?? [],
                'meta' => [
                    'available_sections' => $full['meta']['available_sections'] ?? [],
                    'relation_issues' => $full['meta']['relation_issues'] ?? [],
                ],
            ],
            ContextView::Standard => [
                'keyword' => $full['keyword'] ?? [],
                'topics' => $full['topics'] ?? [],
                'focus_articles' => $full['focus_articles'] ?? [],
                'related_keywords' => $full['related_keywords'] ?? [],
                'internal_links' => $full['internal_links'] ?? [],
                'gsc' => $full['gsc'] ?? [],
                'meta' => $full['meta'] ?? [],
            ],
            ContextView::Detail => $full,
        };

        return ContextSlice::make(
            ContextSliceKey::KEYWORDS_RELATIONSHIP,
            $request->siteId,
            $data,
            $dto->sourceUpdatedAt,
            true,
            $dto->generatedAt,
            false,
        );
    }
}
