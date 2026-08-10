<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentConversationSummarizer;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelGateway;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentModelRouter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentConversationSummary;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelRoutingContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningInputSanitizer;
use Throwable;

final class DefaultAgentConversationSummarizer implements AgentConversationSummarizer
{
    public function __construct(
        private readonly ?AgentModelRouter $router = null,
        private readonly ?AgentModelGateway $gateway = null,
        private readonly ?AgentPlanningInputSanitizer $sanitizer = null,
        private readonly int $messageThreshold = 12,
        private readonly int $tokenThreshold = 4000,
    ) {}

    public function shouldSummarize(int $messageCount, int $approxTokens): bool
    {
        return $messageCount >= $this->messageThreshold || $approxTokens >= $this->tokenThreshold;
    }

    public function summarize(AgentSummarizationRequest $request): AgentConversationSummary
    {
        $sanitizer = $this->sanitizer ?? new AgentPlanningInputSanitizer;
        $safeMessages = [];
        foreach ($request->messages as $msg) {
            if (! is_array($msg)) {
                continue;
            }
            $safeMessages[] = $sanitizer->sanitize([
                'role' => $msg['role'] ?? '',
                'content' => isset($msg['content']) ? mb_substr((string) $msg['content'], 0, 500) : '',
                'message_type' => $msg['message_type'] ?? '',
            ]);
        }

        if ($this->router === null || $this->gateway === null) {
            return $this->deterministicFallback($safeMessages, $request->workingContext);
        }

        try {
            $selection = $this->router->resolve(new AgentModelRoutingContext(
                taskType: 'conversation_summary',
                requiresStructuredOutput: true,
                connectionId: isset($request->workingContext['connection_id'])
                    ? (int) $request->workingContext['connection_id']
                    : null,
                siteRef: isset($request->workingContext['site_ref'])
                    ? (string) $request->workingContext['site_ref']
                    : null,
            ));
            $result = $this->gateway->summarize(
                new AgentSummarizationRequest($safeMessages, $request->workingContext, $request->locale),
                $selection,
            );

            return $result['summary'];
        } catch (Throwable) {
            return $this->deterministicFallback($safeMessages, $request->workingContext);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $workingContext
     */
    private function deterministicFallback(array $messages, array $workingContext): AgentConversationSummary
    {
        $bits = [];
        foreach (array_slice($messages, -6) as $msg) {
            $role = (string) ($msg['role'] ?? '');
            $content = (string) ($msg['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $bits[] = $role.': '.mb_substr($content, 0, 120);
        }

        return new AgentConversationSummary(
            text: implode("\n", $bits),
            version: 0,
            untilMessageId: null,
            payload: [
                'source' => 'deterministic_fallback',
                'active_refs' => [
                    'site_ref' => $workingContext['site_ref'] ?? null,
                    'project_ref' => $workingContext['project_ref'] ?? null,
                ],
            ],
        );
    }
}
