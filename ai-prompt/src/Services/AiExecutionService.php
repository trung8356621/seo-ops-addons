<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Anthropic;
use Anthropic\Exceptions\ErrorException;
use Anthropic\Exceptions\TransporterException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptPart;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;
use GuzzleHttp\Client;
use Throwable;

final class AiExecutionService
{
    private const HTTP_TIMEOUT_SECONDS = 180.0;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 30.0;

    private const MAX_OUTPUT_TOKENS = 8192;

    /**
     * Thực thi gọi API Claude qua mozex/anthropic-laravel (API key từ DB, không dùng .env).
     *
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function executeClaude(
        SeoPrompt $prompt,
        ?string $inputData = null,
        bool $isTaskMode = true,
        array $variables = [],
        ?string $modelOverride = null,
        ?string $compiledPrompt = null,
    ): array {
        $prompt->loadMissing(['aiConnection']);
        $variables = Utf8Sanitizer::variablesForAi($variables);
        $inputData = $inputData !== null ? Utf8Sanitizer::compactForAiVariable($inputData) : null;
        $compiledPrompt = $compiledPrompt !== null ? Utf8Sanitizer::string($compiledPrompt) : null;

        if (\Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline()) {
            throw new PromptRunException(
                'Prompt công cụ Hình ảnh phải chạy qua MediaGenerationService (Imagen/Nano Banana), không gọi executeClaude.',
            );
        }

        $connection = $prompt->aiConnection;
        if ($connection === null || $connection->provider !== 'claude') {
            throw new PromptRunException('Không tìm thấy kết nối Claude hợp lệ cho Prompt này.');
        }

        if ($connection->status !== 'active') {
            throw new PromptRunException('Kết nối AI đang tắt hoặc không khả dụng.');
        }

        if (blank($connection->api_key)) {
            throw new PromptRunException('Kết nối AI chưa có API Key.');
        }

        $apiKey = $connection->api_key;
        $modelName = trim((string) ($modelOverride ?? ''));
        if ($modelName === '') {
            $category = AiModelCategory::resolveForPrompt(
                filled($prompt->model_category ?? null) ? (string) $prompt->model_category : null,
                'claude',
            );
            $routed = app(AiModelRouterService::class)->getActiveModel((int) $connection->id, $category);
            $modelName = $routed !== null
                ? (string) $routed->raw_model_name
                : 'claude-sonnet-4-20250514';
        }

        $client = Anthropic::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($this->createHttpClient())
            ->make();

        $compiledPrompt = trim((string) $compiledPrompt);
        [$systemInstructions, $userMessages] = $compiledPrompt !== ''
            ? $this->buildMessagesFromCompiled($compiledPrompt, $inputData, $isTaskMode)
            : $this->buildMessagesFromParts($prompt, $variables, $isTaskMode, $inputData);

        if ($userMessages === [] && $systemInstructions === []) {
            throw new PromptRunException('Prompt không có nội dung thành phần nào.');
        }

        if ($userMessages === []) {
            throw new PromptRunException('Prompt không có khối nhiệm vụ (task) để gửi tới Claude.');
        }

        $payload = [
            'model' => $modelName,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => implode("\n\n---\n\n", $userMessages),
                ],
            ],
        ];

        if ($isTaskMode && $systemInstructions !== []) {
            $payload['system'] = implode("\n\n", $systemInstructions);
        }

        try {
            $response = $client->messages()->create($payload);

            $text = collect($response->content)
                ->filter(static fn ($chunk): bool => $chunk->type === 'text' && filled($chunk->text))
                ->map(static fn ($chunk): string => (string) $chunk->text)
                ->implode("\n");

            if ($text === '') {
                throw new PromptRunException('Claude không trả về nội dung.');
            }

            $usage = null;
            if (isset($response->usage) && method_exists($response->usage, 'toArray')) {
                $usage = $response->usage->toArray();
            }

            return [$text, $usage];
        } catch (PromptRunException $exception) {
            throw $exception;
        } catch (ErrorException $exception) {
            $statusCode = $exception->getStatusCode();
            $errorType = $exception->getErrorType();

            throw new PromptRunException(
                'Lỗi Anthropic API'
                . ($statusCode !== null ? " ({$statusCode})" : '')
                . ($errorType !== null && $errorType !== '' ? " [{$errorType}]" : '')
                . ': ' . $exception->getMessage(),
                (int) ($statusCode ?? 0),
                $exception,
            );
        } catch (TransporterException $exception) {
            throw new PromptRunException(
                'Lỗi kết nối máy chủ Anthropic (timeout/mạng): ' . $exception->getMessage(),
                0,
                $exception,
            );
        } catch (Throwable $th) {
            throw new PromptRunException('Lỗi không xác định khi gọi Claude: ' . $th->getMessage(), (int) $th->getCode(), $th);
        }
    }

    private function createHttpClient(): Client
    {
        return new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * Prompt đã compile (chuỗi Task hoặc run đầy đủ). Task mode: tách system (trước ---) / user (sau ---).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function buildMessagesFromCompiled(string $compiledPrompt, ?string $inputData, bool $isTaskMode): array
    {
        $systemInstructions = [];
        $userMessages = [];

        if ($isTaskMode && str_contains($compiledPrompt, "\n\n---\n\n")) {
            $segments = explode("\n\n---\n\n", $compiledPrompt);
            $stepContent = trim((string) array_pop($segments));
            $systemBody = trim(implode("\n\n---\n\n", $segments));

            if ($systemBody !== '') {
                $systemInstructions[] = $systemBody;
            }

            $userMessages[] = $stepContent !== '' ? $stepContent : $compiledPrompt;
        } else {
            $userMessages[] = $compiledPrompt;
        }

        if (! empty($inputData)) {
            $userMessages[] = "DỮ LIỆU ĐẦU VÀO CẦN XỬ LÝ:\n" . $inputData;
        }

        return [$systemInstructions, $userMessages];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: list<string>, 1: list<string>}
     */
    private function buildMessagesFromParts(
        SeoPrompt $prompt,
        array $variables,
        bool $isTaskMode,
        ?string $inputData,
    ): array {
        $parts = $prompt->resolvedParts();

        $systemInstructions = [];
        $userMessages = [];

        foreach ($parts as $part) {
            $type = strtolower((string) $part->role);
            $block = $this->buildPartBlock($part, $variables);
            if ($block === '') {
                continue;
            }

            if ($isTaskMode) {
                // Task mode: chỉ khối «task» vào user — tối ưu token; còn lại lên system.
                if ($type === 'task') {
                    $userMessages[] = $block;
                } else {
                    $systemInstructions[] = $block;
                }
            } else {
                // Test mode: toàn bộ block vào user để kiểm tra luồng thô.
                $userMessages[] = $block;
            }
        }

        if (! empty($inputData)) {
            $userMessages[] = "DỮ LIỆU ĐẦU VÀO CẦN XỬ LÝ:\n" . $inputData;
        }

        return [$systemInstructions, $userMessages];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function buildPartBlock(SeoPromptPart $part, array $variables): string
    {
        $content = trim($this->substituteVariables((string) $part->content, $variables));
        if ($content === '') {
            return '';
        }

        $blockTitle = filled($part->name) ? strtoupper((string) $part->name) . ":\n" : '';

        $meta = is_array($part->meta) ? $part->meta : [];
        $formatExtra = '';
        if (isset($meta['format']) && trim((string) $meta['format']) !== '') {
            $formatExtra = "\nFormat yêu cầu: " . $this->substituteVariables((string) $meta['format'], $variables);
        }

        $rules = trim((string) ($meta['rules'] ?? ''));
        if ($rules !== '') {
            $formatExtra .= "\nQuy tắc:\n" . $this->substituteVariables($rules, $variables);
        }

        if ($part->role === 'sub_task') {
            $specific = trim((string) ($meta['specific_constraints'] ?? ''));
            if ($specific !== '') {
                $formatExtra .= "\nRàng buộc riêng (sub-prompt):\n"
                    . $this->substituteVariables($specific, $variables);
            }
        }

        return $blockTitle . $content . $formatExtra;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substituteVariables(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return array_key_exists($key, $variables)
                    ? (string) $variables[$key]
                    : $matches[0];
            },
            $text,
        );
    }
}
