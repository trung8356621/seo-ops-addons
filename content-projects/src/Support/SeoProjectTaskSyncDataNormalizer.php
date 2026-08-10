<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
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
        $siteDefault = $defaultSiteId ?? ($project->site_id !== null ? (int) $project->site_id : null);
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

            // Legacy rows: single source_content before keyword/title split.
            if ($keyword === '' && $title === '' && SeoProjectTask::isNewArticleType($type) && $existingArticleTitle !== '') {
                $keyword = $existingArticleTitle;
            }

            if (in_array($type, [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE], true)
                && ! ContentProjectItemIdentity::isValid($keyword, $title)
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

            $siteId = (int) ($row['site_id'] ?? 0);
            if ($siteId <= 0) {
                $siteId = (int) ($siteDefault ?? 0);
            }

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
            );
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    protected function allowedSiteIds(): array
    {
        return SeoAccessControl::accessibleSiteIds();
    }
}
