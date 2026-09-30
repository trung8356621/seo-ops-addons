<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\Seo\Services\SeoAudit\Agent\SeoAuditAgentReadService;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolContext;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutionResult;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolHandlerInterface;
use Throwable;

final class SeoAuditListToolHandler implements SeoToolHandlerInterface
{
    public const TOOL_KEY = 'seo_audit.list';

    public function __construct(
        private readonly SeoAuditAgentReadService $readService
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
        try {
            $agentContext = new AgentExecutionContext(
                actorRef: $context->actorRef,
                actorType: $context->actorType,
                tenantRef: $context->tenantRef ?? ('site:' . ($context->resolvedSiteId ?? 0)),
                siteRef: $context->siteRef ?? ('site:' . ($context->resolvedSiteId ?? 0)),
                requestRef: $context->requestRef ?? ('req_' . bin2hex(random_bytes(8))),
                resolvedSiteId: $context->resolvedSiteId,
                resolvedActorUserId: $context->resolvedActorUserId,
                scopes: $context->scopes
            );

            $result = $this->readService->listArticles($agentContext, $input);

            return SeoToolExecutionResult::success($result);
        } catch (Throwable $e) {
            return SeoToolExecutionResult::failure(
                errorCode: 'execution_failed',
                errorMessage: 'Failed to retrieve SEO audit articles.'
            );
        }
    }
}
