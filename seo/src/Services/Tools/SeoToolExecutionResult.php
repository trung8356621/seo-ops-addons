<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

final class SeoToolExecutionResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly bool $success,
        private readonly array $data = [],
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null,
        private readonly array $meta = [],
        private readonly int $httpStatus = 200
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function success(array $data, array $meta = [], int $httpStatus = 200): self
    {
        return new self(
            success: true,
            data: $data,
            meta: $meta,
            httpStatus: $httpStatus
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function failure(
        string $errorCode,
        string $errorMessage,
        array $meta = [],
        int $httpStatus = 400
    ): self {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            meta: $meta,
            httpStatus: $httpStatus
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->success) {
            $payload = [
                'success' => true,
                'data' => $this->data,
            ];
            if (!empty($this->meta)) {
                $payload['meta'] = $this->meta;
            }
            return $payload;
        }

        $payload = [
            'success' => false,
            'error' => [
                'code' => $this->errorCode ?? 'tool_execution_failed',
                'message' => $this->errorMessage ?? 'Tool execution failed.',
            ],
        ];
        if (!empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }
        return $payload;
    }
}

