<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpQuery;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpSnapshot;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Application\SerpIntelligenceActionCodes;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractSerpIntelligenceHandler implements ContentProjectCommandHandler
{
    public function __construct(
        protected readonly KeywordIntelligenceTenantGuard $tenantGuard,
        protected readonly ContentProjectPreviewToken $previewToken,
    ) {}

    protected function resolveWorkspace(string $workspaceRef): SeoKeywordWorkspace
    {
        $id = KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($workspaceRef);
        $workspace = SeoKeywordWorkspace::query()->find($id);

        if (! $workspace instanceof SeoKeywordWorkspace) {
            throw new RuntimeException('Workspace không tồn tại.');
        }

        return $workspace;
    }

    protected function resolveQuery(string $queryRef, ?SeoKeywordWorkspace $workspace = null): SeoSerpQuery
    {
        $id = KeywordIntelligencePublicRef::resolveSerpQueryIdStrict($queryRef);
        $query = SeoSerpQuery::query()->find($id);

        if (! $query instanceof SeoSerpQuery) {
            throw new RuntimeException('SERP query không tồn tại.');
        }

        if ($workspace !== null && (int) ($query->workspace_id ?? 0) !== (int) $workspace->id) {
            throw new RuntimeException('SERP query không thuộc workspace.');
        }

        return $query;
    }

    protected function resolveSnapshot(string $snapshotRef, ?SeoSerpQuery $query = null): SeoSerpSnapshot
    {
        $id = KeywordIntelligencePublicRef::resolveSerpSnapshotIdStrict($snapshotRef);
        $snapshot = SeoSerpSnapshot::query()->find($id);

        if (! $snapshot instanceof SeoSerpSnapshot) {
            throw new RuntimeException('SERP snapshot không tồn tại.');
        }

        if ($query !== null && (int) ($snapshot->serp_query_id ?? 0) !== (int) $query->id) {
            throw new RuntimeException('SERP snapshot không thuộc query.');
        }

        return $snapshot;
    }

    protected function assertNotArchived(SeoKeywordWorkspace $workspace): void
    {
        if ($workspace->archived_at !== null || $workspace->status === KeywordWorkspaceStatus::Archived) {
            throw new RuntimeException('Workspace archived.');
        }
    }

    /**
     * @param  callable(): ContentProjectActionResult  $callback
     */
    protected function wrap(callable $callback): ContentProjectActionResult
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            return ContentProjectActionResult::fail(
                SerpIntelligenceActionCodes::VALIDATION_FAILED,
                $e->getMessage(),
            );
        } catch (RuntimeException $e) {
            return $this->mapRuntimeException($e);
        }
    }

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
    protected function assertConfirmationToken(?string $token, array $fingerprint, bool $required): ?ContentProjectActionResult
    {
        if (! $required) {
            return null;
        }

        if ($token === null || trim($token) === '') {
            return ContentProjectActionResult::fail(
                SerpIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation required.',
            );
        }

        $status = $this->previewToken->validate($token, $fingerprint);

        return match ($status) {
            'ok' => null,
            'expired' => ContentProjectActionResult::fail(
                SerpIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation token expired.',
            ),
            'stale' => ContentProjectActionResult::fail(
                SerpIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation token stale — preview outdated.',
            ),
            default => ContentProjectActionResult::fail(
                SerpIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Invalid confirmation token.',
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function buildFingerprint(string $action, int $workspaceId, array $extra = []): array
    {
        return array_merge([
            'action' => $action,
            'workspace_id' => $workspaceId,
        ], $extra);
    }

    protected function mapRuntimeException(RuntimeException $e): ContentProjectActionResult
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Không có quyền') || str_contains($message, 'không thuộc site')) {
            return ContentProjectActionResult::fail(SerpIntelligenceActionCodes::FORBIDDEN, $message);
        }

        if (str_contains($message, 'không tồn tại')) {
            return ContentProjectActionResult::fail(SerpIntelligenceActionCodes::NOT_FOUND, $message);
        }

        if (str_contains($message, 'archived') || str_contains($message, 'Archived')) {
            return ContentProjectActionResult::fail(SerpIntelligenceActionCodes::WORKSPACE_ARCHIVED, $message);
        }

        if (str_starts_with($message, 'serp.')) {
            return ContentProjectActionResult::fail($message, $message);
        }

        return ContentProjectActionResult::fail(SerpIntelligenceActionCodes::FAILED, $message);
    }
}
