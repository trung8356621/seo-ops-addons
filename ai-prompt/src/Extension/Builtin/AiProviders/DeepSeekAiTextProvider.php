<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Builtin\AiProviders;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiProviderHealthResult;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextRequest;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextResult;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use App\Models\ApiConnection;

final class DeepSeekAiTextProvider implements AiTextProviderInterface
{
    public function __construct(
        private readonly DeepSeekChatClient $client,
    ) {}

    public function key(): string
    {
        return ApiConnectionProviders::DEEPSEEK;
    }

    public function supportsModel(string $model): bool
    {
        $model = strtolower(trim($model));

        return $model === '' || str_starts_with($model, 'deepseek');
    }

    public function generate(AiTextRequest $request, AiExecutionContext $context): AiTextResult
    {
        $connection = $this->resolveConnection($context);
        if ($connection === null) {
            return AiTextResult::failure('Không tìm thấy kết nối DeepSeek hợp lệ (connectionId).', $request->model);
        }
        if ($connection->provider !== ApiConnectionProviders::DEEPSEEK) {
            return AiTextResult::failure('Kết nối được cung cấp không phải DeepSeek.', $request->model);
        }
        if (blank($connection->api_key)) {
            return AiTextResult::failure('Kết nối DeepSeek chưa có API Key.', $request->model);
        }

        try {
            [$text, $usage] = $this->client->generate(
                $connection,
                $request->prompt,
                $request->model,
                $request->options,
            );

            return AiTextResult::success($text, $request->model, $usage);
        } catch (PromptRunException $exception) {
            return AiTextResult::failure($exception->getMessage(), $request->model);
        }
    }

    public function health(AiExecutionContext $context): AiProviderHealthResult
    {
        if ($context->connectionId === null) {
            return AiProviderHealthResult::healthy('DeepSeek text provider registered; connection-specific health requires connectionId context.');
        }

        $connection = $this->resolveConnection($context);
        if ($connection === null) {
            return AiProviderHealthResult::unhealthy("Không tìm thấy kết nối #{$context->connectionId}.");
        }
        if ($connection->provider !== ApiConnectionProviders::DEEPSEEK) {
            return AiProviderHealthResult::unhealthy('Kết nối được cung cấp không phải DeepSeek.');
        }
        if (blank($connection->api_key)) {
            return AiProviderHealthResult::unhealthy('Kết nối DeepSeek chưa có API Key.');
        }

        return AiProviderHealthResult::healthy('Kết nối DeepSeek hợp lệ.');
    }

    private function resolveConnection(AiExecutionContext $context): ?ApiConnection
    {
        if ($context->connectionId === null) {
            return null;
        }

        return ApiConnection::query()->find($context->connectionId);
    }
}
