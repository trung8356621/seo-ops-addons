<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Contracts\DecisionModelCompletion;
use Omnichannel\Addons\AiPrompt\Contracts\ResolvedDecisionModel;
use Omnichannel\Addons\AiPrompt\Services\Ai\ClaudeMessagesClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Services\Ai\GeminiGenerateContentClient;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use RuntimeException;

/**
 * Executes a bounded decision prompt on the AI Settings connection.
 * The API key stays on the connection model and is never returned.
 */
final class DecisionModelCompletionService implements DecisionModelCompletion
{
    public function complete(ResolvedDecisionModel $model, string $prompt, int $maxOutputTokens): string
    {
        $connection = ApiConnection::query()->find($model->connectionId);
        if (! $connection instanceof ApiConnection) {
            throw new RuntimeException('Decision model connection is missing.');
        }

        $options = [
            'max_output' => max(128, $maxOutputTokens),
            'temperature' => 0,
        ];
        $provider = strtolower((string) $connection->provider);
        $result = match ($provider) {
            ApiConnectionProviders::DEEPSEEK => app(DeepSeekChatClient::class)->generate($connection, $prompt, $model->model, $options),
            ApiConnectionProviders::GEMINI => app(GeminiGenerateContentClient::class)->generate($connection, $prompt, $model->model, $options),
            ApiConnectionProviders::CLAUDE => app(ClaudeMessagesClient::class)->generate($connection, $prompt, $model->model, $options),
            default => app(OpenAiCompatibleProtocolAdapter::class)->generate($connection, $prompt, $model->model, $options),
        };

        $text = is_array($result) ? ($result[0] ?? '') : '';
        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Decision model returned an empty completion.');
        }

        return $text;
    }
}
