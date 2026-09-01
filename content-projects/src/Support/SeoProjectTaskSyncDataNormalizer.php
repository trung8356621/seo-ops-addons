<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemContentLengthDefaults;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemContentLengthMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemModelOverrideMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemTitleProtection;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Validation\ValidationException;

class SeoProjectTaskSyncDataNormalizer
{
    public function __construct(
        private readonly ProjectTaskSourceKeyGenerator $sourceKeys,
    ) {}

    /**
     * @param  list<mixed>  $tasksData
     * @return list<SeoProjectTaskSyncData>
     */
    public function normalize(SeoProject $project, array $tasksData, ?int $defaultSiteId = null): array
    {
        $projectId = (int) $project->id;
        $canonicalSiteId = $this->canonicalSiteId($project, $defaultSiteId);
        $allowedSiteIds = $this->allowedSiteIds();
        $out = [];

        foreach ($tasksData as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = SeoProjectTask::normalizeType($row['type'] ?? SeoProjectTask::TYPE_CREATE);
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $secondaryDescription = trim((string) ($row['secondary_description'] ?? ''));
            $existingArticleTitle = trim((string) ($row['source_content'] ?? ''));

            // Rewrite: source_content is the original title; do not persist a second editable title
            // that could override AI-proposed titles downstream.
            if ($type === SeoProjectTask::TYPE_REWRITE) {
                $title = '';
            }

            // Legacy rows: single source_content before keyword/title split.
            if ($keyword === '' && $title === '' && SeoProjectTask::isNewArticleType($type) && $existingArticleTitle !== '') {
                $keyword = $existingArticleTitle;
            }

            $identityTitle = $type === SeoProjectTask::TYPE_REWRITE ? $existingArticleTitle : $title;
            if (in_array($type, [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE], true)
                && ! ContentProjectItemIdentity::isValid($keyword, $identityTitle)
            ) {
                throw ValidationException::withMessages([
                    'tasks_data.'.$index.'.keyword' => ContentProjectItemIdentity::failureMessage(),
                    'tasks_data.'.$index.'.title' => ContentProjectItemIdentity::failureMessage(),
                    'tasks_data' => ContentProjectItemIdentity::failureMessage(),
                ]);
            }

            $content = SeoProjectTask::deriveSourceContent(
                $type,
                $keyword,
                $title,
                $existingArticleTitle,
            );
            if ($content === '') {
                continue;
            }

            // Project.site_id is the ownership source of truth. Ignore stale
            // tasks_data[*].site_id — the edit repeater no longer exposes a
            // per-item domain selector, so leftover hidden values must not win.
            $siteId = $canonicalSiteId;

            if ($siteId <= 0 || ! in_array($siteId, $allowedSiteIds, true)) {
                throw ValidationException::withMessages([
                    'site_id' => __('seo-content-ai::filament.projects.domain_required'),
                    'tasks_data' => __('seo-content-ai::filament.projects.domain_required'),
                ]);
            }

            $postType = null;
            $loaiSanPham = null;
            $description = null;
            $rewriteMode = null;
            $rewriteNotes = null;

            if (SeoProjectTask::isNewArticleType($type)) {
                $postType = SeoProjectTask::normalizePostType($row['post_type'] ?? null);
                if ($postType === SeoProjectTask::POST_TYPE_PRODUCT) {
                    $loai = trim((string) ($row['loai_san_pham'] ?? ''));
                    $loaiSanPham = $loai !== '' ? $loai : null;
                    $gallery = trim((string) ($row['gallery_description'] ?? $row['description'] ?? ''));
                    $description = $gallery !== '' ? $gallery : null;
                }
            }

            if ($type === SeoProjectTask::TYPE_REWRITE) {
                // Rewrite luôn đọc bài gốc; keyword/title/description chỉ định hướng thêm.
                $rewriteMode = SeoProjectTask::REWRITE_MODE_CONTENT;
                $notes = trim((string) ($row['rewrite_notes'] ?? ''));
                $rewriteNotes = $notes !== '' ? $notes : null;
            }

            if ($type === SeoProjectTask::TYPE_IMPROVE) {
                $notes = trim((string) ($row['rewrite_notes'] ?? $row['improve_instruction'] ?? ''));
                $rewriteNotes = $notes !== '' ? $notes : null;
            }

            $taskIdRaw = (int) ($row['id'] ?? $row['task_id'] ?? 0);
            $taskId = $taskIdRaw > 0 ? $taskIdRaw : null;

            $sourceKey = $this->sourceKeys->generate($projectId, $type, $postType, $content);

            $targetDate = isset($row['target_date']) && trim((string) $row['target_date']) !== ''
                ? trim((string) $row['target_date'])
                : null;

            $requestedStatus = isset($row['status']) && trim((string) $row['status']) !== ''
                ? trim((string) $row['status'])
                : null;

            $toneOverride = trim((string) ($row['tone_override'] ?? ''));

            $targetWords = (int) ($row['content_length_target_words'] ?? 0);
            $legacyMode = ItemContentLengthMode::tryFromMixed($row['content_length_override'] ?? null);
            // Direct numeric length is canonical. Preset modes remain readable for legacy rows.
            $contentLengthOverride = null;
            $contentLengthTargetWords = null;
            if ($targetWords > 0) {
                $contentLengthOverride = ItemContentLengthMode::Custom;
                $contentLengthTargetWords = ContentProjectItemContentLengthDefaults::clamp($targetWords);
            } elseif ($legacyMode !== null) {
                $contentLengthOverride = $legacyMode;
                if ($legacyMode === ItemContentLengthMode::Custom) {
                    $contentLengthTargetWords = null;
                }
            } elseif (SeoProjectTask::isNewArticleType($type)) {
                $contentLengthOverride = ItemContentLengthMode::Custom;
                $contentLengthTargetWords = ContentProjectItemContentLengthDefaults::forPostType(
                    $row['post_type'] ?? null,
                );
            }

            $modelOverrideIdRaw = (int) ($row['model_override_id'] ?? 0);
            $modelOverrideId = $modelOverrideIdRaw > 0 ? $modelOverrideIdRaw : null;

            $out[] = new SeoProjectTaskSyncData(
                taskId: $taskId,
                projectId: $projectId,
                siteId: $siteId,
                type: $type,
                postType: $postType,
                sourceContent: $content,
                sourceKey: $sourceKey,
                keyword: $keyword !== '' ? $keyword : null,
                title: $title !== '' ? $title : null,
                secondaryDescription: $secondaryDescription !== '' ? $secondaryDescription : null,
                rewriteMode: $rewriteMode,
                rewriteNotes: $rewriteNotes,
                description: $description,
                loaiSanPham: $loaiSanPham,
                targetDate: $targetDate,
                requestedStatus: $requestedStatus,
                inputIndex: (int) $index,
                toneOverride: $toneOverride !== '' ? $toneOverride : null,
                contentLengthOverride: $contentLengthOverride,
                contentLengthTargetWords: $contentLengthTargetWords,
                generationModeOverride: ItemGenerationMode::tryFromMixed($row['generation_mode_override'] ?? null),
                modelOverrideId: $modelOverrideId,
                modelOverrideMode: self::modelOverrideMode($row, $modelOverrideId),
                titleProtection: ItemTitleProtection::tryFromMixed($row['title_protection'] ?? null),
            );
        }

        return $out;
    }

