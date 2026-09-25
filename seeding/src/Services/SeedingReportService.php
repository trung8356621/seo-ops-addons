<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\Models\SeedingLinkAssignment;
use Omnichannel\Addons\Seeding\Models\SeedingReport;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Report commit point — atomic vs global target_comments (no overcount).
 * Manager read APIs list/serve proof scoped through Topic.installation_id.
 */
final class SeedingReportService
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
    ) {}

    /**
     * Manager-only listing — scoped via Topic.installation_id (reports table has no installation_id).
     *
     * @param  array{
     *     user_id?: int|string|null,
     *     topic_id?: int|string|null,
     *     social?: string|null,
     *     search?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     status?: string|null,
     * }  $filters
     * @return array{reports: list<array<string, mixed>>, members: list<array{user_id: int, user_display_name: string}>}
     */
    public function listForManager(array $filters = []): array
    {
        $installationId = $this->resolver->installationNamespace();
        $query = $this->managerScopedQuery($installationId)
            ->with('topic')
            ->orderByDesc('reported_at')
            ->orderByDesc('id');

        $this->applyManagerFilters($query, $filters);

        /** @var list<SeedingReport> $rows */
        $rows = $query->limit(500)->get()->all();
        $assignmentTitles = $this->resolveAssignmentTitles($rows, $installationId);

        $reports = [];
        foreach ($rows as $report) {
            $reports[] = SeedingTopicPresenter::managerReport(
                $report,
                $assignmentTitles[$this->assignmentKey($report->seed_link_id)] ?? null,
            );
        }

        return [
            'reports' => $reports,
            'members' => $this->distinctMembers($installationId),
        ];
    }

    /**
     * Resolve a single report for the current installation (Topic scope). Null if missing/cross-tenant.
     */
    public function findForManager(int $reportId): ?SeedingReport
    {
        if ($reportId <= 0) {
            return null;
        }

        /** @var SeedingReport|null $report */
        $report = $this->managerScopedQuery($this->resolver->installationNamespace())
            ->with('topic')
            ->whereKey($reportId)
            ->first();

        return $report instanceof SeedingReport ? $report : null;
    }

    /**
     * Stream proof bytes from local disk. Path comes only from the report row — never from the request.
     */
    public function streamProofForManager(int $reportId): SymfonyResponse
    {
        $report = $this->findForManager($reportId);
        if (! $report instanceof SeedingReport) {
            abort(404);
        }

        $path = $this->safeLocalProofPath($report->proof_path);
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $mime = trim((string) ($report->proof_mime ?? ''));
        if ($mime === '' || ! str_starts_with($mime, 'image/')) {
            $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';
        }

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Manager review metadata only — does not mutate Topic counters / seed quotas / proof payload.
     * Idempotent: already-approved reports return the existing read model.
     *
     * @return array<string, mixed>
     */
    public function approveForManager(int $reportId, int $managerUserId): array
    {
        $report = $this->findForManager($reportId);
        if (! $report instanceof SeedingReport) {
            abort(404);
        }

        if ($managerUserId <= 0) {
            abort(401);
        }

        if ($report->approved_at === null) {
            $report->approved_at = Carbon::now();
            $report->approved_by = $managerUserId;
            $report->save();
        }

        if (! $report->relationLoaded('topic')) {
            $report->load('topic');
        }

        $titles = $this->resolveAssignmentTitles(
            [$report],
            $this->resolver->installationNamespace(),
        );

        return SeedingTopicPresenter::managerReport(
            $report,
            $titles[$this->assignmentKey($report->seed_link_id)] ?? null,
        );
    }

    /**
     * @param  array{
     *     topic_id: int,
     *     user_id: int,
     *     user_display_name?: string|null,
     *     comment_text: string,
     *     seed_link_id?: string|null,
     *     seed_url?: string|null,
     *     proof?: UploadedFile|null,
     * }  $payload
     * @return array{
     *     report: SeedingReport,
     *     user_report_count: int,
     *     required: int,
     *     completed: bool,
     *     global_completed: int,
     *     global_target: int,
     *     topic_done: bool
     * }
     */
    public function submit(array $payload): array
    {
        $topicId = (int) ($payload['topic_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        $comment = trim((string) ($payload['comment_text'] ?? ''));

        if ($topicId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Thiếu topic hoặc user');
        }
        if ($comment === '') {
            throw new InvalidArgumentException('Thiếu nội dung comment');
        }

        $proof = $payload['proof'] ?? null;
        if (! $proof instanceof UploadedFile) {
            throw new InvalidArgumentException('Cần ảnh proof');
        }

        return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use (
            $payload,
            $topicId,
            $userId,
            $comment,
            $proof,
        ): array {
            /** @var SeedingTopic|null $topic */
            $topic = SeedingTopic::query()
                ->forInstallation($this->resolver->installationNamespace())
                ->whereKey($topicId)
                ->lockForUpdate()
                ->first();

            if (! $topic instanceof SeedingTopic || $topic->isArchived()) {
                throw new InvalidArgumentException('Chủ đề không tồn tại');
            }

            if ((int) $topic->created_by === $userId) {
                throw new InvalidArgumentException('Không thể báo cáo chủ đề của chính bạn');
            }

            if ($topic->isPaused()) {
                throw new InvalidArgumentException('Chủ đề đang tạm dừng');
            }

            if ($topic->isCancelled()) {
                throw new InvalidArgumentException('Chủ đề đã bị hủy');
            }

            $globalTarget = $topic->targetComments();
            $globalCompleted = max(0, (int) $topic->completed_comments);
            if ($globalCompleted >= $globalTarget || $topic->status === SeedingTopicStatus::Done) {
                throw new InvalidArgumentException('Chủ đề đã đủ quota');
            }

            $required = $topic->requiredCommentsPerUser();
            $existing = (int) SeedingReport::query()
                ->where('topic_id', $topicId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->count();

            if ($existing >= $required) {
                throw new InvalidArgumentException('Bạn đã hoàn thành chủ đề này');
            }

            $stored = $this->storeProof($proof, $topicId, $userId);

            $report = new SeedingReport([
                'topic_id' => $topicId,
                'user_id' => $userId,
                'user_display_name' => $this->nullableString($payload['user_display_name'] ?? null),
                'comment_text' => $comment,
                'seed_link_id' => $this->nullableString($payload['seed_link_id'] ?? null),
                'seed_url' => $this->nullableString($payload['seed_url'] ?? null),
                'proof_path' => $stored['path'],
                'proof_mime' => $stored['mime'],
                'proof_meta' => $stored['meta'],
                'reported_at' => Carbon::now(),
            ]);
            $report->save();

            $newGlobal = $globalCompleted + 1;
            $topic->completed_comments = $newGlobal;
            $topicDone = $newGlobal >= $globalTarget;
            if ($topicDone) {
                $topic->status = SeedingTopicStatus::Done;
                $topic->completed_at = Carbon::now();
            } elseif ($topic->status === SeedingTopicStatus::Pending) {
                $topic->status = SeedingTopicStatus::Active;
            }
            $topic->save();

            $userCount = $existing + 1;

            return [
                'report' => $report,
                'user_report_count' => $userCount,
                'required' => $required,
                'completed' => $userCount >= $required || $topicDone,
                'global_completed' => $newGlobal,
                'global_target' => $globalTarget,
                'topic_done' => $topicDone,
            ];
        });
    }

    /**
     * @return array{path: string, mime: string, meta: array<string, mixed>}
     */
    private function storeProof(UploadedFile $file, int $topicId, int $userId): array
    {
        if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
            throw new InvalidArgumentException('Proof phải là ảnh');
        }

        $dir = 'seeding/proofs/'.$topicId;
        $path = $file->store($dir, 'local');
        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException('Không lưu được proof');
        }

        return [
            'path' => $path,
            'mime' => (string) ($file->getMimeType() ?: 'image/jpeg'),
            'meta' => [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'user_id' => $userId,
                'stored_at' => Carbon::now()->toIso8601String(),
                'disk' => 'local',
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return Builder<SeedingReport>
     */
    private function managerScopedQuery(string $installationId): Builder
    {
        return SeedingReport::query()
            ->whereHas('topic', static function (Builder $topic) use ($installationId): void {
                $topic->forInstallation($installationId);
            });
    }

    /**
     * @param  Builder<SeedingReport>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyManagerFilters(Builder $query, array $filters): void
    {
        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $topicId = (int) ($filters['topic_id'] ?? 0);
        if ($topicId > 0) {
            $query->where('topic_id', $topicId);
        }

        $social = SeedingSocialPlatform::tryFromLabelOrValue(
            is_string($filters['social'] ?? null) ? (string) $filters['social'] : null
        );
        if ($social instanceof SeedingSocialPlatform) {
            $query->whereHas('topic', static function (Builder $topic) use ($social): void {
                $topic->where('social_platform', $social->value);
            });
        }

        $dateFrom = $this->parseFilterDate($filters['date_from'] ?? null, startOfDay: true);
        if ($dateFrom !== null) {
            $query->where('reported_at', '>=', $dateFrom);
        }

        $dateTo = $this->parseFilterDate($filters['date_to'] ?? null, startOfDay: false);
        if ($dateTo !== null) {
            $query->where('reported_at', '<=', $dateTo);
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status === 'pending') {
            $query->whereNull('approved_at');
        } elseif ($status === 'approved') {
            $query->whereNotNull('approved_at');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $q) use ($like): void {
            $q->where('comment_text', 'like', $like)
                ->orWhere('user_display_name', 'like', $like)
                ->orWhere('seed_url', 'like', $like)
                ->orWhere('seed_link_id', 'like', $like)
                ->orWhereHas('topic', static function (Builder $topic) use ($like): void {
                    $topic->where('title', 'like', $like)
                        ->orWhere('full_text', 'like', $like)
                        ->orWhere('social_url', 'like', $like);
                });
        });
    }

    /**
     * @return list<array{user_id: int, user_display_name: string}>
     */
    private function distinctMembers(string $installationId): array
    {
        $rows = $this->managerScopedQuery($installationId)
            ->select(['user_id', 'user_display_name'])
            ->orderBy('user_display_name')
            ->orderBy('user_id')
            ->get();

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if ($uid <= 0 || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $name = trim((string) ($row->user_display_name ?? ''));
            $out[] = [
                'user_id' => $uid,
                'user_display_name' => $name !== '' ? $name : ('#'.$uid),
            ];
        }

        return $out;
    }

    /**
     * @param  list<SeedingReport>  $reports
     * @return array<string, string>
     */
    private function resolveAssignmentTitles(array $reports, string $installationId): array
    {
        $ids = [];
        foreach ($reports as $report) {
            $numeric = $this->assignmentNumericId($report->seed_link_id);
            if ($numeric !== null) {
                $ids[$numeric] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, SeedingLinkAssignment> $assignments */
        $assignments = SeedingLinkAssignment::query()
            ->forInstallation($installationId)
            ->whereIn('id', array_keys($ids))
            ->get()
            ->keyBy(static fn (SeedingLinkAssignment $row): int => (int) $row->id);

        $map = [];
        foreach ($assignments as $id => $assignment) {
            $title = trim((string) ($assignment->title ?? ''));
            if ($title !== '') {
                $map['assign:'.$id] = $title;
            }
        }

        return $map;
    }

    private function assignmentKey(?string $seedLinkId): string
    {
        $numeric = $this->assignmentNumericId($seedLinkId);

        return $numeric !== null ? 'assign:'.$numeric : '';
    }

    private function assignmentNumericId(?string $seedLinkId): ?int
    {
        $raw = trim((string) $seedLinkId);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^assign:(\d+)$/i', $raw, $m) === 1) {
            return (int) $m[1];
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function parseFilterDate(mixed $raw, bool $startOfDay): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $dt = Carbon::parse(trim($raw));
        } catch (\Throwable) {
            return null;
        }

        return $startOfDay ? $dt->startOfDay() : $dt->endOfDay();
    }

    /**
     * Only allow relative paths under seeding/proofs/ — reject traversal / absolute paths.
     */
    private function safeLocalProofPath(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $path = str_replace('\\', '/', trim($raw));
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }
        if (preg_match('#^[a-zA-Z]:/#', $path) === 1) {
            return null;
        }
        if (! str_starts_with($path, 'seeding/proofs/')) {
            return null;
        }

        return $path;
    }
}
