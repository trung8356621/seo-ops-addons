<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use App\Support\RuntimeLogger;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ChangeContentProjectWriterCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ChangeContentProjectWriterService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class ChangeContentProjectWriterHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly ChangeContentProjectWriterService $changeWriter,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ChangeContentProjectWriterCommand) {
            throw new InvalidArgumentException('Expected ChangeContentProjectWriterCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            if (! SeoAccessControl::canMutateContentProjects()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    (string) __('seo-content-ai::filament.projects.change_writer_forbidden'),
                );
            }

            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            $siteId = (int) ($project->site_id ?? 0);
            if ($siteId > 0 && ! SeoAccessControl::canAccessSite($siteId)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    (string) __('seo-content-ai::filament.projects.change_writer_forbidden'),
                    $projectId,
                );
            }

            return $this->businessLock->withLock(
                $this->businessLock->projectSchedule($projectId),
                function () use ($project, $projectId, $command, $actor): ContentProjectActionResult {
                    $result = $this->changeWriter->changeWriter($project, $command->newWriterId);

                    if (($result['ok'] ?? false) !== true) {
                        return ContentProjectActionResult::fail(
                            (string) ($result['code'] ?? ContentProjectActionCodes::FAILED),
                            (string) ($result['reason'] ?? ContentProjectActionCodes::FAILED),
                            $projectId,
                            metadata: is_array($result['metadata'] ?? null) ? $result['metadata'] : [],
                        );
                    }

                    RuntimeLogger::info('content_project_writer_changed', [
                        'project_id' => $projectId,
                        'from_user_id' => (int) ($result['from_user_id'] ?? 0),
                        'to_user_id' => (int) ($result['to_user_id'] ?? 0),
                        'actor_id' => $actor->actorId,
                        'articles_synced' => (int) ($result['articles_synced'] ?? 0),
                    ]);

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::PROJECT_WRITER_CHANGED,
                        (string) __('seo-content-ai::filament.projects.change_writer_completed'),
                        $projectId,
                        metadata: [
                            'from_user_id' => (int) ($result['from_user_id'] ?? 0),
                            'to_user_id' => (int) ($result['to_user_id'] ?? 0),
                            'articles_synced' => (int) ($result['articles_synced'] ?? 0),
                            'actor_id' => $actor->actorId,
                        ],
                    );
                },
            );
        });
    }
}
