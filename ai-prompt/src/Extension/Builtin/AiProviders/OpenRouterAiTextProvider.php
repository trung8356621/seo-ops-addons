<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiProviderHealthResult;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextRequest;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextResult;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use App\Models\ApiConnection;

final class OpenRouterAiTextProvider implements AiTextProviderInterface
{
    public function __construct(
        private readonly OpenAiCompatibleProtocolAdapter $adapter,
    ) {}

    public function key(): string
    {
        return ApiConnectionProviders::OPENROUTER;
    }

    public function supportsModel(string $model): bool
    {
        return trim($model) !== '';
    }

    public function generate(AiTextRequest $request, AiExecutionContext $context): AiTextResult
    {
        $connection = $this->resolveConnection($context);
        if ($connection === null) {
            return AiTextResult::failure('Không tìm thấy kết nối OpenRouter hợp lệ (connectionId).', $request->model);
        }
        if ($connection->provider !== ApiConnectionProviders::OPENROUTER) {
            return AiTextResult::failure('Kết nối được cung cấp không phải OpenRouter.', $request->model);
        }
        if (blank($connection->api_key)) {
            return AiTextResult::failure('Kết nối OpenRouter chưa có API Key.', $request->model);
        }

        try {
            [$text, $usage] = $this->adapter->generate(
                $connection,
                $request->prompt,
                $request->model,
                $request->options,
            );

            $resolved = '';
            if (is_array($usage)) {
                $resolved = trim((string) ($usage['resolved_model'] ?? ''));
                $usage['requested_model'] = $request->model;
            }

            return AiTextResult::success($text, $resolved !== '' ? $resolved : $request->model, $usage);
        } catch (PromptRunException $exception) {
            return AiTextResult::failure($exception->getMessage(), $request->model);
        }
    }

    public function health(AiExecutionContext $context): AiProviderHealthResult
    {
        if ($context->connectionId === null) {
            return AiProviderHealthResult::healthy('OpenRouter text provider registered; connection-specific health requires connectionId context.');
        }

        $connection = $this->resolveConnection($context);
        if ($connection === null) {
            return AiProviderHealthResult::unhealthy("Không tìm thấy kết nối #{$context->connectionId}.");
        }
        if ($connection->provider !== ApiConnectionProviders::OPENROUTER) {
            return AiProviderHealthResult::unhealthy('Kết nối được cung cấp không phải OpenRouter.');
        }
        if (blank($connection->api_key)) {
            return AiProviderHealthResult::unhealthy('Kết nối OpenRouter chưa có API Key.');
        }

        return AiProviderHealthResult::healthy('Kết nối OpenRouter hợp lệ.');
    }

    private function resolveConnection(AiExecutionContext $context): ?ApiConnection
    {
        if ($context->connectionId === null) {
            return null;
        }

        return ApiConnection::query()->find($context->connectionId);
    }
}
