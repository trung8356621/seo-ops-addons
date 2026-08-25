<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\InspectArticleIndexesWithGscCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection\GscUrlInspectionRunService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;

final class InspectArticleIndexesWithGscHandler implements ContentProjectCommandHandler
{
    public function __construct(
        private readonly GscUrlInspectionRunService $runs = new GscUrlInspectionRunService,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof InspectArticleIndexesWithGscCommand) {
            throw new InvalidArgumentException('Expected InspectArticleIndexesWithGscCommand.');
        }

        $siteId = (int) $command->siteId;
        if ($siteId <= 0) {
            return ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::VALIDATION_FAILED,
                'site_id is required.',
            );
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            return ContentProjectActionResult::fail(GscIntelligenceActionCodes::FORBIDDEN, 'Access denied.');
        }

        $actorId = ($actor->actorId ?? 0) > 0 ? (int) $actor->actorId : null;

        $result = $command->dueOnly || $command->articleIds === []
            ? $this->runs->queueDue($siteId, $actorId, $command->limit)
            : $this->runs->queueForArticles($siteId, $command->articleIds, $actorId, $command->limit);

        if (! ($result['ok'] ?? false)) {
            return ContentProjectActionResult::fail(
                (string) ($result['error_code'] ?? GscIntelligenceActionCodes::FAILED),
                (string) ($result['error_message'] ?? 'Failed to queue GSC URL Inspection.'),
                metadata: ['site_id' => $siteId],
            );
        }

        return ContentProjectActionResult::ok(
            'gsc.url_inspection_queued',
            ($result['queued'] ?? false)
                ? 'GSC URL Inspection queued.'
                : 'GSC URL Inspection finished.',
            affectedItemIds: $command->articleIds,
            metadata: [
                'site_id' => $siteId,
                'queued' => (bool) ($result['queued'] ?? false),
                'run_id' => $result['run_id'] ?? null,
                'public_ref' => $result['public_ref'] ?? null,
                'status' => $result['status'] ?? 'queued',
                'requested' => $result['requested'] ?? null,
                'inspected' => $result['inspected'] ?? null,
                'indexed' => $result['indexed'] ?? null,
                'not_indexed' => $result['not_indexed'] ?? null,
                'unknown' => $result['unknown'] ?? null,
                'failed' => $result['failed'] ?? null,
            ],
        );
    }
}
