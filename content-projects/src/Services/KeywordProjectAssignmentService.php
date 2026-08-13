<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Domain assignment: Keyword → Content Project task (TYPE_NEW_KEYWORD).
 * Không Filament notification; không WordPress outbound.
 */
final class KeywordProjectAssignmentService
{
    public function __construct(
        private readonly SeoProjectTaskUniqueWriter $uniqueWriter,
    ) {}

    /**
     * @param  Collection<int, mixed>  $records
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int}
     */
    public function assignKeywords(Collection $records, int $projectId, int $targetSiteId, bool $dryRun = false): array
    {
        $empty = static fn (int $overflow): array => [
            'added' => 0,
            'duplicate' => 0,
            'overflow' => $overflow,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
        ];

        if ($targetSiteId <= 0) {
            return $empty($records->count());
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return $empty($records->count());
        }

        $records = $records
            ->filter(fn (mixed $record): bool => $record instanceof Keyword && $this->canAssignKeyword($record))
            ->values();

        $added = 0;
        $duplicate = 0;
        $overflow = 0;
        $domainMismatch = 0;
        $alreadyInProject = 0;
        $projectSiteId = (int) ($project->site_id ?? 0);
        $targetProjectId = (int) $project->id;

        DB::connection($project->getConnectionName())->transaction(function () use (
            $project,
            $records,
            $projectSiteId,
            $targetProjectId,
            $targetSiteId,
            $dryRun,
            &$added,
            &$duplicate,
            &$overflow,
            &$domainMismatch,
            &$alreadyInProject,
        ): void {
            $project->refresh();
            $max = $project->maxTasksAllowed();
            $currentTotal = $project->registeredTaskCount();

            $existingKeys = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->get(['site_id', 'type', 'source_content'])
                ->map(static fn (SeoProjectTask $task): string => (int) $task->site_id.'|'.SeoProjectTask::TYPE_NEW_KEYWORD.'|'.mb_strtolower(trim((string) $task->source_content)))
                ->all();
            $existingMap = array_fill_keys($existingKeys, true);

            foreach ($records as $record) {
                if ($currentTotal >= $max) {
                    $overflow++;

                    continue;
                }

                $assignedProjectId = $this->keywordAssignedContentProjectIdForSite($record, $targetSiteId);
                if ($assignedProjectId !== null) {
                    if ($assignedProjectId === $targetProjectId) {
                        $duplicate++;
                    } else {
                        $alreadyInProject++;
                    }

                    continue;
                }

                if ($projectSiteId > 0 && $targetSiteId !== $projectSiteId) {
                    $domainMismatch++;

                    continue;
                }

                $sourceContent = trim((string) $record->phrase);
                $siteId = $targetSiteId;
                $key = $siteId.'|'.SeoProjectTask::TYPE_CREATE.'|'.mb_strtolower($sourceContent);
                if (isset($existingMap[$key])) {
                    $duplicate++;

                    continue;
                }

                if (! $dryRun) {
                    try {
                        $task = $this->uniqueWriter->createOrReturnExisting([
                            'project_id' => (int) $project->id,
                            'site_id' => $siteId > 0 ? $siteId : null,
                            'article_id' => null,
                            'type' => SeoProjectTask::TYPE_CREATE,
                            'source_content' => $sourceContent,
                            'keyword' => $sourceContent,
                            'title' => null,
                            'secondary_description' => null,
                            'description' => null,
                            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                            'target_date' => $project->monthCarbon()->copy()->addDays($currentTotal)->format('Y-m-d'),
                            'status' => SeoProjectTask::STATUS_PENDING,
                        ]);
                    } catch (ValidationException) {
                        $duplicate++;
                        $existingMap[$key] = true;

                        continue;
                    }

                    if (! $task->wasRecentlyCreated) {
                        $duplicate++;
                        $existingMap[$key] = true;

                        continue;
                    }
                }

                $existingMap[$key] = true;
                $currentTotal++;
                $added++;
            }

            if (! $dryRun) {
                $project->syncTotalTasksCounter();
            }
        });

        return [
            'added' => $added,
            'duplicate' => $duplicate,
            'overflow' => $overflow,
            'domain_mismatch' => $domainMismatch,
            'already_in_project' => $alreadyInProject,
        ];
    }

