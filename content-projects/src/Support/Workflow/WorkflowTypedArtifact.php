<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\Workflow;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;

/**
 * Canonical identity for a successful prompt-node output consumed by domain actions.
 *
 * @phpstan-type ArtifactArray array{
 *   artifact_type: string,
 *   status: string,
 *   payload: string,
 *   project_id: int|null,
 *   project_task_id: int|null,
 *   article_id: int|null,
 *   run_id: int|null,
 *   run_item_id: int|null,
 *   attempt: int|null,
 *   workflow_node_id: string|null,
 *   producer_hook_key: string|null,
 *   workflow_graph_version: string|null,
 *   input_fingerprint: string|null,
 *   created_at: string|null,
 *   invalidated: bool
 * }
 */
final class WorkflowTypedArtifact
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_INVALIDATED = 'invalidated';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly WorkflowArtifactType $artifactType,
        public readonly string $payload,
        public readonly string $status = self::STATUS_SUCCEEDED,
        public readonly ?int $projectId = null,
        public readonly ?int $projectTaskId = null,
        public readonly ?int $articleId = null,
        public readonly ?int $runId = null,
        public readonly ?int $runItemId = null,
        public readonly ?int $attempt = null,
        public readonly ?string $workflowNodeId = null,
        public readonly ?string $producerHookKey = null,
        public readonly ?string $workflowGraphVersion = null,
        public readonly ?string $inputFingerprint = null,
        public readonly ?string $createdAt = null,
        public readonly bool $invalidated = false,
        public readonly array $extra = [],
    ) {}

    public function isReusable(): bool
    {
        return ! $this->invalidated
            && $this->status === self::STATUS_SUCCEEDED
            && trim($this->payload) !== '';
    }

    /**
     * @return ArtifactArray
     */
    public function toArray(): array
    {
        return [
            'artifact_type' => $this->artifactType->value,
            'status' => $this->status,
            'payload' => $this->payload,
            'project_id' => $this->projectId,
            'project_task_id' => $this->projectTaskId,
            'article_id' => $this->articleId,
            'run_id' => $this->runId,
            'run_item_id' => $this->runItemId,
            'attempt' => $this->attempt,
            'workflow_node_id' => $this->workflowNodeId,
            'producer_hook_key' => $this->producerHookKey,
            'workflow_graph_version' => $this->workflowGraphVersion,
            'input_fingerprint' => $this->inputFingerprint,
            'created_at' => $this->createdAt,
            'invalidated' => $this->invalidated,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $type = WorkflowArtifactType::tryFrom((string) ($data['artifact_type'] ?? ''));
        if (! $type instanceof WorkflowArtifactType) {
            return null;
        }

        $payload = trim((string) ($data['payload'] ?? ''));
        if ($payload === '') {
            return null;
        }

        return new self(
            artifactType: $type,
            payload: $payload,
            status: (string) ($data['status'] ?? self::STATUS_SUCCEEDED),
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            projectTaskId: isset($data['project_task_id']) ? (int) $data['project_task_id'] : null,
            articleId: isset($data['article_id']) ? (int) $data['article_id'] : null,
            runId: isset($data['run_id']) ? (int) $data['run_id'] : null,
            runItemId: isset($data['run_item_id']) ? (int) $data['run_item_id'] : null,
            attempt: isset($data['attempt']) ? (int) $data['attempt'] : null,
            workflowNodeId: isset($data['workflow_node_id']) ? (string) $data['workflow_node_id'] : null,
            producerHookKey: isset($data['producer_hook_key']) ? (string) $data['producer_hook_key'] : null,
            workflowGraphVersion: isset($data['workflow_graph_version']) ? (string) $data['workflow_graph_version'] : null,
            inputFingerprint: isset($data['input_fingerprint']) ? (string) $data['input_fingerprint'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            invalidated: (bool) ($data['invalidated'] ?? false),
        );
    }
}
