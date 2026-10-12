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
        private readonly AnswerEvidenceProjector $answerEvidence = new AnswerEvidenceProjector(),
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
    /**
     * @param  list<string>  $unresolvedRequirements
     */
    public function buildAnswerInput(
        AgentProjectScope $scope,
        string $userMessage,
        array $history,
        RetrievalBundle $bundle,
        string $selectedResponseTemplate,
        string $selectedResponseLanguage = 'en',
        array $unresolvedRequirements = [],
    ): PreparedModelInput {
        if (! AgentResponseTemplateCatalog::supports($selectedResponseTemplate)) {
            throw new \InvalidArgumentException('Selected response template is unknown.');
        }
        if (! in_array($selectedResponseLanguage, ['vi', 'en'], true)) {
            throw new \InvalidArgumentException('Selected response language is unknown.');
        }
        $evidence = $this->answerEvidence->project($bundle, $userMessage);
        $user = json_encode([
            'scope' => $scope->toArray(),
            'message' => $userMessage,
            'conversation' => $this->compactHistory($history),
            'retrieval_bundle' => $evidence['bundle'],
            'analysis_task' => $evidence['analysis_task'],
            'missing_capabilities' => SeoAccessCapabilityCatalog::missing(),
            'response_contract' => [
                'shape' => 'AgentResponse JSON with message, blocks, actions.',
                'evidence' => 'Chart and factual table numbers must come from retrieval_bundle sources whose status is ok. Never treat a partial page as a full-site count.',
                'read' => 'For factual READ requests, present retrieved facts only. Do not generate recommendations or Draft candidates.',
                'coverage_links' => 'Use only supplied coverage_href values for Strong, Medium, and Weak filter links. Never construct navigation URLs.',
                'recommendations' => 'For explicit content recommendation/opportunity/improvement requests, use concise table blocks grouped by existing Topic when available. Each distinct candidate includes title, keyword when identifiable, Topic/topic_ref when evidenced, short reason, type new|rewrite|improve, source attribution, and an item object. Clearly mark generated/inferred new ideas and never invent refs or metrics.',
                'draft_intake' => [
                    'action' => 'content_project.draft.intake',
                    'user_initiated' => true,
                    'supported_types' => ['new', 'rewrite', 'improve'],
                    'source_type' => 'agent',
                    'rule' => 'An actionable table is allowed for valid candidates in site scope. It only exposes selection controls; never submit automatically. Omit actionable metadata when a candidate cannot be submitted safely.',
                ],
            ],
            'selected_response_template' => $selectedResponseTemplate,
            'selected_response_language' => $selectedResponseLanguage,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($unresolvedRequirements !== []) {
            $payload = json_decode(is_string($user) ? $user : '{}', true);
            if (is_array($payload)) {
                $payload['unresolved_requirements'] = array_values($unresolvedRequirements);
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $user = is_string($encoded) ? $encoded : $user;
            }
        }

        return PreparedModelInput::make('answer', [
            ['role' => 'system', 'content' => $this->systemInstruction('agent.response.compose')],
            ['role' => 'user', 'content' => is_string($user) ? $user : ''],
        ], $this->redactor);
    }

    public function buildTopicSuggestionsInput(
        AgentProjectScope $scope,
        RetrievalBundle $bundle,
        string $language = 'vi',
    ): PreparedModelInput {
        $topics = $this->extractCompactTopics($bundle);
        $user = json_encode([
            'language' => $language,
            'max_suggestions' => 5,
            'site_context' => array_filter([
                'site_ref' => $scope->siteRef,
            ]),
            'topics' => $topics,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return PreparedModelInput::make('topic_suggestions', [
            ['role' => 'system', 'content' => $this->systemInstruction('agent.topic_suggestions.generate')],
            ['role' => 'user', 'content' => is_string($user) ? $user : '{}'],
        ], $this->redactor);
    }

    /**
     * @return list<array{topic_ref: string, name: string, coverage: string, article_count?: int}>
     */
    public function extractCompactTopics(RetrievalBundle $bundle): array
    {
        $allTopics = [];
        foreach ($bundle->sources as $source) {
            $topics = is_array($source->data['topics'] ?? null) ? $source->data['topics'] : [];
            foreach ($topics as $topic) {
                if (! is_array($topic)) {
                    continue;
                }
                $ref = trim((string) ($topic['topic_ref'] ?? ''));
                if ($ref === '' && isset($topic['id'])) {
                    $ref = 'topic:'.$topic['id'];
                }
                $name = trim((string) ($topic['name'] ?? ''));
                if ($ref === '' || $name === '') {
                    continue;
                }
                $coverage = trim((string) ($topic['coverage'] ?? 'unknown'));
                $articleCount = isset($topic['article_count']) && is_numeric($topic['article_count']) ? (int) $topic['article_count'] : null;

                $item = [
                    'topic_ref' => $ref,
                    'name' => $name,
                    'coverage' => $coverage,
                ];
                if ($articleCount !== null) {
                    $item['article_count'] = $articleCount;
                }
                $allTopics[$ref] = $item;
            }
        }

        $sorted = array_values($allTopics);
        usort($sorted, static function (array $a, array $b): int {
            $priority = static function (array $t): int {
                $cov = strtolower($t['coverage'] ?? '');
                $count = $t['article_count'] ?? 10;
                if ($cov === 'weak' || $cov === 'none' || $count === 0) {
                    return 0;
                }
                if ($cov === 'medium' || $cov === 'low') {
                    return 1;
                }

                return 2;
            };

            return $priority($a) <=> $priority($b);
        });

        return array_slice($sorted, 0, 8);
    }

    /**
     * @return list<string>
     */
    public function extractAllowedTopicRefs(RetrievalBundle $bundle): array
    {
        $compact = $this->extractCompactTopics($bundle);

        return array_map(static fn (array $t): string => $t['topic_ref'], $compact);
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
