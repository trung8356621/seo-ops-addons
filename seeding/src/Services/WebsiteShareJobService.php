<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\Core\Event\ArticleIndexStatusChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Enums\WebsiteShareJobStatus;
use Omnichannel\Addons\Seeding\Jobs\CheckArticleForWebsiteShareJob;
use Omnichannel\Addons\Seeding\Models\WebsiteShareJob;
use Omnichannel\Addons\Seeding\Models\WebsiteShareReport;
use Omnichannel\Addons\Seeding\Models\WebsiteShareTarget;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Omnichannel\Addons\Seeding\Support\WebsiteSharePresenter;

/**
 * Website Share Jobs — delayed after SEO index; not Topic Comment seeding.
 */
final class WebsiteShareJobService
{
    public const DELAY_MINUTES = 10;

    /** @var list<string> */
    public const DEFAULT_SOCIALS = [
        'facebook',
        'threads',
        'pinterest',
    ];

    public function __construct(
        private readonly SeedingServiceResolver $resolver,
    ) {}

    public function handleIndexStatusChanged(ArticleIndexStatusChanged $event): void
    {
        if ($event->articleId <= 0) {
            return;
        }

        if (! $event->indexed) {
            $this->cancelScheduledForArticle($event->articleId);

            return;
        }

        $indexedAt = $event->indexedAt() ?? Carbon::now();
        $generation = $indexedAt->toIso8601String();
        $installationId = $this->resolver->installationNamespace();

        $existing = WebsiteShareJob::query()
            ->forInstallation($installationId)
            ->where('article_id', $event->articleId)
            ->where('index_generation', $generation)
            ->first();

        if ($existing instanceof WebsiteShareJob) {
            return;
        }

        $eligibleAt = Carbon::now()->addMinutes(self::DELAY_MINUTES);

        $job = WebsiteShareJob::query()->create([
            'installation_id' => $installationId,
            'article_id' => $event->articleId,
            'site_id' => $event->siteId,
            'index_generation' => $generation,
            'source_type' => 'seo_index',
            'status' => WebsiteShareJobStatus::Scheduled,
            'title' => $event->articleTitle,
            'article_url' => $event->articleUrl,
            'domain' => $event->domain,
            'thumbnail_url' => $event->thumbnailUrl,
            'indexed_at' => $indexedAt,
            'eligible_at' => $eligibleAt,
        ]);

        CheckArticleForWebsiteShareJob::dispatch((int) $job->id)
            ->delay($eligibleAt);
    }

