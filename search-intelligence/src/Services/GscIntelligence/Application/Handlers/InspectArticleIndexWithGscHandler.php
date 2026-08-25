<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexWithGscCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;

final class InspectArticleIndexWithGscHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly GscUrlInspectionService $inspection = new GscUrlInspectionService,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof InspectArticleIndexWithGscCommand) {
            throw new InvalidArgumentException('Expected InspectArticleIndexWithGscCommand.');
        }

        $articleId = (int) $command->articleId;
        if ($articleId <= 0) {
            return ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::VALIDATION_FAILED,
                'article_id is required.',
            );
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return ContentProjectActionResult::fail(GscIntelligenceActionCodes::NOT_FOUND, 'Article not found.');
        }

        if (! SeoAccessControl::canAccessArticle($article)) {
            return ContentProjectActionResult::fail(GscIntelligenceActionCodes::FORBIDDEN, 'Access denied.');
        }

        $result = $this->inspection->inspectArticle(
            $articleId,
            ($actor->actorId ?? 0) > 0 ? (int) $actor->actorId : null,
        );

        if (! ($result['ok'] ?? false)) {
            return ContentProjectActionResult::fail(
                (string) ($result['error_code'] ?? GscIntelligenceActionCodes::FAILED),
                (string) ($result['error_message'] ?? 'GSC URL Inspection failed.'),
                metadata: [
                    'article_id' => $articleId,
                    'site_id' => (int) ($result['site_id'] ?? 0),
                ],
            );
        }

        return ContentProjectActionResult::ok(
            'gsc.url_inspection_recorded',
            'GSC URL Inspection recorded.',
            affectedItemIds: [$articleId],
            metadata: [
                'article_id' => $articleId,
                'site_id' => (int) ($result['site_id'] ?? 0),
                'check_status' => $result['check_status'] ?? null,
                'effective_health' => $result['effective_health'] ?? null,
                'check_id' => $result['check_id'] ?? null,
                'source' => $result['source'] ?? null,
                'queued' => false,
                'transitioned_to_dropped' => (bool) ($result['transitioned_to_dropped'] ?? false),
                'recovered_from_dropped' => (bool) ($result['recovered_from_dropped'] ?? false),
            ],
        );
    }
}
