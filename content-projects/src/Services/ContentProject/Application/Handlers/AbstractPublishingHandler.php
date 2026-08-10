<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractPublishingHandler implements ContentProjectCommandHandler
{
    public function __construct(
        protected readonly ContentProjectTenantGuard $tenantGuard,
        protected readonly ContentProjectBusinessLock $businessLock,
        protected readonly ContentProjectPreviewToken $previewToken,
    ) {}

    protected function resolveProject(string|int $projectRef): SeoProject
    {
        $projectId = ContentProjectPublicRef::resolveProjectId($projectRef);
        $project = SeoProject::query()->find($projectId);

        if (! $project instanceof SeoProject) {
            throw new RuntimeException('Project không tồn tại.');
        }

        return $project;
    }

    /**
     * @param  list<int|string>  $itemRefs
     * @return list<int>
     */
    protected function resolveItemIds(array $itemRefs): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (int|string $ref): int => ContentProjectPublicRef::resolveItemId($ref),
            $itemRefs,
        ), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @template T of ContentProjectActionResult
     *
     * @param  callable(): ContentProjectActionResult  $callback
     */
    protected function wrap(?int $projectId, callable $callback): ContentProjectActionResult
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::VALIDATION_FAILED,
                $e->getMessage(),
                $projectId,
            );
        } catch (RuntimeException $e) {
            return $this->mapRuntimeException($e, $projectId);
        }
    }

    /**
     * @param  list<int>  $itemIds
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $plannedChanges
     * @param  array<string, mixed>  $fingerprint
     */
    protected function previewReady(
        int $projectId,
        array $itemIds,
        array $fingerprint,
        array $plannedChanges,
        array $warnings = [],
        bool $requiresConfirmation = true,
    ): ContentProjectActionResult {
        $token = $this->previewToken->issue($fingerprint);

        return ContentProjectActionResult::ok(
            ContentProjectActionCodes::PREVIEW_READY,
            'Preview ready.',
            $projectId,
            $itemIds,
            $warnings,
            metadata: [
                'affected_count' => count($itemIds),
                'affected_item_ids' => $itemIds,
                'warnings' => $warnings,
                'requires_confirmation' => $requiresConfirmation,
                'confirmation_token' => $token,
                'planned_changes' => $plannedChanges,
            ],
        );
    }

    /**
     * API/Agent: dangerous ops cần preview token.
     * Filament user: không bắt buộc token (đã có auth UI).
     */
    protected function requiresConfirmation(ActorContext $actor, ?string $token = null): bool
    {
        if (in_array($actor->actorType, ['api', 'agent'], true)) {
            return true;
        }

        return $token !== null && trim($token) !== '';
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    protected function assertConfirmationToken(
        ?string $token,
        array $fingerprint,
        bool $required,
        int $projectId,
    ): ?ContentProjectActionResult {
        if (! $required) {
            return null;
        }

        if ($token === null || trim($token) === '') {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation required.',
                $projectId,
            );
        }

        $status = $this->previewToken->validate($token, $fingerprint);

        return match ($status) {
            'ok' => null,
            'expired' => ContentProjectActionResult::fail(
                ContentProjectActionCodes::CONFIRMATION_EXPIRED,
                'Confirmation token expired.',
                $projectId,
            ),
            'stale' => ContentProjectActionResult::fail(
                ContentProjectActionCodes::CONFIRMATION_STALE,
                'Confirmation token stale — preview outdated.',
                $projectId,
            ),
            default => ContentProjectActionResult::fail(
                ContentProjectActionCodes::CONFIRMATION_INVALID,
                'Invalid confirmation token.',
                $projectId,
            ),
        };
    }

    protected function consumeConfirmationToken(?string $token): void
    {
        if ($token !== null && trim($token) !== '') {
            $this->previewToken->consume($token);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildFingerprint(string $action, int $projectId, array $extra = []): array
    {
        return array_merge([
            'action' => $action,
            'project_id' => $projectId,
        ], $extra);
    }

    protected function isDryRun(bool $commandDryRun, bool $actorDryRun): bool
    {
        return $commandDryRun || $actorDryRun;
    }

    protected function mapRuntimeException(RuntimeException $e, ?int $projectId): ContentProjectActionResult
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Business lock busy') || str_contains($message, 'operation.locked')) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::OPERATION_LOCKED,
                $message,
                $projectId,
            );
        }

        if (
            str_contains($message, 'Không có quyền')
            || str_contains($message, 'không thuộc site')
            || str_contains($message, 'Forbidden')
        ) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FORBIDDEN,
                $message,
                $projectId,
            );
        }

        if (str_contains($message, 'Project không tồn tại')) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::PROJECT_NOT_FOUND,
                $message,
                $projectId,
            );
        }

        if (str_contains($message, 'Project đã Archived') || str_contains($message, 'archive_blocked_generate')) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::PROJECT_ARCHIVED_BLOCK,
                $message,
                $projectId,
            );
        }

        if (str_contains($message, 'không thuộc project') || str_contains($message, 'item không')) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::ITEMS_NOT_FOUND,
                $message,
                $projectId,
            );
        }

        if (
            str_contains($message, 'archive_waiting_publish_confirm')
            || str_contains($message, 'waiting_publish_confirm')
        ) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::CONFIRMATION_REQUIRED,
                $message,
                $projectId,
            );
        }

        if (str_contains($message, 'archive_blocked')) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::LIFECYCLE_INVALID,
                $message,
                $projectId,
            );
        }

        return ContentProjectActionResult::fail(
            ContentProjectActionCodes::FAILED,
            $message,
            $projectId,
        );
    }
}
