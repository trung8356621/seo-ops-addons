<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Models\SeedingReport;
use Omnichannel\Addons\Seeding\Models\SeedingTopic;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;

/**
 * Report commit point — persists proof + comment snapshot.
 */
final class SeedingReportService
{
    public function __construct(
        private readonly SeedingServiceResolver $resolver,
    ) {}

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
     * @return array{report: SeedingReport, user_report_count: int, required: int, completed: bool}
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

        $topic = SeedingTopic::query()
            ->forInstallation($this->resolver->installationNamespace())
            ->whereKey($topicId)
            ->first();

        if (! $topic instanceof SeedingTopic || $topic->isArchived()) {
            throw new InvalidArgumentException('Chủ đề không tồn tại');
        }

        if ((int) $topic->created_by === $userId) {
            throw new InvalidArgumentException('Không thể báo cáo chủ đề của chính bạn');
        }

        $required = $topic->requiredCommentsPerUser();
        $existing = (int) SeedingReport::query()
            ->where('topic_id', $topicId)
            ->where('user_id', $userId)
            ->count();

        if ($existing >= $required) {
            throw new InvalidArgumentException('Bạn đã hoàn thành chủ đề này');
        }

        $proof = $payload['proof'] ?? null;
        if (! $proof instanceof UploadedFile) {
            throw new InvalidArgumentException('Cần ảnh proof');
        }

        return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use (
            $payload,
            $topic,
            $topicId,
            $userId,
            $comment,
            $proof,
            $required,
            $existing,
        ): array {
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

            $userCount = $existing + 1;

            return [
                'report' => $report,
                'user_report_count' => $userCount,
                'required' => $required,
                'completed' => $userCount >= $required,
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

        $disk = Storage::disk('local');
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
}
