<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolContext;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutionResult;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolHandlerInterface;
use InvalidArgumentException;
use Throwable;

final class ContentProjectDraftIntakeToolHandler implements SeoToolHandlerInterface
{
    public const TOOL_KEY = 'content_project.draft_intake';

    public function __construct(
        private readonly ServiceApiDraftIntakeService $intakeService
    ) {
    }

    public function getToolKey(): string
    {
        return self::TOOL_KEY;
    }

    /**
     * @param SeoToolContext $context
     * @param array<string, mixed> $input
     * @param bool $confirmed
     * @return SeoToolExecutionResult
     */
    public function execute(SeoToolContext $context, array $input, bool $confirmed = false): SeoToolExecutionResult
    {
        $payload = $input;
        if (!isset($payload['site_id']) && $context->resolvedSiteId !== null) {
            $payload['site_id'] = $context->resolvedSiteId;
        }

        $idempotencyKey = $context->requestRef;

        try {
            $result = $this->intakeService->intake($payload, $idempotencyKey);

            return SeoToolExecutionResult::success(
                data: $result->toArray(),
                meta: [
                    'draft_ref' => $result->draftRef,
                    'added_count' => $result->added,
                    'already_in_draft_count' => $result->alreadyInDraft,
                    'failed_count' => $result->failed,
                ]
            );
        } catch (InvalidArgumentException $e) {
            return SeoToolExecutionResult::failure(
                errorCode: 'validation_failed',
                errorMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            return SeoToolExecutionResult::failure(
                errorCode: 'draft_intake_failed',
                errorMessage: $e->getMessage() !== '' ? $e->getMessage() : 'Draft intake failed.'
            );
        }
    }
}
