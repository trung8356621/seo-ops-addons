<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;
use RuntimeException;

/**
 * Base cho mọi Keyword Intelligence Application Handler — mirror
 * AbstractPublishingHandler nhưng resolve SeoKeywordWorkspace thay vì SeoProject.
 */
abstract class AbstractKeywordIntelligenceHandler implements ContentProjectCommandHandler
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
                KeywordIntelligenceActionCodes::VALIDATION_FAILED,
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
                KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation required.',
            );
        }

        $status = $this->previewToken->validate($token, $fingerprint);

        return match ($status) {
            'ok' => null,
            'expired' => ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation token expired.',
            ),
            'stale' => ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation token stale — preview outdated.',
            ),
            default => ContentProjectActionResult::fail(
                KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED,
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
            return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::FORBIDDEN, $message);
        }

        if (str_contains($message, 'không tồn tại')) {
            return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::NOT_FOUND, $message);
        }

        if (str_contains($message, 'archived') || str_contains($message, 'Archived')) {
            return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::WORKSPACE_ARCHIVED, $message);
        }

        if (str_starts_with($message, 'topical_map.') || str_starts_with($message, 'keyword.conversion.')) {
            return ContentProjectActionResult::fail($message, $message);
        }

        return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::FAILED, $message);
    }
}
