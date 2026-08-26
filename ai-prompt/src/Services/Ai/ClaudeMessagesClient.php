<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Ai;

use Anthropic;
use Anthropic\Exceptions\ErrorException;
use Anthropic\Exceptions\TransporterException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use App\Models\ApiConnection;
use GuzzleHttp\Client;
use Throwable;

/**
 * Real Anthropic Messages API client for a single compiled prompt string.
 *
 * This is intentionally simpler than `AiExecutionService::executeClaude`, which still owns
 * the task-mode / sub-task chaining flow used internally by PromptRunnerService. This client
 * backs the extension boundary (`ClaudeAiTextProvider::generate`) used by AiProviderResolver
 * and any external/plugin caller that only needs "prompt in, text out".
 */
final class ClaudeMessagesClient
{
    private const HTTP_TIMEOUT_SECONDS = 180.0;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 30.0;

    private const MAX_OUTPUT_TOKENS = 8192;

    private const DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    /**
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function generate(ApiConnection $connection, string $prompt, string $model): array
    {
        if (blank($connection->api_key)) {
            throw new PromptRunException('Kết nối Claude chưa có API Key.');
        }

        $modelName = trim($model) !== '' ? trim($model) : self::DEFAULT_MODEL;

        $client = Anthropic::factory()
            ->withApiKey((string) $connection->api_key)
            ->withHttpClient($this->createHttpClient())
            ->make();

        $payload = [
            'model' => $modelName,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        try {
            $response = $client->messages()->create($payload);

            // Preserve whitespace-only text blocks — do not use filled()/blank()
            // (Laravel blank(' ') === true would drop meaningful spacing).
            $text = collect($response->content)
                ->filter(static fn ($chunk): bool => $chunk->type === 'text'
                    && is_string($chunk->text)
                    && $chunk->text !== '')
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
                .($statusCode !== null ? " ({$statusCode})" : '')
                .($errorType !== null && $errorType !== '' ? " [{$errorType}]" : '')
                .': '.$exception->getMessage(),
                (int) ($statusCode ?? 0),
                $exception,
            );
        } catch (TransporterException $exception) {
            throw new PromptRunException(
                'Lỗi kết nối máy chủ Anthropic (timeout/mạng): '.$exception->getMessage(),
                0,
                $exception,
            );
        } catch (Throwable $th) {
            throw new PromptRunException('Lỗi không xác định khi gọi Claude: '.$th->getMessage(), (int) $th->getCode(), $th);
        }
    }

    private function createHttpClient(): Client
    {
        return new Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
        ]);
    }
}
