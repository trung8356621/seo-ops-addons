<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Providers;

use Omnichannel\Addons\Seo\Services\Context\Projection\ContextListSlice;
use Omnichannel\Addons\Seo\Services\Context\Projection\ContextProjection;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceKey;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextView;
use Omnichannel\Addons\Seo\Services\Context\Slice\Contracts\ContextSliceProvider;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;

final class KeywordsLandscapeSliceProvider implements ContextSliceProvider
{
    public function __construct(
        private readonly KeywordLandscapeGateway $landscape,
    ) {}

    public function definition(): ContextSliceDefinition
    {
        return new ContextSliceDefinition(
            key: ContextSliceKey::KEYWORDS_LANDSCAPE,
            description: 'Site-level Topic keyword landscape.',
            scope: 'site',
            views: [ContextView::Summary->value, ContextView::Standard->value, ContextView::Detail->value],
            defaultView: ContextView::Summary->value,
            optionalParameters: ['limit'],
        );
    }

    public function provide(ContextSliceRequest $request): ContextSlice
    {
        $includeDna = $request->view === ContextView::Detail;
        $landscape = $this->landscape->forSite($request->siteId, $includeDna);
        $limit = $request->limit(ContextProjection::DEFAULT_LIST_LIMIT[$request->view->value]) ?? 5;

        $topics = [];
        foreach ($landscape->topics as $topic) {
            if ($request->view === ContextView::Summary) {
                $topics[] = [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'coverage' => $topic->coverage,
                    'article_count' => $topic->articleCount,
                ];
            } elseif ($request->view === ContextView::Standard) {
                $topics[] = $topic->toMcpContextRow();
            } else {
                $topics[] = $topic->toArray();
            }
        }

        $data = [
            'topic_count' => $landscape->topicCount(),
            'topics' => ContextListSlice::fromAll($topics, $limit),
        ];

        return ContextSlice::make(
            ContextSliceKey::KEYWORDS_LANDSCAPE,
            $request->siteId,
            $data,
            $landscape->sourceUpdatedAt,
            true,
        );
    }
}
