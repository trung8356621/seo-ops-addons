<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\DataTransfer;

use Omnichannel\Addons\AiPrompt\Support\AiErrorPresentationCategory;
use Omnichannel\Addons\AiPrompt\Support\AiErrorPresentationSource;

/**
 * Structured AI error for API / toast / History (no secrets).
 *
 * @phpstan-type PresentationArray array{
 *   category: string,
 *   category_label: string,
 *   error_code: string|null,
 *   message: string,
 *   source: string,
 *   source_label: string,
 *   provider_name: string|null,
 *   model_name: string|null,
 *   upstream_http_status: int|null,
 *   upstream_error_code: string|null,
 *   upstream_message: string|null,
 *   attempt_count: int|null,
 *   fallback_exhausted: bool|null,
 *   retryable: bool|null,
 *   retry_after: int|null,
 *   request_id: string|null,
 *   correlation_id: string|null,
 *   step: string|null,
 *   hook_key: string|null,
 *   detail_lines: list<string>
 * }
 */
final readonly class AiErrorPresentation
{
    /**
     * @param  list<string>  $detailLines
     */
    public function __construct(
        public AiErrorPresentationCategory $category,
        public AiErrorPresentationSource $source,
        public string $message,
        public ?string $errorCode = null,
        public ?string $providerName = null,
        public ?string $modelName = null,
        public ?int $upstreamHttpStatus = null,
        public ?string $upstreamErrorCode = null,
        public ?string $upstreamMessage = null,
        public ?int $attemptCount = null,
        public ?bool $fallbackExhausted = null,
        public ?bool $retryable = null,
        public ?int $retryAfter = null,
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?string $step = null,
        public ?string $hookKey = null,
        public array $detailLines = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'category_label' => $this->category->labelVi(),
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'source' => $this->source->value,
            'source_label' => $this->source->labelVi(),
            'provider_name' => $this->providerName,
            'model_name' => $this->modelName,
            'upstream_http_status' => $this->upstreamHttpStatus,
            'upstream_error_code' => $this->upstreamErrorCode,
            'upstream_message' => $this->upstreamMessage,
            'attempt_count' => $this->attemptCount,
            'fallback_exhausted' => $this->fallbackExhausted,
            'retryable' => $this->retryable,
            'retry_after' => $this->retryAfter,
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'step' => $this->step,
            'hook_key' => $this->hookKey,
            'detail_lines' => $this->detailLines,
        ];
    }
}
