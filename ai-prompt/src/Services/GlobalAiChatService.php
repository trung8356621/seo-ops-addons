<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\ApiConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * @deprecated HTTP routes removed — Agent Workspace is sole chat/execution surface.
 * Service retained temporarily; do not re-register routes or add new callers.
 */
final class GlobalAiChatService
{
    private const MAX_HISTORY_MESSAGES = 12;

    private const SYSTEM_PROMPT = 'Bạn là trợ lý AI cho hệ thống quản trị SEO. '
        .'Trả lời trực tiếp, rõ ràng và ưu tiên tiếng Việt. Không bịa dữ liệu chưa được cung cấp.';

    /**
     * @return list<array{id: int, label: string, provider: string}>
     */
    public function availableModels(): array
    {
        return $this->availableModelsQuery()
            ->with('apiConnection:id,name,provider')
            ->orderByDesc('priority')
            ->orderBy('display_name')
            ->get()
            ->map(static function (SeoAiModel $model): array {
                $connection = $model->apiConnection;
                $provider = strtolower((string) ($connection?->provider ?? ''));

                return [
                    'id' => (int) $model->id,
                    'label' => trim((string) $model->display_name)
                        .' · '.strtoupper($provider)
                        .' · '.trim((string) ($connection?->name ?? 'AI')),
                    'provider' => $provider,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $history
     * @return array{answer: string, model: string, provider: string}
     */
    public function chat(
        int $modelId,
        string $message,
        array $history = [],
        ?UploadedFile $image = null,
    ): array {
        $model = $this->resolveModel($modelId);
        $connection = $model->apiConnection;
        if (! $connection instanceof ApiConnection) {
            throw new PromptRunException('Không tìm thấy kết nối AI của model đã chọn.');
        }

        $message = trim($message);
        if ($message === '' && $image === null) {
            throw new PromptRunException('Nhập nội dung hoặc chọn một hình ảnh.');
        }

        $history = $this->normalizeHistory($history);
        $imageData = $image !== null ? $this->encodeImage($image) : null;
        $provider = strtolower((string) $connection->provider);

        $answer = match ($provider) {
            'gemini' => $this->callGemini($connection, $model, $message, $history, $imageData),
            'claude' => $this->callClaude($connection, $model, $message, $history, $imageData),
            default => throw new PromptRunException('Nhà cung cấp AI không được hỗ trợ: '.$provider),
        };

        return [
            'answer' => $answer,
            'model' => (string) $model->raw_model_name,
            'provider' => $provider,
        ];
    }

    private function availableModelsQuery()
    {
        $userId = (int) (auth()->id() ?? 0);
        $ownerId = (int) (SeoAccessControl::accountOwnerId() ?? 0);
        $allowedUserIds = array_values(array_unique(array_filter([$userId, $ownerId])));

        return SeoAiModel::query()
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->whereIn('category', [
                AiModelCategory::GEMINI_FLASH,
                AiModelCategory::GEMINI_PRO,
                AiModelCategory::CLAUDE_HAIKU,
                AiModelCategory::CLAUDE_SONNET,
                AiModelCategory::CLAUDE_OPUS,
            ])
            ->whereHas('apiConnection', static function ($query) use ($allowedUserIds): void {
                $query->where('status', 'active')
                    ->where(function ($scope) use ($allowedUserIds): void {
                        $scope->where('is_global', true);
                        if ($allowedUserIds !== []) {
                            $scope->orWhereIn('user_id', $allowedUserIds);
                        }
                    });
            });
    }

    private function resolveModel(int $modelId): SeoAiModel
    {
        $model = $this->availableModelsQuery()
            ->with('apiConnection')
            ->find($modelId);

        if (! $model instanceof SeoAiModel) {
            throw new PromptRunException('Model AI không tồn tại hoặc bạn không có quyền sử dụng.');
        }

        if (blank($model->apiConnection?->api_key)) {
            throw new PromptRunException('Kết nối AI chưa có API key.');
        }

        return $model;
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function normalizeHistory(array $history): array
    {
        $normalized = [];
        foreach (array_slice($history, -self::MAX_HISTORY_MESSAGES) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 12000),
            ];
        }

        return $normalized;
    }

    /**
     * @return array{mime_type: string, data: string}
     */
    private function encodeImage(UploadedFile $image): array
    {
        $contents = file_get_contents($image->getRealPath());
        if (! is_string($contents) || $contents === '') {
            throw new PromptRunException('Không đọc được hình ảnh đã chọn.');
        }

        return [
            'mime_type' => (string) ($image->getMimeType() ?: 'image/jpeg'),
            'data' => base64_encode($contents),
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{mime_type: string, data: string}|null  $image
     */
    private function callGemini(
        ApiConnection $connection,
        SeoAiModel $model,
        string $message,
        array $history,
        ?array $image,
    ): string {
        $contents = collect($history)
            ->map(static fn (array $item): array => [
                'role' => $item['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $item['content']]],
            ])
            ->values()
            ->all();

        $parts = [];
        if ($message !== '') {
            $parts[] = ['text' => $message];
        }
        if ($image !== null) {
            $parts[] = ['inline_data' => $image];
        }
        $contents[] = ['role' => 'user', 'parts' => $parts];

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode((string) $model->raw_model_name),
        );

        $response = Http::timeout(180)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key])
            ->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => self::SYSTEM_PROMPT]],
                ],
                'contents' => $contents,
            ]);

        if (! $response->successful()) {
            throw new PromptRunException(
                'Gemini API lỗi: '.mb_substr((string) (
                    $response->json('error.message') ?? $response->body()
                ), 0, 1000),
            );
        }

        $answer = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if (trim($answer) === '') {
            throw new PromptRunException('Gemini không trả về nội dung.');
        }

        return trim($answer);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{mime_type: string, data: string}|null  $image
     */
    private function callClaude(
        ApiConnection $connection,
        SeoAiModel $model,
        string $message,
        array $history,
        ?array $image,
    ): string {
        $messages = collect($history)
            ->map(static fn (array $item): array => [
                'role' => $item['role'],
                'content' => $item['content'],
            ])
            ->values()
            ->all();

        $content = [];
        if ($image !== null) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image['mime_type'],
                    'data' => $image['data'],
                ],
            ];
        }
        if ($message !== '') {
            $content[] = ['type' => 'text', 'text' => $message];
        }
        $messages[] = ['role' => 'user', 'content' => $content];

        $response = Http::timeout(180)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $connection->api_key,
                'anthropic-version' => '2023-06-01',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) $model->raw_model_name,
                'max_tokens' => 4096,
                'system' => self::SYSTEM_PROMPT,
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            throw new PromptRunException(
                'Claude API lỗi: '.mb_substr((string) (
                    $response->json('error.message') ?? $response->body()
                ), 0, 1000),
            );
        }

        $answer = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->filter()
            ->implode("\n");

        if (trim($answer) === '') {
            throw new PromptRunException('Claude không trả về nội dung.');
        }

        return trim($answer);
    }
}
