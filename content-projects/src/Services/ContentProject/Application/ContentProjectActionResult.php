<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application;

/**
 * Kết quả chuẩn cho mọi Application Command.
 *
 * @phpstan-type Warning list<string>
 * @phpstan-type ErrorMap array<string, list<string>>
 * @phpstan-type Meta array<string, mixed>
 */
final class ContentProjectActionResult
{
    /**
     * @param  list<int>  $affectedItemIds
     * @param  list<string>  $warnings
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $projectId = null,
        public readonly array $affectedItemIds = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  list<int>  $affectedItemIds
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadata
     */
    public static function ok(
        string $code,
        string $message,
        ?int $projectId = null,
        array $affectedItemIds = [],
        array $warnings = [],
        array $metadata = [],
    ): self {
        return new self(
            success: true,
            code: $code,
            message: $message,
            projectId: $projectId,
            affectedItemIds: $affectedItemIds,
            warnings: $warnings,
            errors: [],
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadata
     */
    public static function fail(
        string $code,
        string $message,
        ?int $projectId = null,
        array $errors = [],
        array $warnings = [],
        array $metadata = [],
        array $affectedItemIds = [],
    ): self {
        return new self(
            success: false,
            code: $code,
            message: $message,
            projectId: $projectId,
            affectedItemIds: $affectedItemIds,
            warnings: $warnings,
            errors: $errors,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message,
            'project_id' => $this->projectId,
            'project_ref' => $this->projectId !== null
                ? ContentProjectPublicRef::project($this->projectId)
                : null,
            'affected_item_ids' => $this->affectedItemIds,
            'affected_item_refs' => array_map(
                static fn (int $id): string => ContentProjectPublicRef::item($id),
                $this->affectedItemIds,
            ),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * API contract — refs only, không leak numeric IDs.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(?string $requestId = null, bool $idempotentReplay = false): array
    {
        $metadata = $this->metadata;
        unset($metadata['affected_item_ids'], $metadata['project_id']);

        $data = array_merge($metadata, [
            'project_ref' => $this->projectId !== null
                ? ContentProjectPublicRef::project($this->projectId)
                : null,
            'affected_item_refs' => array_map(
                static fn (int $id): string => ContentProjectPublicRef::item($id),
                $this->affectedItemIds,
            ),
        ]);

        return [
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message,
            'data' => $data,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'meta' => [
                'request_id' => $requestId,
                'idempotent_replay' => $idempotentReplay,
            ],
        ];
    }
}
