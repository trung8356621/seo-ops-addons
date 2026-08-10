<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Media\Services\GeminiMediaGenerationService;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class GeminiMediaGenerationImagenRetryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_transient_error_retries_then_succeeds(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                return Http::response([
                    'error' => [
                        'message' => 'fail to execute model; internal endpoint: /vertex/vertex-imagen-jpe-v1-8; target=anonymous server',
                        'status' => 'INTERNAL',
                    ],
                ], 500);
            }

            return Http::response([
                'predictions' => [
                    [
                        'bytesBase64Encoded' => base64_encode('PNGDATA'),
                        'mimeType' => 'image/png',
                    ],
                ],
            ], 200);
        });

        $service = $this->makeService(sleepLog: $sleeps);
        $connection = $this->fakeConnection();

        $result = $this->invokeImagenPredict($service, $connection, 'a product photo', 'imagen-4.0-generate-001');

        $this->assertSame(3, $calls);
        $this->assertSame([500, 1000], $sleeps);
        $this->assertSame('PNGDATA', $result['binary']);
        $this->assertSame('imagen-4.0-generate-001', $result['model_used']);
    }

    public function test_authentication_error_does_not_retry(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([
                'error' => [
                    'message' => 'API key not valid. Please pass a valid API key.',
                    'status' => 'UNAUTHENTICATED',
                ],
            ], 401);
        });

        $service = $this->makeService(sleepLog: $sleeps);
        $connection = $this->fakeConnection();

        try {
            $this->invokeImagenPredict($service, $connection, 'prompt', 'imagen-4.0-generate-001');
            $this->fail('Expected PromptRunException');
        } catch (PromptRunException $exception) {
            $this->assertSame(1, $calls);
            $this->assertSame([], $sleeps);
            $this->assertSame(ImagenProviderErrorClassifier::AUTHENTICATION_ERROR, $exception->classification());
            $this->assertFalse($exception->isRetryable());
        }
    }

    public function test_invalid_model_does_not_retry_same_model(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([
                'error' => [
                    'message' => 'models/imagen-9.0-generate-001 is not found',
                    'status' => 'NOT_FOUND',
                ],
            ], 404);
        });

        $service = $this->makeService(sleepLog: $sleeps);
        $connection = $this->fakeConnection();

        try {
            $this->invokeImagenPredict($service, $connection, 'prompt', 'imagen-9.0-generate-001');
            $this->fail('Expected PromptRunException');
        } catch (PromptRunException $exception) {
            $this->assertSame(1, $calls);
            $this->assertSame([], $sleeps);
            $this->assertSame(ImagenProviderErrorClassifier::INVALID_MODEL, $exception->classification());
            $this->assertFalse($exception->isRetryable());
        }
    }

    /**
     * @param  list<int>|null  $sleepLog
     */
    private function makeService(?array &$sleepLog = null): GeminiMediaGenerationService
    {
        $sleepLog = [];
        $sleeps = &$sleepLog;

        return new GeminiMediaGenerationService(
            Mockery::mock(PromptMediaStorageService::class),
            Mockery::mock(SeoCreateArticleSettingsService::class),
            Mockery::mock(ImageRoutingStrategy::class),
            static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
        );
    }

    private function fakeConnection(): ApiConnection
    {
        $connection = new ApiConnection;
        $connection->forceFill([
            'id' => 1,
            'provider' => 'gemini',
            'api_key' => 'test-key-not-for-log',
            'is_active' => true,
        ]);

        return $connection;
    }

    /**
     * @return array{binary: string, mime: string, usage: mixed, model_used: string}
     */
    private function invokeImagenPredict(
        GeminiMediaGenerationService $service,
        ApiConnection $connection,
        string $prompt,
        string $model,
    ): array {
        $method = new \ReflectionMethod(GeminiMediaGenerationService::class, 'requestImagenPredict');
        $method->setAccessible(true);

        /** @var array{binary: string, mime: string, usage: mixed, model_used: string} $result */
        $result = $method->invoke($service, $connection, $prompt, $model);

        return $result;
    }
}