    public function promoteIfStillIndexed(int $jobId, bool $stillIndexed): ?WebsiteShareJob
    {
        return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use ($jobId, $stillIndexed): ?WebsiteShareJob {
            /** @var WebsiteShareJob|null $job */
            $job = WebsiteShareJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $job instanceof WebsiteShareJob) {
                return null;
            }

            if ($job->status !== WebsiteShareJobStatus::Scheduled) {
                return $job;
            }

            if (! $stillIndexed) {
                $job->status = WebsiteShareJobStatus::Cancelled;
                $job->cancelled_at = Carbon::now();
                $job->save();

                return $job;
            }

            $job->status = WebsiteShareJobStatus::Ready;
            $job->save();

            if ($job->targets()->count() === 0) {
                foreach (self::DEFAULT_SOCIALS as $social) {
                    $platform = SeedingSocialPlatform::tryFrom($social) ?? SeedingSocialPlatform::Other;
                    WebsiteShareTarget::query()->create([
                        'job_id' => (int) $job->id,
                        'social' => $platform,
                        'target_count' => 1,
                        'completed_count' => 0,
                    ]);
                }
            }

            return $job->fresh(['targets']) ?? $job;
        });
    }

    public function cancelScheduledForArticle(int $articleId): void
    {
        WebsiteShareJob::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->where('article_id', $articleId)
            ->where('status', WebsiteShareJobStatus::Scheduled->value)
            ->update([
                'status' => WebsiteShareJobStatus::Cancelled->value,
                'cancelled_at' => Carbon::now(),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function feedForUser(int $userId, ?string $filter = null): array
    {
        $query = WebsiteShareJob::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->feedVisible()
            ->with('targets')
            ->orderByDesc('indexed_at')
            ->orderByDesc('id');

        $filter = trim((string) $filter);
        if ($filter !== '' && $filter !== 'all') {
            $status = match ($filter) {
                'pending_content', 'cho_tao_noi_dung' => WebsiteShareJobStatus::Ready,
                'has_content', 'co_noi_dung' => WebsiteShareJobStatus::HasContent,
                'sharing', 'dang_chia_se' => WebsiteShareJobStatus::Sharing,
                'completed', 'da_hoan_thanh' => WebsiteShareJobStatus::Completed,
                'scheduled', 'cho_tao_nhiem_vu' => WebsiteShareJobStatus::Scheduled,
                default => null,
            };
            if ($status instanceof WebsiteShareJobStatus) {
                $query->where('status', $status->value);
            }
        }

        $rows = [];
        foreach ($query->limit(200)->get() as $job) {
            $rows[] = WebsiteSharePresenter::feedCard($job);
        }

        return $rows;
    }

    public function updateShareContent(int $jobId, string $content): WebsiteShareJob
    {
        $job = $this->findJob($jobId);
        if ($job->status === WebsiteShareJobStatus::Scheduled) {
            throw new InvalidArgumentException('Chưa đến giờ tạo nhiệm vụ');
        }
        if ($job->status === WebsiteShareJobStatus::Cancelled) {
            throw new InvalidArgumentException('Nhiệm vụ đã hủy');
        }

        $text = trim($content);
        $job->share_content = $text === '' ? null : $text;
        $job->content_generated_at = $text === '' ? null : Carbon::now();
        if ($text !== '' && in_array($job->status, [WebsiteShareJobStatus::Ready, WebsiteShareJobStatus::HasContent], true)) {
            $job->status = WebsiteShareJobStatus::HasContent;
        }
        $job->save();

        return $job->fresh(['targets']) ?? $job;
    }

    /**
     * @param  array{
     *     job_id: int,
     *     social: string,
     *     user_id: int,
     *     user_display_name?: string|null,
     *     share_text?: string|null,
     *     proof_path?: string|null,
     *     proof_mime?: string|null,
     *     proof_meta?: array<string, mixed>|null,
     * }  $payload
     * @return array{report: WebsiteShareReport, target: WebsiteShareTarget, job: WebsiteShareJob}
     */
    public function submitShareReport(array $payload): array
    {
        $jobId = (int) ($payload['job_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        $social = SeedingSocialPlatform::tryFromLabelOrValue((string) ($payload['social'] ?? ''));

        if ($jobId <= 0 || $userId <= 0 || ! $social instanceof SeedingSocialPlatform) {
            throw new InvalidArgumentException('Thiếu job hoặc social');
        }

        return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use ($payload, $jobId, $userId, $social): array {
            /** @var WebsiteShareJob|null $job */
            $job = WebsiteShareJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $job instanceof WebsiteShareJob || $job->status === WebsiteShareJobStatus::Cancelled) {
                throw new InvalidArgumentException('Nhiệm vụ không tồn tại');
            }
            if ($job->status === WebsiteShareJobStatus::Scheduled) {
                throw new InvalidArgumentException('Chưa đến giờ chia sẻ');
            }

            /** @var WebsiteShareTarget|null $target */
            $target = WebsiteShareTarget::query()
                ->where('job_id', $jobId)
                ->where('social', $social->value)
                ->lockForUpdate()
                ->first();

            if (! $target instanceof WebsiteShareTarget) {
                throw new InvalidArgumentException('Không có target social này');
            }

            if ($target->isComplete()) {
                throw new InvalidArgumentException('Đã đủ target share cho social này');
            }

            $report = new WebsiteShareReport([
                'job_id' => $jobId,
                'target_id' => (int) $target->id,
                'user_id' => $userId,
                'user_display_name' => isset($payload['user_display_name'])
                    ? trim((string) $payload['user_display_name'])
                    : null,
                'social' => $social,
                'share_text' => isset($payload['share_text']) ? trim((string) $payload['share_text']) : null,
                'proof_path' => $payload['proof_path'] ?? null,
                'proof_mime' => $payload['proof_mime'] ?? null,
                'proof_meta' => $payload['proof_meta'] ?? null,
                'reported_at' => Carbon::now(),
            ]);
            $report->save();

            $target->completed_count = (int) $target->completed_count + 1;
            $target->save();

            $job->load('targets');
            $allDone = $job->targets->every(static fn (WebsiteShareTarget $t): bool => $t->isComplete());
            if ($allDone) {
                $job->status = WebsiteShareJobStatus::Completed;
                $job->completed_at = Carbon::now();
            } else {
                $job->status = WebsiteShareJobStatus::Sharing;
            }
            $job->save();

            return [
                'report' => $report,
                'target' => $target,
                'job' => $job->fresh(['targets']) ?? $job,
            ];
        });
    }

    /**
     * @return array<string, int|array<string, int>>
     */
    public function managerStats(): array
    {
        $base = WebsiteShareJob::query()->forInstallation($this->resolver->installationNamespace());

        $bySocial = [];
        foreach (
            WebsiteShareTarget::query()
                ->whereIn('job_id', (clone $base)->select('id'))
                ->selectRaw('social, SUM(target_count) as target_sum, SUM(completed_count) as completed_sum')
                ->groupBy('social')
                ->get() as $row
        ) {
            $social = $row->social;
            $key = $social instanceof SeedingSocialPlatform
                ? $social->value
                : (is_string($social) && $social !== '' ? $social : 'other');
            $bySocial[$key] = [
                'target' => (int) $row->target_sum,
                'completed' => (int) $row->completed_sum,
            ];
        }

        return [
            'pending' => (clone $base)->where('status', WebsiteShareJobStatus::Scheduled->value)->count()
                + (clone $base)->where('status', WebsiteShareJobStatus::Ready->value)->count(),
            'ready' => (clone $base)->whereIn('status', [
                WebsiteShareJobStatus::Ready->value,
                WebsiteShareJobStatus::HasContent->value,
            ])->count(),
            'completed' => (clone $base)->where('status', WebsiteShareJobStatus::Completed->value)->count(),
            'by_social' => $bySocial,
        ];
    }

    private function findJob(int $jobId): WebsiteShareJob
    {
        $job = WebsiteShareJob::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->whereKey($jobId)
            ->first();

        if (! $job instanceof WebsiteShareJob) {
            throw new InvalidArgumentException('Nhiệm vụ không tồn tại');
        }

        return $job;
    }
}
