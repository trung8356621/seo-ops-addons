<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectItemAction;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\ArchiveProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArchiveService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionDecisionService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionGuard;
use InvalidArgumentException;

/**
 * Item-level archive (not Archive Project). Keeps WP post; cleans workspace artifacts via archive policy.
 */
final class ArchiveProjectItemsHandler extends AbstractPublishingHandler
{
    public function __construct(
        ContentProjectTenantGuard $tenantGuard,
        ContentProjectBusinessLock $businessLock,
        ContentProjectPreviewToken $previewToken,
        private readonly SeoProjectArchiveService $archiveService,
        private readonly SeoAuditSuggestionDecisionService $suggestionDecisions,
        private readonly ContentProjectItemActionGuard $actionGuard = new ContentProjectItemActionGuard,
    ) {
        parent::__construct($tenantGuard, $businessLock, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof ArchiveProjectItemsCommand) {
            throw new InvalidArgumentException('Expected ArchiveProjectItemsCommand.');
        }

        return $this->wrap(null, function () use ($command, $actor): ContentProjectActionResult {
            $project = $this->resolveProject($command->projectRef);
            $projectId = (int) $project->getKey();
            $this->tenantGuard->assertCanAccessProject($project, $actor);

            if ($project->archived_at !== null || $project->isArchive()) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                    'Project archived.',
                    $projectId,
                );
            }

            $itemIds = $this->resolveItemIds($command->itemRefs);
            if ($itemIds === []) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::VALIDATION_FAILED,
                    'Item list is empty.',
                    $projectId,
                );
            }

            $this->tenantGuard->assertTasksBelongToProject($project, $itemIds);
            $this->assertItemsArchivable($projectId, $itemIds);

            $fingerprint = $this->buildFingerprint($command->name(), $projectId, [
                'item_ids' => $itemIds,
                'note' => $command->note,
            ]);

            if ($this->isDryRun($command->dryRun, $actor->dryRun)) {
                return $this->previewReady(
                    $projectId,
                    $itemIds,
                    $fingerprint,
                    [
                        'action' => 'archive_items',
                        'item_ids' => $itemIds,
                        'count' => count($itemIds),
                        'note' => $command->note,
                        'wordpress_post_deleted' => false,
                    ],
                );
            }

            $token = $command->confirmationToken ?? $actor->confirmationToken;
            $confirmationFailure = $this->assertConfirmationToken(
                $token,
                $fingerprint,
                required: $this->requiresConfirmation($actor, $token),
                projectId: $projectId,
            );
            if ($confirmationFailure instanceof ContentProjectActionResult) {
                return $confirmationFailure;
            }

            $userId = (int) ($actor->actorId ?? 0);
            if ($userId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::FORBIDDEN,
                    'Actor user id is required.',
                    $projectId,
                );
            }

            $result = $this->businessLock->withLock(
                $this->businessLock->projectArchive($projectId),
                function () use ($project, $projectId, $itemIds, $command, $userId, $actor): ContentProjectActionResult {
                    $rejectArticleIds = [];
                    $rejectFingerprints = [];
                    if ($project->isDraftPlanning()) {
                        $tasks = SeoProjectTask::query()
                            ->where('project_id', $projectId)
                            ->whereIn('id', $itemIds)
                            ->with('itemOrigin')
                            ->get();

                        foreach ($tasks as $task) {
                            if (! $task instanceof SeoProjectTask) {
                                continue;
                            }
                            $articleId = (int) ($task->article_id ?? 0);
                            if ($articleId > 0) {
                                $rejectArticleIds[] = $articleId;
                            }

                            $origin = $task->itemOrigin;
                            if ($origin instanceof \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin
                                && (string) $origin->source_type === \Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT
                            ) {
                                $fp = trim((string) ($origin->source_fingerprint ?? ''));
                                if ($fp !== '') {
                                    $rejectFingerprints[] = [
                                        'fingerprint' => $fp,
                                        'keyword' => (string) ($task->keyword ?? ''),
                                        'title' => (string) ($task->title ?? ''),
                                    ];
                                }
                            }
                        }

                        $rejectArticleIds = array_values(array_unique(array_filter(
                            $rejectArticleIds,
                            static fn (int $id): bool => $id > 0,
                        )));
                    }

                    // Soft-archive planning item: sets archived_at + TaskArchived event.
                    // Without article_id there is no article workspace / WP mirror cleanup.
                    $archiveResult = $this->archiveService->archiveTasks(
                        $project,
                        $itemIds,
                        $userId,
                        $command->note,
                    );

                    // Project-scoped rejection only — never skip_seo_audit.
                    if ($rejectArticleIds !== []) {
                        $this->suggestionDecisions->dismissArticles(
                            $project,
                            $rejectArticleIds,
                            $actor->actorId,
                        );
                    }
                    if ($rejectFingerprints !== []) {
                        $this->suggestionDecisions->dismissFingerprints(
                            $project,
                            $rejectFingerprints,
                            $actor->actorId,
                        );
                    }

                    return ContentProjectActionResult::ok(
                        ContentProjectActionCodes::ITEMS_ARCHIVED,
                        sprintf('%d item(s) archived.', (int) ($archiveResult['archived'] ?? 0)),
                        $projectId,
                        $itemIds,
                        metadata: [
                            'affected_count' => (int) ($archiveResult['archived'] ?? 0),
                            'wordpress_post_deleted' => false,
                            'rejected_article_ids' => $rejectArticleIds,
                            'rejected_fingerprints' => array_column($rejectFingerprints, 'fingerprint'),
                        ],
                    );
                },
            );

            if ($result->success) {
                $this->consumeConfirmationToken($command->confirmationToken ?? $actor->confirmationToken);
            }

            return $result;
        });
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function assertItemsArchivable(int $projectId, array $itemIds): void
    {
        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereIn('id', $itemIds)
            ->with(['article'])
            ->get();

        foreach ($tasks as $task) {
            $this->actionGuard->assertCan(
                ContentProjectItemAction::Archive,
                $task,
                $task->relationLoaded('article') ? $task->article : null,
            );
        }
    }
}
