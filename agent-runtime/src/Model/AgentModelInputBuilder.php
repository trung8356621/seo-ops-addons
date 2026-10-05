<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\SeoAccessCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponseTemplateCatalog;
use Omnichannel\Addons\Seo\Contracts\ResolvesSettingsPromptHook;

/**
 * SSOT for model-visible input. Copy and Send both use the returned object.
 */
final class AgentModelInputBuilder
{
    public function __construct(
        private readonly SecretRedactor $redactor = new SecretRedactor(),
        private readonly ?ResolvesSettingsPromptHook $promptBindings = null,
        private readonly AgentModelEvidenceSanitizer $evidenceSanitizer = new AgentModelEvidenceSanitizer(),
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function buildRoutingInput(
        AgentProjectScope $scope,
        string $userMessage,
        array $history,
    ): PreparedModelInput {
        $user = json_encode([
            'scope' => $scope->toArray(),
            'message' => $userMessage,
            'conversation' => $this->compactHistory($history),
            'capability_catalog' => AgentCapabilityCatalog::modelVisible(),
            'response_catalog' => AgentResponseTemplateCatalog::modelVisible(),
            'facts' => [
                'global_retrieval' => 'unsupported',
                'gsc_force_sync' => 'unsupported',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return PreparedModelInput::make('routing', [
            ['role' => 'system', 'content' => $this->systemInstruction('agent.routing.decide')],
            ['role' => 'user', 'content' => is_string($user) ? $user : ''],
        ], $this->redactor);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function buildAnswerInput(
        AgentProjectScope $scope,
        string $userMessage,
        array $history,
        RetrievalBundle $bundle,
        string $selectedResponseTemplate,
        string $selectedResponseLanguage = 'en',
    ): PreparedModelInput {
        if (! AgentResponseTemplateCatalog::supports($selectedResponseTemplate)) {
            throw new \InvalidArgumentException('Selected response template is unknown.');
        }
        if (! in_array($selectedResponseLanguage, ['vi', 'en'], true)) {
            throw new \InvalidArgumentException('Selected response language is unknown.');
        }
        $user = json_encode([
            'scope' => $scope->toArray(),
            'message' => $userMessage,
            'conversation' => $this->compactHistory($history),
            'retrieval_bundle' => $this->evidenceSanitizer->sanitize($bundle),
            'missing_capabilities' => SeoAccessCapabilityCatalog::missing(),
            'response_contract' => 'AgentResponse JSON with message, blocks, actions. Chart and table numbers must come from retrieval_bundle sources whose status is ok.',
            'selected_response_template' => $selectedResponseTemplate,
            'selected_response_language' => $selectedResponseLanguage,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return PreparedModelInput::make('answer', [
            ['role' => 'system', 'content' => $this->systemInstruction('agent.response.compose')],
            ['role' => 'user', 'content' => is_string($user) ? $user : ''],
        ], $this->redactor);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function compactHistory(array $history): array
    {
        $tail = array_slice($history, -6);
        $out = [];
        foreach ($tail as $message) {
            if (! is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            if (! in_array($role, ['user', 'assistant'], true)) {
                $role = 'user';
            }
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if (strlen($content) > 800) {
                $content = substr($content, 0, 800);
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    private function systemInstruction(string $hookKey): string
    {
        $resolver = $this->promptBindings ?? app(ResolvesSettingsPromptHook::class);
        $content = trim((string) $resolver->resolveSettingsHook($hookKey)->markdown_content);
        if ($content === '') {
            throw new \RuntimeException("Bound Agent Runtime prompt [{$hookKey}] has empty markdown_content.");
        }

        return $content;
    }
}
