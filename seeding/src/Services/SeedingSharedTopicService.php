<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\Models\SeedingReport;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Omnichannel\Addons\Seeding\Support\SeedingTargetCalculator;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;

/**
 * Shared-topic commit points: share draft → DB, eligible feed for seeders.
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

    /**
     * Active seeding members — non-blocked users on this client.
     */
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
     * @param  array{
     *     title?: string|null,
     *     full_text: string,
     *     source_html?: string|null,
     *     social_url?: string|null,
     *     links?: list<array<string, mixed>>,
     *     created_by: int,
     *     created_by_display_name?: string|null,
     * }  $payload
     */
    public function share(array $payload): SeedingTopic
    {
        $fullText = trim((string) ($payload['full_text'] ?? ''));
        if ($fullText === '') {
            throw new InvalidArgumentException('Nội dung chủ đề không được trống');
        }

        $createdBy = (int) ($payload['created_by'] ?? 0);
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('Thiếu tác giả');
        }

        $installationId = $this->resolver->installationNamespace();
        $snapshot = $this->targets->snapshot($this->maxCommentsPerDay(), $this->activeMemberCount());
        $socialUrl = $this->normalizeOptionalUrl($payload['social_url'] ?? null);
        $platform = $socialUrl !== null
            ? $this->platformDetector->detect($socialUrl)
            : null;

        return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use (
            $payload,
            $fullText,
            $createdBy,
            $installationId,
            $snapshot,
            $socialUrl,
            $platform,
        ): SeedingTopic {
            $topic = new SeedingTopic([
                'installation_id' => $installationId,
                'created_by' => $createdBy,
                'created_by_display_name' => $this->nullableString($payload['created_by_display_name'] ?? null),
                'title' => $this->nullableString($payload['title'] ?? null),
                'full_text' => $fullText,
                'source_html' => $this->nullableString($payload['source_html'] ?? null),
                'social_url' => $socialUrl,
                'social_platform' => $platform,
                'links_json' => $this->normalizeLinks($payload['links'] ?? []),
                'status' => SeedingTopicStatus::Shared,
                'max_comments_target' => $snapshot['max_comments_target'],
                'member_count_at_share' => $snapshot['member_count_at_share'],
                'required_comments_per_user' => $snapshot['required_comments_per_user'],
                'shared_at' => Carbon::now(),
            ]);
            $topic->save();

            return $topic->fresh() ?? $topic;
        });
    }

    /**
     * Eligible shared feed for current user (excludes own + completed).
     *
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

            $feed[] = SeedingTopicPresenter::feedItem($topic, $userId, $userCount, true);
        }

        return $feed;
    }

    /**
     * Link usage today from reports (source of truth for soft limits).
     *
     * @return array<string, int> seed_link_id => count
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
