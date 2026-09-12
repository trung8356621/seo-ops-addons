<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicSourceType;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\Models\SeedingReport;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Omnichannel\Addons\Seeding\Support\SeedingTargetCalculator;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;

/**
 * Shared-topic commit points: one execution topic = one social + target_comments.
 */
final class SeedingSharedTopicService
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
        private readonly SeedingTargetCalculator $targets,
        private readonly SeedingSocialPlatformDetector $platformDetector = new SeedingSocialPlatformDetector,
    ) {}

    public function maxCommentsPerDay(): int
    {
        $raw = $this->resolver->resolve()->rawConfig;
        $max = (int) ($raw['max_comments_per_day'] ?? SeedingTargetCalculator::DEFAULT_MAX_COMMENTS_PER_DAY);

        return max(1, $max > 0 ? $max : SeedingTargetCalculator::DEFAULT_MAX_COMMENTS_PER_DAY);
    }

    public function activeMemberCount(): int
    {
        if (! Schema::hasTable('users')) {
            return 1;
        }

        $query = User::query();
        if (Schema::hasColumn('users', 'status')) {
            $query->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhere('status', '!=', User::STATUS_BLOCK);
            });
        }

        return max(1, (int) $query->count());
    }

    /**
     * Create one or many independent topic executions (1 social each).
     *
     * @param  array{
     *     title?: string|null,
     *     full_text: string,
     *     source_html?: string|null,
     *     social_url?: string|null,
     *     social_platform?: string|null,
     *     target_comments?: int|null,
     *     social_targets?: list<array{social_platform?: string, platform?: string, target_comments?: int, target?: int}>,
     *     links?: list<array<string, mixed>>,
     *     source_type?: string|null,
     *     created_by: int,
     *     created_by_display_name?: string|null,
     * }  $payload
     * @return list<SeedingTopic>
     */
    public function share(array $payload): array
    {
        $fullText = trim((string) ($payload['full_text'] ?? ''));
        if ($fullText === '') {
            throw new InvalidArgumentException('Nội dung chủ đề không được trống');
        }

        $createdBy = (int) ($payload['created_by'] ?? 0);
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('Thiếu tác giả');
        }

        $executions = $this->normalizeExecutions($payload);
        if ($executions === []) {
            throw new InvalidArgumentException('Cần chọn ít nhất 1 mạng xã hội và target');
        }

        $installationId = $this->resolver->installationNamespace();
        $socialUrl = $this->normalizeOptionalUrl($payload['social_url'] ?? null);
        $links = $this->normalizeLinks($payload['links'] ?? []);
        $sourceType = SeedingTopicSourceType::tryFrom((string) ($payload['source_type'] ?? 'manual'))
            ?? SeedingTopicSourceType::Manual;
        $memberCount = $this->activeMemberCount();

        return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use (
            $payload,
            $fullText,
            $createdBy,
            $installationId,
            $executions,
            $socialUrl,
            $links,
            $sourceType,
            $memberCount,
        ): array {
            $created = [];
            foreach ($executions as $execution) {
                $platform = $execution['platform'];
                $target = $execution['target'];
                $requiredPerUser = max(1, (int) ceil($target / max(1, $memberCount)));

                $topic = new SeedingTopic([
                    'installation_id' => $installationId,
                    'created_by' => $createdBy,
                    'created_by_display_name' => $this->nullableString($payload['created_by_display_name'] ?? null),
                    'title' => $this->nullableString($payload['title'] ?? null),
                    'full_text' => $fullText,
                    'source_html' => $this->nullableString($payload['source_html'] ?? null),
                    'social_url' => $socialUrl,
                    'social_platform' => $platform,
                    'links_json' => $links,
                    'status' => SeedingTopicStatus::Shared,
                    'source_type' => $sourceType,
                    'max_comments_target' => $target,
                    'member_count_at_share' => $memberCount,
                    'required_comments_per_user' => $requiredPerUser,
                    'completed_comments' => 0,
                    'shared_at' => Carbon::now(),
                ]);
                $topic->save();
                $created[] = $topic->fresh() ?? $topic;
            }

            return $created;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligibleFeedForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $installationId = $this->resolver->installationNamespace();
        $topics = SeedingTopic::query()
            ->forInstallation($installationId)
            ->sharedVisible()
            ->where('created_by', '!=', $userId)
            ->orderByDesc('shared_at')
            ->orderByDesc('id')
            ->get();

        if ($topics->isEmpty()) {
            return [];
        }

        $topicIds = $topics->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $counts = SeedingReport::query()
            ->where('user_id', $userId)
            ->whereIn('topic_id', $topicIds)
            ->selectRaw('topic_id, COUNT(*) as report_count')
            ->groupBy('topic_id')
            ->pluck('report_count', 'topic_id');

        $feed = [];
        foreach ($topics as $topic) {
            $required = $topic->requiredCommentsPerUser();
            $userCount = (int) ($counts[(int) $topic->id] ?? 0);
            if ($userCount >= $required) {
                continue;
            }
            if ($topic->isGloballyComplete()) {
                continue;
            }

            $feed[] = SeedingTopicPresenter::feedItem($topic, $userId, $userCount, true);
        }

        return $feed;
    }

    /**
     * Manager table — all topics including completed (never auto-drop).
     *
     * @param  array{
     *     status?: string|null,
     *     social?: string|null,
     *     created_by?: int|null,
     *     search?: string|null,
     * }  $filters
     * @return list<array<string, mixed>>
     */
    public function managerTopicRows(array $filters = []): array
    {
        $installationId = $this->resolver->installationNamespace();
        $query = SeedingTopic::query()
            ->forInstallation($installationId)
            ->whereNull('archived_at')
            ->orderByDesc('shared_at')
            ->orderByDesc('id');

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $mapped = $this->mapManagerStatusFilter($status);
            if ($mapped !== []) {
                $query->whereIn('status', $mapped);
            }
        }

        $social = SeedingSocialPlatform::tryFromLabelOrValue($filters['social'] ?? null);
        if ($social instanceof SeedingSocialPlatform) {
            $query->where('social_platform', $social->value);
        }

        $createdBy = (int) ($filters['created_by'] ?? 0);
        if ($createdBy > 0) {
            $query->where('created_by', $createdBy);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('title', 'like', $like)
                    ->orWhere('full_text', 'like', $like)
                    ->orWhere('social_url', 'like', $like);
            });
        }

        $rows = [];
        foreach ($query->limit(500)->get() as $topic) {
            $rows[] = SeedingTopicPresenter::managerRow($topic);
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function managerStats(): array
    {
        $installationId = $this->resolver->installationNamespace();
        $base = SeedingTopic::query()
            ->forInstallation($installationId)
            ->whereNull('archived_at');

        $running = (clone $base)->whereIn('status', SeedingTopicStatus::feedVisibleValues())->count();
        $done = (clone $base)->where('status', SeedingTopicStatus::Done->value)->count();
        $pending = (clone $base)->where('status', SeedingTopicStatus::Pending->value)->count();
        $paused = (clone $base)->where('status', SeedingTopicStatus::Paused->value)->count();

        $required = (int) (clone $base)->sum('max_comments_target');
        $completed = (int) (clone $base)->sum('completed_comments');

        $bySocial = [];
        foreach (
            SeedingTopic::query()
                ->forInstallation($installationId)
                ->whereNull('archived_at')
                ->selectRaw('social_platform, SUM(max_comments_target) as required_sum, SUM(completed_comments) as completed_sum, COUNT(*) as topic_count')
                ->groupBy('social_platform')
                ->get() as $row
        ) {
            $platform = $row->social_platform;
            $key = $platform instanceof SeedingSocialPlatform
                ? $platform->value
                : (is_string($platform) && $platform !== '' ? $platform : 'other');
            $bySocial[$key] = [
                'topics' => (int) $row->topic_count,
                'required' => (int) $row->required_sum,
                'completed' => (int) $row->completed_sum,
            ];
        }

        return [
            'topics_running' => $running,
            'topics_done' => $done,
            'topics_pending' => $pending,
            'topics_paused' => $paused,
            'comments_required' => $required,
            'comments_completed' => $completed,
            'by_social' => $bySocial,
        ];
    }

    public function pauseTopic(int $topicId): SeedingTopic
    {
        return $this->transitionStatus($topicId, SeedingTopicStatus::Paused, [
            'paused_at' => Carbon::now(),
        ]);
    }

    public function resumeTopic(int $topicId): SeedingTopic
    {
        $topic = $this->findManagedTopic($topicId);
        if ($topic->isCancelled() || $topic->isArchived()) {
            throw new InvalidArgumentException('Không thể resume chủ đề đã hủy/lưu trữ');
        }
        if ($topic->isGloballyComplete()) {
            throw new InvalidArgumentException('Chủ đề đã hoàn thành');
        }

        $topic->status = SeedingTopicStatus::Shared;
        $topic->paused_at = null;
        $topic->save();

        return $topic->fresh() ?? $topic;
    }

    public function cancelTopic(int $topicId): SeedingTopic
    {
        return $this->transitionStatus($topicId, SeedingTopicStatus::Cancelled, [
            'cancelled_at' => Carbon::now(),
            'paused_at' => null,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function linkUsageTodayForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $start = Carbon::now()->startOfDay();
        $rows = SeedingReport::query()
            ->where('user_id', $userId)
            ->where('reported_at', '>=', $start)
            ->whereNotNull('seed_link_id')
            ->where('seed_link_id', '!=', '')
            ->selectRaw('seed_link_id, COUNT(*) as used_count')
            ->groupBy('seed_link_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->seed_link_id] = (int) $row->used_count;
        }

        return $map;
    }

    /**
     * @return Collection<int, SeedingTopic>
     */
    public function topicsCreatedBy(int $userId): Collection
    {
        if ($userId <= 0) {
            return collect();
        }

        return SeedingTopic::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->where('created_by', $userId)
            ->whereNull('archived_at')
            ->orderByDesc('shared_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{platform: SeedingSocialPlatform, target: int}>
     */
    private function normalizeExecutions(array $payload): array
    {
        $rawTargets = $payload['social_targets'] ?? null;
        if (is_array($rawTargets) && $rawTargets !== []) {
            $out = [];
            foreach ($rawTargets as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $platform = SeedingSocialPlatform::tryFromLabelOrValue(
                    (string) ($row['social_platform'] ?? $row['platform'] ?? '')
                );
                $target = (int) ($row['target_comments'] ?? $row['target'] ?? 0);
                if (! $platform instanceof SeedingSocialPlatform || $target <= 0) {
                    continue;
                }
                $out[] = ['platform' => $platform, 'target' => $target];
            }

            return $out;
        }

        $platform = SeedingSocialPlatform::tryFromLabelOrValue(
            (string) ($payload['social_platform'] ?? '')
        );
        if (! $platform instanceof SeedingSocialPlatform) {
            $socialUrl = $this->normalizeOptionalUrl($payload['social_url'] ?? null);
            $platform = $socialUrl !== null
                ? $this->platformDetector->detect($socialUrl)
                : null;
        }

        if (! $platform instanceof SeedingSocialPlatform) {
            throw new InvalidArgumentException('Social là bắt buộc');
        }

        $target = (int) ($payload['target_comments'] ?? $payload['max_comments_target'] ?? 0);
        if ($target <= 0) {
            $target = $this->maxCommentsPerDay();
        }

        return [['platform' => $platform, 'target' => max(1, $target)]];
    }

    /**
     * @return list<string>
     */
    private function mapManagerStatusFilter(string $status): array
    {
        return match ($status) {
            'running', 'dang_chay' => SeedingTopicStatus::feedVisibleValues(),
            'pending', 'cho_bat_dau' => [SeedingTopicStatus::Pending->value],
            'done', 'completed', 'hoan_thanh' => [SeedingTopicStatus::Done->value],
            'paused', 'tam_dung' => [SeedingTopicStatus::Paused->value],
            'cancelled', 'da_huy' => [SeedingTopicStatus::Cancelled->value],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transitionStatus(int $topicId, SeedingTopicStatus $status, array $extra = []): SeedingTopic
    {
        $topic = $this->findManagedTopic($topicId);
        $topic->status = $status;
        foreach ($extra as $key => $value) {
            $topic->{$key} = $value;
        }
        $topic->save();

        return $topic->fresh() ?? $topic;
    }

    private function findManagedTopic(int $topicId): SeedingTopic
    {
        $topic = SeedingTopic::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->whereKey($topicId)
            ->first();

        if (! $topic instanceof SeedingTopic || $topic->isArchived()) {
            throw new InvalidArgumentException('Chủ đề không tồn tại');
        }

        return $topic;
    }

    /**
     * @param  list<mixed>  $links
     * @return list<array<string, mixed>>
     */
    private function normalizeLinks(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $url = trim($link);
                if ($url !== '') {
                    $out[] = ['url' => $url];
                }
                continue;
            }
            if (! is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? $link['normalized_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'url' => $url,
                'normalized_url' => isset($link['normalized_url']) ? (string) $link['normalized_url'] : null,
                'preview_title' => $link['preview_title'] ?? null,
                'preview_description' => $link['preview_description'] ?? null,
                'preview_image_url' => $link['preview_image_url'] ?? null,
                'preview_domain' => $link['preview_domain'] ?? null,
            ];
        }

        return $out;
    }

    private function normalizeOptionalUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL không hợp lệ');
        }

        return $url;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
