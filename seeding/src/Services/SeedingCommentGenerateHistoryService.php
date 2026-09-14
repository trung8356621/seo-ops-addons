<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Seeding\Models\SeedingCommentGenerateLog;
use Omnichannel\Addons\Seeding\Support\SeedingAiArchitecture;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;
use Throwable;

/**
 * Lightweight Gen Comment debug history — fixed 20-slot ring buffer.
 * Newest-first order is by monotonic sequence, never by slot id.
 */
class SeedingCommentGenerateHistoryService
{
    public const MAX_LOGS = SeedingAiArchitecture::RETENTION_MAX_LOGS;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  array{
     *     topic_id?: int|null,
     *     social?: string|null,
     *     quantity: int,
     *     mcp_context: string,
     *     final_prompt: string,
     *     ai_output?: string|null,
     *     provider?: string|null,
     *     model?: string|null,
     *     status: string,
     *     error_message?: string|null,
     *     generated_at?: \DateTimeInterface|string|null
     * }  $payload
     */
    public function record(array $payload): ?SeedingCommentGenerateLog
    {
        try {
            return DB::connection(SeedingServiceConfig::CONNECTION)->transaction(function () use ($payload): SeedingCommentGenerateLog {
                $meta = DB::connection(SeedingServiceConfig::CONNECTION)
                    ->table('seeding_comment_generate_log_meta')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->first();

                if ($meta === null) {
                    DB::connection(SeedingServiceConfig::CONNECTION)
                        ->table('seeding_comment_generate_log_meta')
                        ->insert(['id' => 1, 'next_sequence' => 0]);
                    $current = 0;
                } else {
                    $current = (int) ($meta->next_sequence ?? 0);
                }

                $sequence = $current + 1;
                DB::connection(SeedingServiceConfig::CONNECTION)
                    ->table('seeding_comment_generate_log_meta')
                    ->where('id', 1)
                    ->update(['next_sequence' => $sequence]);

                $slot = (($sequence - 1) % self::MAX_LOGS) + 1;
                $generatedAt = $payload['generated_at'] ?? now();

                $attributes = [
                    'slot' => $slot,
                    'sequence' => $sequence,
                    'topic_id' => isset($payload['topic_id']) ? (int) $payload['topic_id'] : null,
                    'social' => isset($payload['social']) ? $this->nullableString($payload['social']) : null,
                    'quantity' => max(1, (int) ($payload['quantity'] ?? 1)),
                    'mcp_context' => (string) ($payload['mcp_context'] ?? ''),
                    'final_prompt' => (string) ($payload['final_prompt'] ?? ''),
                    'ai_output' => isset($payload['ai_output']) ? $this->nullableString($payload['ai_output']) : null,
                    'provider' => isset($payload['provider']) ? $this->nullableString($payload['provider']) : null,
                    'model' => isset($payload['model']) ? $this->nullableString($payload['model']) : null,
                    'status' => (string) ($payload['status'] ?? self::STATUS_FAILED),
                    'error_message' => isset($payload['error_message']) ? $this->nullableString($payload['error_message']) : null,
                    'generated_at' => $generatedAt,
                ];

                if ($attributes['topic_id'] !== null && $attributes['topic_id'] <= 0) {
                    $attributes['topic_id'] = null;
                }

                SeedingCommentGenerateLog::query()->updateOrCreate(
                    ['slot' => $slot],
                    $attributes,
                );

                /** @var SeedingCommentGenerateLog $log */
                $log = SeedingCommentGenerateLog::query()->where('slot', $slot)->firstOrFail();

                return $log;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<SeedingCommentGenerateLog>
     */
    public function latest(int $limit = self::MAX_LOGS): array
    {
        $limit = max(1, min(self::MAX_LOGS, $limit));

        try {
            return SeedingCommentGenerateLog::query()
                ->orderByDesc('sequence')
                ->limit($limit)
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function findBySlot(int $slot): ?SeedingCommentGenerateLog
    {
        if ($slot < 1 || $slot > self::MAX_LOGS) {
            return null;
        }

        try {
            return SeedingCommentGenerateLog::query()->where('slot', $slot)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pure ring-slot helper for unit tests (1-based).
     */
    public static function slotForSequence(int $sequence, int $max = self::MAX_LOGS): int
    {
        $max = max(1, $max);
        $sequence = max(1, $sequence);

        return (($sequence - 1) % $max) + 1;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : (string) $value;
    }
}
