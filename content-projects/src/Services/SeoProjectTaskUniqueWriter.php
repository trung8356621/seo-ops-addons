<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Race-safe task create under UNIQUE(project_id, source_key).
 */
final class SeoProjectTaskUniqueWriter
{
    public function __construct(
        private readonly ProjectTaskSourceKeyGenerator $sourceKeys,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOrReturnExisting(array $attributes): SeoProjectTask
    {
        return $this->write($attributes, onConflict: 'return');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createStrict(array $attributes): SeoProjectTask
    {
        return $this->write($attributes, onConflict: 'fail');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  'return'|'fail'  $onConflict
     */
    private function write(array $attributes, string $onConflict): SeoProjectTask
    {
        $projectId = (int) ($attributes['project_id'] ?? 0);
        $type = (string) ($attributes['type'] ?? '');
        $sourceContent = trim((string) ($attributes['source_content'] ?? ''));
        $postType = isset($attributes['post_type']) ? (string) $attributes['post_type'] : null;

        if ($projectId <= 0 || $type === '' || $sourceContent === '') {
            throw ValidationException::withMessages([
                'source_content' => ContentProjectErrorCode::SyncDuplicateInput->value,
            ]);
        }

        if (! isset($attributes['source_key']) || trim((string) $attributes['source_key']) === '') {
            $attributes['source_key'] = $this->sourceKeys->generate(
                $projectId,
                $type,
                $postType,
                $sourceContent,
            );
        }

        $sourceKey = (string) $attributes['source_key'];

        // DB column rewrite_mode NOT NULL — never insert null (create/improve/…).
        $typeNormalized = SeoProjectTask::normalizeType($attributes['type'] ?? $type);
        if ($typeNormalized === SeoProjectTask::TYPE_REWRITE) {
            $attributes['rewrite_mode'] = SeoProjectTask::REWRITE_MODE_CONTENT;
        } else {
            $attributes['rewrite_mode'] = SeoProjectTask::REWRITE_MODE_KEYWORD;
        }
        $attributes['type'] = $typeNormalized;

        $existing = SeoProjectTask::withTrashed()
            ->where('project_id', $projectId)
            ->where('source_key', $sourceKey)
            ->first();

        if ($existing instanceof SeoProjectTask) {
            if ($existing->trashed() || $onConflict === 'fail') {
                throw ValidationException::withMessages([
                    'source_key' => ContentProjectErrorCode::TaskSourceKeyConflict->value,
                ]);
            }

            return $existing;
        }

        try {
            return SeoProjectTask::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isSourceKeyUniqueViolation($exception)) {
                throw $exception;
            }

            $raced = SeoProjectTask::withTrashed()
                ->where('project_id', $projectId)
                ->where('source_key', $sourceKey)
                ->first();

            if ($onConflict === 'return' && $raced instanceof SeoProjectTask && ! $raced->trashed()) {
                return $raced;
            }

            throw ValidationException::withMessages([
                'source_key' => ContentProjectErrorCode::TaskSourceKeyConflict->value,
            ]);
        }
    }

    private function isSourceKeyUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return (string) $exception->getCode() === '23000'
            && (
                str_contains($message, 'seo_project_tasks_project_id_source_key_unique')
                || (str_contains($message, 'source_key') && (
                    str_contains($message, 'Duplicate')
                    || str_contains($message, 'UNIQUE')
                ))
            );
    }
}
