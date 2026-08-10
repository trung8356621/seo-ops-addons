<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\Workflow;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Support\Workflow\WorkflowTypedArtifact;

/**
 * Decides whether an upstream typed artifact may be reused on step resume / content rewrite.
 * No UI/job heuristics — call this from domain services only.
 */
final class ArtifactReusePolicy
{
    /**
     * Publishing schedule alone must never invalidate outline/content.
     *
     * @var list<string>
     */
    public const NON_INVALIDATING_INPUT_KEYS = [
        'scheduled_publish_at',
        'publish_queue_status',
        'publish_published_at',
        'publish_now',
        'auto_schedule',
    ];

    /**
     * Identity/content keys that invalidate outline (and therefore content).
     *
     * @var list<string>
     */
    public const OUTLINE_INVALIDATING_INPUT_KEYS = [
        'keyword',
        'focus_keyword',
        'post_title',
        'title',
        'description',
        'site_short_description',
        'post_type',
        'tone',
        'language',
        'search_intent',
    ];

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $current
     */
    public function canReuse(
        WorkflowTypedArtifact $artifact,
        WorkflowArtifactType $requiredType,
        int $projectTaskId,
        ?int $runItemId = null,
        ?int $attempt = null,
        ?string $graphVersion = null,
        ?string $inputFingerprint = null,
        bool $allowCrossAttemptReuse = true,
    ): bool {
        if ($artifact->artifactType !== $requiredType) {
            return false;
        }

        if (! $artifact->isReusable()) {
            return false;
        }

        if ($artifact->projectTaskId !== null && (int) $artifact->projectTaskId !== $projectTaskId) {
            return false;
        }

        if ($runItemId !== null && $artifact->runItemId !== null && (int) $artifact->runItemId !== $runItemId) {
            if (! $allowCrossAttemptReuse) {
                return false;
            }
        }

        if ($attempt !== null && $artifact->attempt !== null && (int) $artifact->attempt !== $attempt) {
            if (! $allowCrossAttemptReuse) {
                return false;
            }
        }

        if ($graphVersion !== null && $graphVersion !== ''
            && $artifact->workflowGraphVersion !== null && $artifact->workflowGraphVersion !== ''
            && $artifact->workflowGraphVersion !== $graphVersion
        ) {
            return false;
        }

        if ($inputFingerprint !== null && $inputFingerprint !== ''
            && $artifact->inputFingerprint !== null && $artifact->inputFingerprint !== ''
            && $artifact->inputFingerprint !== $inputFingerprint
        ) {
            return false;
        }

        return true;
    }

    /**
     * Fingerprint of generation-relevant inputs (excludes schedule/publish keys).
     *
     * @param  array<string, mixed>  $inputs
     */
    public function inputFingerprint(array $inputs): string
    {
        $normalized = [];
        foreach (self::OUTLINE_INVALIDATING_INPUT_KEYS as $key) {
            if (! array_key_exists($key, $inputs)) {
                continue;
            }
            $value = $inputs[$key];
            if (is_scalar($value) || $value === null) {
                $normalized[$key] = trim((string) ($value ?? ''));
            }
        }
        ksort($normalized);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function outlineInvalidatedByInputChange(array $before, array $after): bool
    {
        return $this->inputFingerprint($before) !== $this->inputFingerprint($after);
    }

    /**
     * Reject outline / non-content payloads as article body sources.
     */
    public function isValidArticleContentPayload(string $payload): bool
    {
        $payload = trim($payload);
        if ($payload === '') {
            return false;
        }

        if ($this->looksLikeOutlineMarkerPayload($payload)) {
            return false;
        }

        return true;
    }

    public function looksLikeOutlineMarkerPayload(string $payload): bool
    {
        $payload = trim($payload);
        if ($payload === '') {
            return false;
        }

        return (bool) preg_match('/\[START_TASK_\d+_OUTLINE\]/i', $payload)
            || (bool) preg_match('/\[END_TASK_\d+_OUTLINE\]/i', $payload);
    }
}
