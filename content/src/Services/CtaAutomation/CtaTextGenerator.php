<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\AiPrompt\DataTransfer\PromptExecutionResult;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutesExhaustedException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\InteractivePromptExecutor;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use RuntimeException;

/**
 * One batch CTA copy call through the shared interactive executor.
 */
class CtaTextGenerator
{
    public const HOOK_KEY = 'article.cta.generate';

    public const ROUTING_POLICY = AiRoutingPolicy::FreeOnly;

    public function __construct(
        private readonly InteractivePromptExecutor $executor,
        private readonly CtaOutputValidator $validator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $placements
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, ctas: list<array{placement_id: string, section_id: string, text: string}>, errors: list<array{placement_id: string, code: string}>, execution: array<string, mixed>}
     */
    public function generate(string $language, string $tone, string $businessContext, array $placements, array $sections, array $context = []): array
    {
        $expected = [];
        foreach ($placements as $placement) {
            $expected[] = [
                'placement_id' => (string) $placement['placement_id'],
                'section_id' => (string) $placement['section_id'],
                'alias' => $placement['alias'] ?? null,
            ];
        }
        $prompt = $this->compile($language, $tone, $businessContext, $placements, $sections);
        $errors = [];
        $parsed = [];
        $execution = $this->emptyExecution();
        $historyId = $this->openHistory($context);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $body = $attempt === 0
                ? $prompt
                : $prompt."\n\nPrevious output failed validation codes: ".implode(',', array_column($errors, 'code')).'. Return JSON only.';
            try {
                $result = $this->executor->executeCompiled(
                    $body,
                    self::HOOK_KEY,
                    null,
                    self::ROUTING_POLICY,
                    null,
                    $historyId !== null ? ['prompt_result_id' => $historyId] : [],
                );
            } catch (AiRoutesExhaustedException $exception) {
                Log::warning('cta.free_models_unavailable', ['error_type' => $exception::class]);
                $this->closeHistory($historyId, null, 'failed');

                return [
                    'ok' => false,
                    'ctas' => [],
                    'errors' => [['placement_id' => '', 'code' => 'no_free_model']],
                    'execution' => $execution,
                ];
            }
            if ($result->routingPolicyEffective !== AiRoutingPolicy::FreeOnly
                || ($result->candidate !== null && ! $result->candidate->isFree)) {
                $this->closeHistory($historyId, $result, 'failed');

                return [
                    'ok' => false,
                    'ctas' => [],
                    'errors' => [['placement_id' => '', 'code' => 'paid_route_blocked']],
                    'execution' => $this->executionFrom($result, $historyId),
                ];
            }
            $execution = $this->executionFrom($result, $historyId);
            $parsed = $this->decode((string) $result->text);
            if ($parsed === null) {
                $errors = [['placement_id' => '', 'code' => 'invalid_json']];
                continue;
            }
            $validated = $this->validator->validate($expected, $parsed, $language);
            if ($validated['ok']) {
                $this->closeHistory($historyId, $result, 'completed');
                $validated['execution'] = $execution;

                return $validated;
            }
            $errors = $validated['errors'];
        }

        $this->closeHistory($historyId, null, 'failed');

        return ['ok' => false, 'ctas' => [], 'errors' => $errors, 'execution' => $execution];
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @param  list<array<string, mixed>>  $sections
     */
    public function compile(string $language, string $tone, string $businessContext, array $placements, array $sections): string
    {
        $template = $this->template();
        $plan = [];
        $contexts = [];
        $bySection = [];
        foreach ($sections as $section) {
            $bySection[(string) $section['section_id']] = $section;
        }
        foreach ($placements as $placement) {
            $sectionId = (string) $placement['section_id'];
            $section = $bySection[$sectionId] ?? [];
            $plan[] = [
                'placement_id' => $placement['placement_id'],
                'section_id' => $sectionId,
                'intent' => $placement['intent'],
                'allowed_shortcode' => $placement['alias'],
            ];
            $contexts[] = [
                'section_id' => $sectionId,
                'heading' => (string) ($section['heading'] ?? ''),
                'content' => mb_substr((string) ($section['content'] ?? ''), 0, 700),
            ];
        }
        $replacements = [
            '{{language}}' => $language,
            '{{tone}}' => $tone !== '' ? $tone : 'informative',
            '{{business_context}}' => $businessContext !== '' ? $businessContext : 'general',
            '{{cta_plan}}' => json_encode($plan, JSON_UNESCAPED_UNICODE) ?: '[]',
            '{{section_contexts}}' => json_encode($contexts, JSON_UNESCAPED_UNICODE) ?: '[]',
        ];

        return strtr($template, $replacements);
    }

