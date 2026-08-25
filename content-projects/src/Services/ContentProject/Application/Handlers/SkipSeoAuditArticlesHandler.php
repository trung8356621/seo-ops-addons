<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\Content\Services\ArticleSeoAuditSkipService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SkipSeoAuditArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use InvalidArgumentException;

final class SkipSeoAuditArticlesHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ArticleSeoAuditSkipService $skipService,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SkipSeoAuditArticlesCommand) {
            throw new InvalidArgumentException('Expected SkipSeoAuditArticlesCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $ids = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $command->articleIds),
                static fn (int $id): bool => $id > 0,
            )));

            if ($ids === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Article list is empty.',
                    $projectId,
                );
            }

            $summary = $this->skipService->skipMany($ids);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::SEO_AUDIT_ARTICLES_SKIPPED,
                sprintf(
                    'Skipped: %d · Already skipped: %d · Missing: %d',
                    (int) ($summary['skipped'] ?? 0),
                    (int) ($summary['already_skipped'] ?? 0),
                    (int) ($summary['missing'] ?? 0),
                ),
                $projectId,
                $ids,
                metadata: $summary,
            );
        });
    }
}
