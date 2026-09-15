<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Ai;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AiPrompt\Services\AiOutboundBudgetGate;
use Omnichannel\Addons\AiPrompt\Services\PromptBudgetPreflightService;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * OpenAI-compatible DeepSeek Chat Completions client.
 */
final class DeepSeekChatClient
{
    public const DEFAULT_BASE_URL = 'https://api.deepseek.com';

    private const HTTP_TIMEOUT_SECONDS = 180;

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function generate(ApiConnection $connection, string $prompt, string $model, array $options = []): array
    {
        if (blank($connection->api_key)) {
            throw new PromptRunException('Kết nối DeepSeek chưa có API Key.');
        }

        $modelName = trim($model);
        if ($modelName === '') {
            throw new PromptRunException(
                'Thiếu model DeepSeek từ routing. Đồng bộ catalog từ provider /models rồi chọn model trong AI Center.',
            );
        }

        $baseUrl = rtrim($this->baseUrl($connection), '/');
        $url = $baseUrl.'/chat/completions';

        $payload = [
            'model' => $modelName,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if (array_key_exists('temperature', $options) && $options['temperature'] !== null) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $omitCeiling = \Omnichannel\Addons\AiPrompt\Support\ArticleOutboundCeilingPolicy::shouldOmitApplicationCeiling($options);

        if (! $omitCeiling && isset($options['max_output']) && is_numeric($options['max_output'])) {
            $payload['max_tokens'] = (int) $options['max_output'];
        }

        // Final outbound invariant — blocks HTTP when payload exceeds verified budget.
        if (! $omitCeiling && function_exists('app') && app()->bound(PromptBudgetPreflightService::class)) {
            $gate = new AiOutboundBudgetGate(app(PromptBudgetPreflightService::class));
            $plan = $gate->verifyCompiled(
                null,
                $connection,
                $prompt,
                $modelName,
                'deepseek',
                is_string($options['hook_key'] ?? null) ? (string) $options['hook_key'] : null,
                $options,
            );
            if ($plan->requestedMaxOutputTokens > 0 && ! isset($payload['max_tokens'])) {
                $payload['max_tokens'] = $plan->requestedMaxOutputTokens;
            }
            $options['budget_plan_id'] = $plan->planId;
        }

        $this->applyThinkingMode($payload, $modelName, $options);

        $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->acceptJson()
            ->withToken((string) $connection->api_key)
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new PromptRunException(
                'Lỗi DeepSeek API ('.$response->status().'): '.$response->body(),
                $response->status(),
            );
        }

        $json = $response->json();
        $text = (string) data_get($json, 'choices.0.message.content', '');
        if (trim($text) === '') {
            throw new PromptRunException('DeepSeek không trả về nội dung.');
        }

        $usage = data_get($json, 'usage');
        $usageBag = is_array($usage) ? $usage : [];
        $finishReason = trim((string) data_get($json, 'choices.0.finish_reason', ''));
        if ($finishReason !== '') {
            $usageBag['finish_reason'] = $finishReason;
        }

        return [$text, $usageBag !== [] ? $usageBag : null];
    }

    /**
     * @return list<array{id: string, display_name: string}>
     */
    public function listModels(ApiConnection $connection): array
    {
        if (blank($connection->api_key)) {
            return [];
        }

        $url = rtrim($this->baseUrl($connection), '/').'/models';
        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken((string) $connection->api_key)
            ->get($url);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json('data', []);
        if (! is_array($data)) {
            return [];
        }

        $models = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $models[] = [
                'id' => $id,
                'display_name' => $id,
            ];
        }

        return $models;
    }

    public function baseUrl(ApiConnection $connection): string
    {
        if (function_exists('app')) {
            try {
                return app(ProviderConnectionResolver::class)->resolve($connection)->effectiveBaseUrl;
            } catch (\Throwable $exception) {
                throw new PromptRunException($exception->getMessage());
            }
        }

        return self::DEFAULT_BASE_URL;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    private function applyThinkingMode(array &$payload, string $modelName, array $options): void
    {
        $lower = strtolower($modelName);
        // Legacy reasoner always thinks; documented no-op for disable.
        if ($lower === 'deepseek-reasoner') {
            return;
        }

        $thinking = $options['thinking'] ?? null;
        $explicitEnable = $thinking === 'enabled'
            || $thinking === true
            || (is_array($thinking) && ($thinking['type'] ?? '') === 'enabled');
        $explicitDisable = $thinking === 'disabled'
            || $thinking === false
            || (is_array($thinking) && ($thinking['type'] ?? '') === 'disabled');

        $profile = strtolower(trim((string) ($options['execution_profile'] ?? '')));
        $hook = strtolower(trim((string) ($options['hook_key'] ?? '')));
        $reasoningContext = $profile === AiExecutionProfile::TextReasoning->value
            || str_contains($hook, 'outline')
            || str_contains($hook, 'vocabulary');

        if ($explicitDisable) {
            $payload['thinking'] = ['type' => 'disabled'];

            return;
        }

        if ($explicitEnable || $reasoningContext) {
            $payload['thinking'] = ['type' => 'enabled'];
        }
    }
}