    private function template(): string
    {
        $path = dirname(__DIR__, 4).'/ai-prompt/resources/prompt-hooks/v01/article.cta.generate@0.1.0.json';
        if (! is_file($path)) {
            throw new RuntimeException('cta_prompt_missing');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $template = is_array($decoded['template'] ?? null) ? $decoded['template'] : [];
        $system = trim((string) ($template['system'] ?? ''));
        $user = trim((string) ($template['user'] ?? ''));
        if ($system === '' || $user === '') {
            throw new RuntimeException('cta_prompt_missing');
        }

        return $system."\n\n".$user;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function decode(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded) || ! is_array($decoded['ctas'] ?? null)) {
            return null;
        }

        return $decoded['ctas'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function openHistory(array $context): ?int
    {
        try {
            $promptId = (new CtaExecutionHistoryWriter())->ensurePrompt();
            if ($promptId === null) {
                return null;
            }
            $result = PromptResult::query()->create([
                'prompt_id' => $promptId,
                'user_id' => (int) ($context['user_id'] ?? 0) > 0 ? (int) $context['user_id'] : (int) (auth()->id() ?: 1),
                'site_id' => (int) ($context['site_id'] ?? 0),
                'status' => 'running',
                'canonical_prompt_key' => self::HOOK_KEY,
                'stage' => self::HOOK_KEY,
                'correlation_id' => (string) ($context['run_id'] ?? ''),
                'input_snapshot' => [
                    'hook_key' => self::HOOK_KEY,
                    'article_id' => $context['article_id'] ?? null,
                    'cta_run_id' => $context['run_id'] ?? null,
                    'prompt_version' => '0.1.0',
                ],
                'started_at' => now(),
            ]);

            return (int) $result->getKey();
        } catch (\Throwable $exception) {
            Log::warning('cta.history_persist_failed', ['error_type' => $exception::class]);

            return null;
        }
    }

    private function closeHistory(?int $id, ?PromptExecutionResult $result, string $status): void
    {
        if ($id === null || $id <= 0) {
            return;
        }
        try {
            $usage = $result !== null && is_array($result->usage) ? $result->usage : null;
            $row = PromptResult::query()->find($id);
            if ($row === null) {
                return;
            }
            $snapshot = is_array($row->input_snapshot) ? $row->input_snapshot : [];
            if ($result !== null) {
                $candidate = $result->candidate;
                $snapshot['routing_policy'] = $result->routingPolicyEffective->value;
                $snapshot['provider'] = $candidate?->provider;
                $snapshot['connection_id'] = $candidate !== null ? (int) $candidate->connection->id : null;
                $snapshot['candidate_model'] = $candidate?->model;
                $snapshot['is_free_candidate'] = $candidate?->isFree;
                $actual = is_array($usage) ? ($usage['resolved_model'] ?? $usage['actual_provider_model'] ?? null) : null;
                if (is_string($actual) && trim($actual) !== '') {
                    $snapshot['actual_provider_model'] = trim($actual);
                }
            }
            $row->status = $status === 'completed' ? 'completed' : 'failed';
            $row->output_text = $status === 'completed' && $result !== null ? $result->text : null;
            $row->token_usage = $usage;
            $row->input_snapshot = $snapshot;
            $row->finished_at = now();
            $row->save();
        } catch (\Throwable $exception) {
            Log::warning('cta.history_persist_failed', ['error_type' => $exception::class]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function executionFrom(PromptExecutionResult $result, ?int $historyId): array
    {
        $candidate = $result->candidate;
        $usage = is_array($result->usage) ? $result->usage : [];
        $input = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $output = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;

        return [
            'prompt_hook' => self::HOOK_KEY,
            'prompt_version' => '0.1.0',
            'connection_id' => $candidate !== null ? (int) $candidate->connection->id : null,
            'provider' => $candidate?->provider,
            'model' => $candidate?->model,
            'execution_id' => isset($result->meta['execution_id']) ? (string) $result->meta['execution_id'] : null,
            'prompt_result_id' => $historyId,
            'input_tokens' => is_numeric($input) ? (int) $input : null,
            'output_tokens' => is_numeric($output) ? (int) $output : null,
            'routing_policy' => $result->routingPolicyEffective->value,
            'is_free' => $candidate?->isFree,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyExecution(): array
    {
        return [
            'prompt_hook' => self::HOOK_KEY,
            'prompt_version' => '0.1.0',
            'connection_id' => null,
            'provider' => null,
            'model' => null,
            'execution_id' => null,
            'prompt_result_id' => null,
            'input_tokens' => null,
            'output_tokens' => null,
        ];
    }
}