    /**
     * The repeater exposes a "fallback if unavailable" toggle; the column stores the
     * binding strength. A mode without a model id is dropped — it would never apply.
     *
     * @param  array<mixed>  $row
     */
    private static function modelOverrideMode(array $row, ?int $modelOverrideId): ?ItemModelOverrideMode
    {
        if ($modelOverrideId === null) {
            return null;
        }

        if (array_key_exists('model_fallback_enabled', $row)) {
            return filter_var($row['model_fallback_enabled'], FILTER_VALIDATE_BOOLEAN)
                ? ItemModelOverrideMode::Preferred
                : ItemModelOverrideMode::Required;
        }

        return ItemModelOverrideMode::tryFromMixed($row['model_override_mode'] ?? null)
            ?? ItemModelOverrideMode::Preferred;
    }

    /**
     * Canonical site for project-item sync.
     *
     * Persisted project.site_id always wins. Compatibility helpers that run
     * without a persisted project may pass defaultSiteId (create-form site).
     * Row-level site_id is never authoritative.
     */
    public function canonicalSiteId(SeoProject $project, ?int $defaultSiteId = null): int
    {
        $fromProject = $project->site_id !== null ? (int) $project->site_id : 0;
        if ($fromProject > 0) {
            return $fromProject;
        }

        return (int) ($defaultSiteId ?? 0);
    }

    /**
     * @return list<int>
     */
    protected function allowedSiteIds(): array
    {
        return SeoAccessControl::accessibleSiteIds();
    }
}