    /**
     * Assign free-form planning phrases (Vocabulary) as TYPE_CREATE items.
     * Does not create/update Keyword Intelligence records and does not start generation.
     *
     * @param  list<string>  $phrases
     * @return array{added:int, duplicate:int, overflow:int, domain_mismatch:int, already_in_project:int, existing_article:int}
     */
    public function assignPhrases(
        array $phrases,
        int $projectId,
        int $targetSiteId,
        bool $dryRun = false,
        bool $ignoreMonthlyCapacity = false,
    ): array
    {
        $empty = static fn (int $overflow): array => [
            'added' => 0,
            'duplicate' => 0,
            'overflow' => $overflow,
            'domain_mismatch' => 0,
            'already_in_project' => 0,
            'existing_article' => 0,
        ];

        $normalized = [];
        foreach ($phrases as $phrase) {
            $trimmed = trim((string) $phrase);
            if ($trimmed === '') {
                continue;
            }
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
            if ($key === '' || isset($normalized[$key])) {
                continue;
            }
            $normalized[$key] = $trimmed;
        }

        if ($targetSiteId <= 0 || $normalized === []) {
            return $empty(count($phrases));
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            return $empty(count($normalized));
        }

        if ($project->isProjectArchived()) {
            return $empty(count($normalized));
        }

        $added = 0;
        $duplicate = 0;
        $overflow = 0;
        $domainMismatch = 0;
        $alreadyInProject = 0;
        $existingArticle = 0;
        $projectSiteId = (int) ($project->site_id ?? 0);
        $targetProjectId = (int) $project->id;

        DB::connection($project->getConnectionName())->transaction(function () use (
            $project,
            $normalized,
            $projectSiteId,
            $targetProjectId,
            $targetSiteId,
            $dryRun,
            $ignoreMonthlyCapacity,
            &$added,
            &$duplicate,
            &$overflow,
            &$domainMismatch,
            &$alreadyInProject,
            &$existingArticle,
        ): void {
            $project->refresh();
            $max = $project->maxTasksAllowed();
            $currentTotal = $project->registeredTaskCount();

            $existingKeys = SeoProjectTask::query()
                ->where('project_id', (int) $project->id)
                ->where('type', SeoProjectTask::TYPE_CREATE)
                ->get(['source_content', 'keyword', 'title'])
                ->map(static function (SeoProjectTask $task): string {
                    $needle = trim((string) ($task->keyword ?: $task->source_content ?: $task->title));

                    return mb_strtolower(preg_replace('/\s+/u', ' ', $needle) ?? $needle);
                })
                ->filter(static fn (string $key): bool => $key !== '')
                ->all();
            $existingMap = array_fill_keys($existingKeys, true);

            $otherProjectNeedles = SeoProjectTask::query()
                ->where('type', SeoProjectTask::TYPE_CREATE)
                ->where('site_id', $targetSiteId)
                ->where('project_id', '!=', $targetProjectId)
                ->get(['source_content', 'keyword', 'title'])
                ->map(static function (SeoProjectTask $task): string {
                    $needle = trim((string) ($task->keyword ?: $task->source_content ?: $task->title));

                    return mb_strtolower(preg_replace('/\s+/u', ' ', $needle) ?? $needle);
                })
                ->filter(static fn (string $key): bool => $key !== '')
                ->all();
            $otherProjectMap = array_fill_keys($otherProjectNeedles, true);

            foreach ($normalized as $key => $sourceContent) {
                if (! $ignoreMonthlyCapacity && $currentTotal >= $max) {
                    $overflow++;

                    continue;
                }

                if ($projectSiteId > 0 && $targetSiteId !== $projectSiteId) {
                    $domainMismatch++;

                    continue;
                }

                if (isset($existingMap[$key])) {
                    $duplicate++;

                    continue;
                }

                if (isset($otherProjectMap[$key])) {
                    $alreadyInProject++;

                    continue;
                }

                $articleExists = SeoArticle::query()
                    ->where('site_id', $targetSiteId)
                    ->where(function ($query) use ($sourceContent): void {
                        $query->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower($sourceContent)])
                            ->orWhereHas('articleMetas', static function ($meta) use ($sourceContent): void {
                                $meta->where('meta_key', 'seo_focus_keyword')
                                    ->whereRaw('LOWER(TRIM(meta_value)) = ?', [mb_strtolower($sourceContent)]);
                            });
                    })
                    ->exists();
                if ($articleExists) {
                    $existingArticle++;
                }

                if (! $dryRun) {
                    try {
                        $task = $this->uniqueWriter->createOrReturnExisting([
                            'project_id' => (int) $project->id,
                            'site_id' => $targetSiteId > 0 ? $targetSiteId : null,
                            'article_id' => null,
                            'type' => SeoProjectTask::TYPE_CREATE,
                            'source_content' => $sourceContent,
                            'keyword' => $sourceContent,
                            'title' => $sourceContent,
                            'secondary_description' => null,
                            'description' => null,
                            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                            'target_date' => $project->monthCarbon()->copy()->addDays($currentTotal)->format('Y-m-d'),
                            'status' => SeoProjectTask::STATUS_PENDING,
                        ]);
                    } catch (ValidationException) {
                        $duplicate++;
                        $existingMap[$key] = true;

                        continue;
                    }

                    if (! $task->wasRecentlyCreated) {
                        $duplicate++;
                        $existingMap[$key] = true;

                        continue;
                    }
                }

                $existingMap[$key] = true;
                $currentTotal++;
                $added++;
            }

            if (! $dryRun) {
                $project->syncTotalTasksCounter();
            }
        });

        return [
            'added' => $added,
            'duplicate' => $duplicate,
            'overflow' => $overflow,
            'domain_mismatch' => $domainMismatch,
            'already_in_project' => $alreadyInProject,
            'existing_article' => $existingArticle,
        ];
    }

    public function canAssignKeyword(Keyword $keyword): bool
    {
        return SeoAccessControl::canMutateInSeoPanel()
            && in_array($keyword->type, [Keyword::TYPE_NORMAL, Keyword::TYPE_SUGGEST], true)
            && (int) ($keyword->main_articles_count ?? 0) < 1
            && $this->keywordAssignedContentProjectId($keyword) === null;
    }

    public function keywordAssignedContentProjectIdForSite(Keyword $keyword, int $siteId): ?int
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        if ($needle === '' || $siteId <= 0) {
            return null;
        }

        $projectId = SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->where('site_id', $siteId)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle])
            ->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }

    public function keywordAssignedContentProjectId(Keyword $keyword): ?int
    {
        $needle = mb_strtolower(trim((string) $keyword->phrase));
        $siteId = $keyword->resolveSiteId(SeoAccessControl::globalSiteId()) ?? 0;

        $query = SeoProjectTask::query()
            ->where('type', SeoProjectTask::TYPE_NEW_KEYWORD)
            ->whereRaw('LOWER(TRIM(source_content)) = ?', [$needle]);

        if ($siteId > 0) {
            $query->where(function ($builder) use ($siteId): void {
                $builder
                    ->where('site_id', $siteId)
                    ->orWhereNull('site_id');
            });
        }

        $projectId = $query->value('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }
}
