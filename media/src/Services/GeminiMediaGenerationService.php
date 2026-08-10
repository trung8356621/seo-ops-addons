<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Media\Support\ImagenProviderErrorClassifier;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Content\Support\TypographyComplexity;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;
use App\Models\ApiConnection;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Http;

/**
 * Sinh ảnh qua Gemini API: Imagen (:predict) hoặc Nano Banana (:generateContent).
 * Danh sách model thử đến từ ImageRoutingStrategy (entry duy nhất).
 */
final class GeminiMediaGenerationService
{
    /** Per-model HTTP budget — must stay below GenerateMediaJob queue timeout. */
    private const HTTP_TIMEOUT_SECONDS = 120;

    /** Same-model transient retries (not counting model failover). */
    private const TRANSIENT_MAX_RETRIES = 2;

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly PromptMediaStorageService $promptMediaStorage,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly ImageRoutingStrategy $imageRoutingStrategy,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
    }

    /**
     * @param  list<string>|null  $modelsOverride  Nếu có (vd. từ executionPolicy fallback) — dùng trực tiếp, không gọi lại modelsToTry
     * @return array{url: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function generateImage(
        ApiConnection $connection,
        string $prompt,
        ImageToolType $toolType = ImageToolType::Image,
        ?RenderingPreference $preference = null,
        bool $productContext = false,
        ?int $inputLength = null,
        ?TypographyComplexity $typographyComplexity = null,
        ?array $modelsOverride = null,
    ): array {
        $rendered = $this->generateImageBinary(
            connection: $connection,
            prompt: $prompt,
            toolType: $toolType,
            preference: $preference,
            productContext: $productContext,
            inputLength: $inputLength,
            typographyComplexity: $typographyComplexity,
            modelsOverride: $modelsOverride,
        );

        $imageUrl = $this->promptMediaStorage->storeBinaryMedia(
            $rendered['binary'],
            $rendered['mime'],
            'image',
            $rendered['model_used'],
        );

        RuntimeLogger::info('seo.imagen.render_succeeded', [
            'render_model' => $rendered['model_used'],
            'tool_type' => $toolType->value,
        ]);

        return [
            'url' => $imageUrl,
            'usage' => $rendered['usage'],
            'model_used' => $rendered['model_used'],
        ];
    }

    /**
     * Render ảnh → binary only (không ghi seo_media). Dùng cho typography candidate.
     *
     * @param  list<string>|null  $modelsOverride
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function generateImageBinary(
        ApiConnection $connection,
        string $prompt,
        ImageToolType $toolType = ImageToolType::Image,
        ?RenderingPreference $preference = null,
        bool $productContext = false,
        ?int $inputLength = null,
        ?TypographyComplexity $typographyComplexity = null,
        ?array $modelsOverride = null,
    ): array {
        $prompt = $this->normalizeImagePrompt(Utf8Sanitizer::string($prompt));
        $preference ??= $this->workflowSettings->getRenderingPreference();

        if ($modelsOverride !== null) {
            $models = array_values(array_unique(array_filter(array_map(
                static fn (mixed $slug): string => GoogleAiModelRegistry::normalizeSlug((string) $slug),
                $modelsOverride,
            ))));
        } elseif ($toolType->isTypography()) {
            $policy = $this->imageRoutingStrategy->executionPolicy(
                toolType: ImageToolType::ImageTypography,
                preference: $preference,
                typographyComplexity: $typographyComplexity,
                compiledPromptLength: $inputLength,
                productContext: $productContext,
                configuredPriorityList: $this->workflowSettings->getTypographyModelPriority(),
                adminEnabledUnknownSlugs: $this->workflowSettings->getAdminEnabledUnknownImageModels(),
                allowGeneralImageFallback: $this->workflowSettings->allowTypographyGeneralImageFallback(),
                generalImageFallbackPriorityList: $this->workflowSettings->getImageModelPriority(),
            );
            $models = $policy->models;
        } else {
            $models = $this->imageRoutingStrategy->modelsToTry(
                toolType: $toolType,
                preference: $preference,
                compiledPromptLength: $inputLength,
                productContext: $productContext,
                typographyComplexity: $typographyComplexity,
                configuredPriorityList: $this->workflowSettings->getImageModelPriority(),
                adminEnabledUnknownSlugs: $this->workflowSettings->getAdminEnabledUnknownImageModels(),
            );
        }

        if ($models === []) {
            throw new PromptRunException(
                $toolType->isTypography()
                    ? (
                        $this->workflowSettings->allowTypographyGeneralImageFallback()
                            ? 'Không có model image (typography hoặc general) đủ điều kiện để render.'
                            : 'Không có model typography phù hợp. Bật General Image Fallback trong AI Advanced hoặc thêm model typography_supported.'
                    )
                    : 'Không có model image đủ điều kiện để render.',
            );
        }

        $lastError = null;

        foreach ($models as $model) {
            try {
                $rendered = GoogleAiModelRegistry::isImagenModel($model)
                    ? $this->requestImagenPredict($connection, $prompt, $model)
                    : $this->requestGeminiNativeImage($connection, $prompt, $model);

                RuntimeLogger::info('seo.imagen.render_binary_succeeded', [
                    'render_model' => $rendered['model_used'] ?? $model,
                    'tool_type' => $toolType->value,
                ]);

                return $rendered;
            } catch (PromptRunException $exception) {
                $lastError = $exception;
                $this->handleRenderModelFailure($connection, $model, $exception->getMessage());
                if (! $this->shouldFailoverToNextModel($exception)) {
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                $lastError = new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
                $this->handleRenderModelFailure($connection, $model, $exception->getMessage());
                if (! $this->shouldFailoverToNextModel($lastError)) {
                    throw $lastError;
                }
            }
        }

        throw $lastError ?? new PromptRunException('Không sinh được ảnh từ Gemini API.');
    }

    private function shouldFailoverToNextModel(PromptRunException $exception): bool
    {
        $classification = $exception->classification();
        if ($classification === ImagenProviderErrorClassifier::AUTHENTICATION_ERROR
            || $classification === ImagenProviderErrorClassifier::INVALID_REQUEST
            || $classification === ImagenProviderErrorClassifier::CONFIGURATION_ERROR
        ) {
            return false;
        }

        return $this->isRetryable($exception->getMessage()) || $exception->isRetryable();
    }

    private function handleRenderModelFailure(ApiConnection $connection, string $model, string $message): void
    {
        RuntimeLogger::warning('seo.imagen.render_model_failed', [
            'render_model' => $model,
            'error' => ImagenProviderErrorClassifier::redactSecrets(mb_substr($message, 0, 2000)),
        ]);

        if (! GeminiModelVersionPolicy::isProviderUnavailableError($message)) {
            return;
        }

        $record = SeoAiModel::query()
            ->where('api_connection_id', (int) $connection->id)
            ->where('raw_model_name', $model)
            ->first();

        if (! $record instanceof SeoAiModel) {
            return;
        }

        $capabilities = is_array($record->capabilities) ? $record->capabilities : [];
        $record->update([
            'capabilities' => GeminiModelVersionPolicy::markCapabilitiesUnavailable($capabilities, $message),
            'last_error' => mb_substr(ImagenProviderErrorClassifier::redactSecrets($message), 0, 2000),
        ]);
    }

    /**
     * Imagen 4 — POST Generative Language API .../models/{model}:predict
     * Public model ID is sent as-is. Internal Vertex IDs (e.g. vertex-imagen-jpe-v1-8)
     * appear only inside Google error payloads — never mapped in our code.
     *
     * Auth: AI Studio API key query param (not Vertex service-account / project/location).
     *
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    private function requestImagenPredict(ApiConnection $connection, string $prompt, string $model): array
    {
        $endpointCategory = 'generativelanguage_v1beta_predict';
        $urlPath = sprintf('/v1beta/models/%s:predict', $model);
        $url = 'https://generativelanguage.googleapis.com'.$urlPath;
        $payload = [
            'instances' => [
                ['prompt' => $prompt],
            ],
            'parameters' => [
                'sampleCount' => 1,
                'aspectRatio' => $this->resolveImagenAspectRatio($prompt),
            ],
        ];

        $attempt = 0;
        $lastException = null;

        while ($attempt <= self::TRANSIENT_MAX_RETRIES) {
            $attempt++;

            try {
                return $this->executeImagenPredictAttempt(
                    connection: $connection,
                    url: $url,
                    urlPath: $urlPath,
                    endpointCategory: $endpointCategory,
                    model: $model,
                    payload: $payload,
                    attempt: $attempt,
                );
            } catch (PromptRunException $exception) {
                $lastException = $exception;
                $canRetry = $exception->isRetryable() && $attempt <= self::TRANSIENT_MAX_RETRIES;
                if (! $canRetry) {
                    throw $exception;
                }

                $backoffMs = (int) (500 * (2 ** ($attempt - 1)));
                RuntimeLogger::warning('seo.imagen.transient_retry', [
                    'requested_model' => $model,
                    'attempt' => $attempt,
                    'next_backoff_ms' => $backoffMs,
                    'classification' => $exception->classification(),
                ]);
                ($this->sleeper)($backoffMs);
            }
        }

        throw $lastException ?? new PromptRunException('Imagen API lỗi ('.$model.').');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    private function executeImagenPredictAttempt(
        ApiConnection $connection,
        string $url,
        string $urlPath,
        string $endpointCategory,
        string $model,
        array $payload,
        int $attempt,
    ): array {
        $response = $this->geminiHttpClient($connection)->post($url, $payload);
        $status = $response->status();
        $providerRequestId = $response->header('x-request-id')
            ?: $response->header('x-goog-request-id')
            ?: null;

        if (! $response->successful()) {
            $rawBody = ImagenProviderErrorClassifier::redactSecrets((string) $response->body());
            $providerMessage = (string) ($response->json('error.message') ?? $rawBody);
            $providerMessage = ImagenProviderErrorClassifier::redactSecrets($providerMessage);
            $presented = ImagenProviderErrorClassifier::present($providerMessage, $status);

            $audit = [
                'requested_model' => $model,
                'resolved_model' => $model,
                'provider' => 'gemini_generativelanguage',
                'endpoint_category' => $endpointCategory,
                'endpoint_path' => $urlPath,
                'region' => null,
                'project_id' => null,
                'auth_mode' => 'api_key_query',
                'http_status' => $status,
                'provider_error_code' => $response->json('error.status') ?? $response->json('error.code'),
                'provider_request_id' => $providerRequestId,
                'retry_count' => max(0, $attempt - 1),
                'final_classification' => $presented['classification'],
                'internal_endpoint_hint' => self::extractInternalEndpointHint($providerMessage),
            ];

            RuntimeLogger::warning('seo.imagen.predict_failed', $audit);

            throw new PromptRunException(
                'Imagen API lỗi ('.$model.'): '.$this->truncate($presented['technical_details']),
                $status,
                null,
                [
                    'classification' => $presented['classification'],
                    'retryable' => $presented['retryable'],
                    'user_message' => $presented['user_message'],
                    'technical_details' => $presented['technical_details'],
                    'audit' => $audit,
                ],
            );
        }

        $predictions = $response->json('predictions', []);
        if (! is_array($predictions)) {
            $predictions = [];
        }

        foreach ($predictions as $prediction) {
            if (! is_array($prediction)) {
                continue;
            }

            $b64 = (string) ($prediction['bytesBase64Encoded'] ?? $prediction['bytes_base64_encoded'] ?? '');
            if ($b64 === '') {
                continue;
            }

            $binary = base64_decode($b64, true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = (string) ($prediction['mimeType'] ?? 'image/png');

            RuntimeLogger::info('seo.imagen.predict_ok', [
                'requested_model' => $model,
                'resolved_model' => $model,
                'provider' => 'gemini_generativelanguage',
                'endpoint_category' => $endpointCategory,
                'auth_mode' => 'api_key_query',
                'http_status' => $status,
                'provider_request_id' => $providerRequestId,
                'retry_count' => max(0, $attempt - 1),
            ]);

            return [
                'binary' => $binary,
                'mime' => $mime !== '' ? $mime : 'image/png',
                'usage' => null,
                'model_used' => $model,
            ];
        }

        $presented = ImagenProviderErrorClassifier::present(
            'Imagen không trả về ảnh ('.$model.').',
            $status,
        );

        throw new PromptRunException(
            $presented['technical_details'],
            $status,
            null,
            [
                'classification' => ImagenProviderErrorClassifier::UNKNOWN_PROVIDER_ERROR,
                'retryable' => true,
                'user_message' => $presented['user_message'],
                'technical_details' => $presented['technical_details'],
                'audit' => [
                    'requested_model' => $model,
                    'resolved_model' => $model,
                    'provider' => 'gemini_generativelanguage',
                    'endpoint_category' => $endpointCategory,
                    'endpoint_path' => $urlPath,
                    'region' => null,
                    'project_id' => null,
                    'auth_mode' => 'api_key_query',
                    'http_status' => $status,
                    'provider_request_id' => $providerRequestId,
                    'retry_count' => max(0, $attempt - 1),
                    'final_classification' => ImagenProviderErrorClassifier::UNKNOWN_PROVIDER_ERROR,
                ],
            ],
        );
    }

    private static function extractInternalEndpointHint(string $message): ?string
    {
        if (preg_match('#/vertex/[a-z0-9\-]+#i', $message, $match) === 1) {
            return $match[0];
        }

        return null;
    }

    /**
     * Nano Banana with optional reference image parts (inline_data).
     * Imagen path does not support references — callers must pass Gemini native model.
     *
     * @param  list<array{mime_type: string, base64: string}>  $referenceImages
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function generateNativeImageWithReferences(
        ApiConnection $connection,
        string $prompt,
        string $model,
        array $referenceImages = [],
    ): array {
        $model = GoogleAiModelRegistry::normalizeSlug($model);
        if ($model === '' || GoogleAiModelRegistry::isImagenModel($model)) {
            throw new PromptRunException(
                'Reference image requires a Gemini native image model (not Imagen).',
                0,
                null,
                [
                    'classification' => 'reference_transport_unsupported',
                    'retryable' => false,
                    'error_code' => 'reference_transport_unsupported',
                ],
            );
        }

        return $this->requestGeminiNativeImage($connection, $prompt, $model, $referenceImages);
    }

    /**
     * Nano Banana — POST .../models/{model}:generateContent (v1beta).
     * Bắt buộc yêu cầu IMAGE modality, nếu không model có thể trả text-only rồi stop.
     *
     * @param  list<array{mime_type: string, base64: string}>  $referenceImages
     * @return array{binary: string, mime: string, usage: array<string, mixed>|null, model_used: string}
     */
    private function requestGeminiNativeImage(
        ApiConnection $connection,
        string $prompt,
        string $model,
        array $referenceImages = [],
    ): array {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model),
        );

        $parts = [];
        foreach ($referenceImages as $ref) {
            $mime = trim((string) ($ref['mime_type'] ?? ''));
            $b64 = trim((string) ($ref['base64'] ?? ''));
            if ($mime === '' || $b64 === '') {
                continue;
            }
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $mime,
                    'data' => $b64,
                ],
            ];
        }
        $parts[] = ['text' => $prompt];

        $response = $this->geminiHttpClient($connection)->post($url, [
            'generationConfig' => [
                'responseModalities' => ['IMAGE', 'TEXT'],
            ],
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
        ]);

        if (! $response->successful()) {
            $message = ImagenProviderErrorClassifier::redactSecrets(
                (string) ($response->json('error.message') ?? $response->body()),
            );
            $presented = ImagenProviderErrorClassifier::present($message, $response->status());

            throw new PromptRunException(
                'Gemini Image API lỗi ('.$model.'): '.$this->truncate($presented['technical_details']),
                $response->status(),
                null,
                [
                    'classification' => $presented['classification'],
                    'retryable' => $presented['retryable'],
                    'user_message' => $presented['user_message'],
                    'technical_details' => $presented['technical_details'],
                ],
            );
        }

        $parts = $response->json('candidates.0.content.parts', []);
        if (! is_array($parts)) {
            $parts = [];
        }

        $textLines = [];
        $binaryOut = null;
        $mimeOut = 'image/png';

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (filled($part['text'] ?? null)) {
                $textLines[] = trim((string) $part['text']);
            }

            $inline = is_array($part['inlineData'] ?? null) ? $part['inlineData'] : null;
            if ($inline === null || blank($inline['data'] ?? null)) {
                continue;
            }

            $mime = (string) ($inline['mimeType'] ?? 'image/png');
            $binary = base64_decode((string) $inline['data'], true);
            if ($binary === false || $binary === '') {
                continue;
            }

            $binaryOut = $binary;
            $mimeOut = $mime !== '' ? $mime : 'image/png';
        }

        if ($binaryOut === null) {
            $blockReason = $response->json('candidates.0.finishReason')
                ?? $response->json('promptFeedback.blockReason');
            $hint = $textLines !== []
                ? ' | text='.mb_substr(implode(' ', $textLines), 0, 180)
                : '';

            throw new PromptRunException(
                'Gemini Image không trả ảnh ('.$model.')'
                .($blockReason ? ' — '.$blockReason : '')
                .$hint
                .'. Thử Imagen 4 hoặc rút gọn prompt (≤480 token cho Imagen).',
            );
        }

        $usage = $response->json('usageMetadata');

        return [
            'binary' => $binaryOut,
            'mime' => $mimeOut,
            'usage' => is_array($usage) ? $usage : null,
            'model_used' => $model,
        ];
    }

    private function normalizeImagePrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new PromptRunException('Prompt sinh ảnh trống.');
        }

        // Imagen giới hạn ~480 token — ưu tiên đoạn tiếng Anh / prompt thuần nếu quá dài.
        if (mb_strlen($prompt) > 3500) {
            if (preg_match('/```[\s\S]*?```/m', $prompt, $code) === 1) {
                $prompt = trim($code[0], "` \n");
            } elseif (preg_match('/(?:Main Prompt|PROMPT|Prompt)[^\n]*\n+([\s\S]{200,2500})/i', $prompt, $match) === 1) {
                $prompt = trim($match[1]);
            } else {
                $prompt = mb_substr($prompt, 0, 3500);
            }
        }

        return $prompt;
    }

    private function resolveImagenAspectRatio(string $prompt): string
    {
        $normalized = mb_strtolower($prompt);

        if (
            str_contains($normalized, '2x3')
            || str_contains($normalized, '2 x 3')
            || str_contains($normalized, '2 dòng 3 cột')
            || str_contains($normalized, '2 hàng, 3 cột')
            || str_contains($normalized, '2 rows')
        ) {
            return '4:3';
        }

        if (
            str_contains($normalized, 'landscape')
            || str_contains($normalized, 'horizontal')
            || str_contains($normalized, '16:9')
        ) {
            return '16:9';
        }

        if (
            str_contains($normalized, 'portrait')
            || str_contains($normalized, 'vertical')
            || str_contains($normalized, '9:16')
        ) {
            return '3:4';
        }

        return '3:4';
    }

    private function geminiHttpClient(ApiConnection $connection): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(self::HTTP_TIMEOUT_SECONDS)
            ->connectTimeout(30)
            ->acceptJson()
            ->withQueryParameters(['key' => $connection->api_key]);
    }

    private function isRetryable(string $message): bool
    {
        $classification = ImagenProviderErrorClassifier::classify($message);

        if (ImagenProviderErrorClassifier::isRetryableClassification($classification)) {
            return true;
        }

        $lower = strtolower($message);

        return str_contains($lower, 'not found')
            || str_contains($lower, '404')
            || str_contains($lower, 'not supported')
            || str_contains($lower, 'connection')
            || str_contains($lower, 'could not resolve');
    }

    private function truncate(string $message): string
    {
        return mb_strlen($message) > 500 ? mb_substr($message, 0, 500).'…' : $message;
    }
}
