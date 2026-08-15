<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use App\Models\SeoDatabaseConnection;

/**
 * Human presentation for operational queue-health status.
 * Does not persist formatted strings; cache/API stay machine-readable.
 *
 * @phpstan-type Display array{text: string, tooltip: string|null, empty: bool, raw: string|null}
 */
final class OperationalStatusFormatter
{
    /**
     * @param  (\Closure(int): (?string))|null  $connectionLabelResolver
     */
    public function __construct(
        private readonly mixed $connectionLabelResolver = null,
    ) {}

    /**
     * @return Display
     */
    public function formatWorker(?string $raw): array
    {
        return $this->formatTimestampOnly(
            $raw,
            $this->t('health_empty_worker', 'No worker activity yet', 'Chưa có hoạt động worker'),
        );
    }

    /**
     * @return Display
     */
    public function formatSuccess(?string $raw, bool $includeDomain = false): array
    {
        $parsed = OperationalStatusParser::parse($raw);
        if ($parsed['occurred_at'] === null) {
            return $this->emptyDisplay(
                $this->t('health_empty_success', 'No successful run yet', 'Chưa có lần chạy thành công'),
                $parsed['raw'],
            );
        }

        $parts = [$this->compactTimestamp($parsed['occurred_at'])];
        if ($parsed['count'] !== null && $parsed['count'] >= 0) {
            $parts[] = $this->taskCount($parsed['count']);
        }
        if ($includeDomain) {
            $domain = $this->connectionLabel($parsed['connection_id']);
            if ($domain !== null) {
                $parts[] = $domain;
            }
        }

        return [
            'text' => implode(' · ', $parts),
            'tooltip' => $this->preciseTimestamp($parsed['occurred_at']),
            'empty' => false,
            'raw' => $parsed['raw'],
        ];
    }

    /**
     * @return Display
     */
    public function formatFailure(?string $raw): array
    {
        $parsed = OperationalStatusParser::parse($raw);
        if ($parsed['occurred_at'] === null && $parsed['message'] === null && $parsed['reason'] === null && $parsed['flags'] === []) {
            return $this->emptyDisplay(
                $this->t('health_empty_failure', 'No failure yet', 'Chưa có lỗi'),
                $parsed['raw'],
            );
        }

        $parts = [];
        if ($parsed['occurred_at'] !== null) {
            $parts[] = $this->compactTimestamp($parsed['occurred_at']);
        }
        if ($parsed['due'] !== null && $parsed['due'] > 0) {
            $parts[] = $this->dueCount($parsed['due']);
        }

        $hasNoProgress = in_array('no_progress', $parsed['flags'], true)
            || $parsed['reason'] === 'no_progress';
        if ($hasNoProgress) {
            $parts[] = $this->reasonLabel('no_progress');
        }

        $reason = $parsed['reason'];
        if (is_string($reason) && $reason !== '' && $reason !== 'unknown' && $reason !== 'no_progress' && ! $hasNoProgress) {
            $parts[] = $this->reasonLabel($reason);
        }

        if ($parsed['message'] !== null && $parsed['message'] !== '') {
            $parts[] = $this->humanizeMessage($parsed['message']);
        }

        if ($parts === []) {
            return $this->emptyDisplay(
                $this->t('health_empty_failure', 'No failure yet', 'Chưa có lỗi'),
                $parsed['raw'],
            );
        }

        return [
            'text' => implode(' · ', $parts),
            'tooltip' => $parsed['occurred_at'] !== null ? $this->preciseTimestamp($parsed['occurred_at']) : null,
            'empty' => false,
            'raw' => $parsed['raw'],
        ];
    }

    public function formatTimestamp(mixed $value): ?string
    {
        return SystemDateTime::formatDateTime($value);
    }

    public function formatTimestampPrecise(mixed $value): ?string
    {
        return SystemDateTime::formatDateTimePrecise($value);
    }

    public function taskCount(int $count): string
    {
        $count = max(0, $count);
        if ($count === 1) {
            return $this->t('health_task_one', '1 task', '1 tác vụ', ['count' => '1']);
        }

        return $this->t('health_task_many', ':count tasks', ':count tác vụ', ['count' => (string) $count]);
    }

    public function dueCount(int $count): string
    {
        $count = max(0, $count);
        if ($count === 1) {
            return $this->t('health_due_one', '1 due task', '1 tác vụ đến hạn', ['count' => '1']);
        }

        return $this->t('health_due_many', ':count due tasks', ':count tác vụ đến hạn', ['count' => (string) $count]);
    }

    public function reasonLabel(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || $code === 'unknown') {
            return $this->t('health_reason_unknown', 'Unknown reason', 'Chưa xác định nguyên nhân');
        }

        return match ($code) {
            'no_progress' => $this->t('health_reason_no_progress', 'No progress', 'Không có tiến triển'),
            'active_publish', 'active_lease' => $this->t('health_reason_active_publish', 'Publishing in progress', 'Đang xuất bản'),
            'stale_claim' => $this->t('health_reason_stale_claim', 'Stuck publish lock', 'Kẹt quyền xuất bản'),
            'awaiting_worker' => $this->t('health_reason_awaiting_worker', 'Waiting for worker', 'Đang chờ worker'),
            'idempotent_replay', 'stale_operation' => $this->t('health_reason_stale_operation', 'Stale operation', 'Thao tác cũ'),
            'lock_busy' => $this->t('health_reason_lock_busy', 'Temporarily locked', 'Đang bị khóa'),
            'invalid_status' => $this->t('health_reason_invalid_status', 'Invalid status', 'Trạng thái không hợp lệ'),
            'dispatch_failed' => $this->t('health_reason_dispatch_failed', 'Could not dispatch publish', 'Gửi xuất bản thất bại'),
            'attempts_exhausted' => $this->t('health_reason_attempts_exhausted', 'Retry limit reached', 'Hết lượt thử'),
            'missing_article' => $this->t('health_reason_missing_article', 'Article missing', 'Thiếu bài viết'),
            'missing_connection', 'connection_failed' => $this->t('health_reason_connection_failed', 'Could not connect to website', 'Không thể kết nối website'),
            'failed' => $this->t('health_reason_failed', 'Failed', 'Thất bại'),
            'timeout' => $this->t('health_reason_timeout', 'Timed out', 'Hết thời gian chờ'),
            'processing' => $this->t('health_reason_processing', 'Processing', 'Đang xử lý'),
            'retrying' => $this->t('health_reason_retrying', 'Retrying', 'Đang thử lại'),
            'stale' => $this->t('health_reason_stale', 'Stale', 'Không còn mới'),
            'waiting' => $this->t('health_reason_waiting', 'Waiting', 'Đang chờ'),
            'success' => $this->t('health_reason_success', 'Success', 'Thành công'),
            'not_due' => $this->t('health_reason_not_due', 'Not due yet', 'Chưa đến hạn'),
            'not_found' => $this->t('health_reason_not_found', 'Not found', 'Không tìm thấy'),
            'claimed' => $this->t('health_reason_claimed', 'Claimed', 'Đã nhận'),
            'other' => $this->t('health_reason_other', 'Other', 'Khác'),
            default => $this->humanizeUnknownCode($code),
        };
    }

    public function runnerLabel(string $status): string
    {
        return match ($status) {
            'connection_failed' => $this->t('health_runner_connection_failed', 'Publishing connection failed', 'Không thể kết nối website'),
            'stopped' => $this->t('health_runner_stopped', 'Runner stopped — scanner heartbeat is stale', 'Runner đã dừng — heartbeat scanner quá cũ'),
            'healthy' => $this->t('health_runner_healthy', 'Runner healthy', 'Runner đang hoạt động tốt'),
            'stale' => $this->t('health_runner_stale', 'Runner stale / unavailable', 'Runner không còn mới / không khả dụng'),
            'degraded' => $this->t('health_runner_degraded', 'Scheduler heartbeat only — no successful publish processing', 'Chỉ có heartbeat scheduler — chưa xuất bản thành công'),
            default => $this->humanizeUnknownCode($status),
        };
    }

    /**
     * @param  array<string, int>  $skipReasonCounts
     */
    public function overdueLabel(
        int $dueTotal,
        int $overdueScheduled,
        int $overdueRetry,
        ?string $dominantReason,
        array $skipReasonCounts,
    ): string {
        if ($dominantReason !== null && $dominantReason !== '' && $dueTotal > 0) {
            $blocked = (int) ($skipReasonCounts[$dominantReason] ?? $dueTotal);

            return $this->t(
                'health_overdue_blocked',
                ':due overdue articles were not processed — :blocked blocked by :reason.',
                ':due bài quá hạn chưa được xử lý — :blocked bị chặn bởi :reason.',
                [
                    'due' => (string) $dueTotal,
                    'blocked' => (string) $blocked,
                    'reason' => $this->reasonLabel($dominantReason),
                ],
            );
        }

        return $this->t(
            'health_overdue_split',
            ':due overdue articles were not processed (:scheduled scheduled + :retry retry).',
            ':due bài quá hạn chưa được xử lý (:scheduled đã lên lịch + :retry thử lại).',
            [
                'due' => (string) $dueTotal,
                'scheduled' => (string) $overdueScheduled,
                'retry' => (string) $overdueRetry,
            ],
        );
    }

    public function empty(string $context = 'generic'): string
    {
        return match ($context) {
            'success' => $this->t('health_empty_success', 'No successful run yet', 'Chưa có lần chạy thành công'),
            'worker' => $this->t('health_empty_worker', 'No worker activity yet', 'Chưa có hoạt động worker'),
            'failure' => $this->t('health_empty_failure', 'No failure yet', 'Chưa có lỗi'),
            default => $this->t('health_empty', 'No data yet', 'Chưa có dữ liệu'),
        };
    }

    public static function isKnownReason(string $code): bool
    {
        return in_array(strtolower(trim($code)), OperationalStatusParser::KNOWN_REASON_CODES, true);
    }

    /**
     * @return Display
     */
    private function formatTimestampOnly(?string $raw, string $emptyLabel): array
    {
        $parsed = OperationalStatusParser::parse($raw);
        if ($parsed['occurred_at'] === null) {
            return $this->emptyDisplay($emptyLabel, $parsed['raw']);
        }

        return [
            'text' => $this->compactTimestamp($parsed['occurred_at']),
            'tooltip' => $this->preciseTimestamp($parsed['occurred_at']),
            'empty' => false,
            'raw' => $parsed['raw'],
        ];
    }

    /**
     * @return Display
     */
    private function emptyDisplay(string $text, ?string $raw): array
    {
        return [
            'text' => $text,
            'tooltip' => null,
            'empty' => true,
            'raw' => $raw,
        ];
    }

    private function compactTimestamp(mixed $value): string
    {
        return SystemDateTime::formatDateTime($value) ?? $this->empty();
    }

    private function preciseTimestamp(mixed $value): ?string
    {
        return SystemDateTime::formatDateTimePrecise($value);
    }

    private function connectionLabel(?int $connectionId): ?string
    {
        if ($connectionId === null || $connectionId <= 0) {
            return null;
        }

        if ($this->connectionLabelResolver instanceof \Closure) {
            try {
                $label = ($this->connectionLabelResolver)($connectionId);
            } catch (\Throwable) {
                $label = null;
            }

            return is_string($label) && trim($label) !== ''
                ? $this->normalizeConnectionLabel($label)
                : null;
        }

        try {
            $name = SeoDatabaseConnection::query()->whereKey($connectionId)->value('name');
        } catch (\Throwable) {
            return null;
        }

        return is_string($name) && trim($name) !== ''
            ? $this->normalizeConnectionLabel($name)
            : null;
    }

    private function normalizeConnectionLabel(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^SEO DB\s+[—–-]\s+(.+)$/u', $name, $matches) === 1) {
            return trim($matches[1]);
        }

        return $name;
    }

    private function humanizeMessage(string $message): string
    {
        $trimmed = trim($message);
        if ($this->looksTechnical($trimmed)) {
            if (str_contains($trimmed, 'cURL') || str_contains($trimmed, 'timed out') || str_contains($trimmed, 'Connection refused')) {
                return $this->t('health_error_connect', 'Could not connect to WordPress.', 'Không thể kết nối WordPress.');
            }

            return $this->t('health_error_generic', 'Something went wrong.', 'Đã xảy ra lỗi.');
        }

        if (self::isKnownReason($trimmed)) {
            return $this->reasonLabel($trimmed);
        }

        if (preg_match('/^[a-z0-9_]+$/', $trimmed) === 1) {
            return $this->humanizeUnknownCode($trimmed);
        }

        return $trimmed;
    }

    private function looksTechnical(string $message): bool
    {
        return str_contains($message, 'SQLSTATE')
            || str_contains($message, 'cURL error')
            || str_contains($message, 'Http\\Client')
            || str_contains($message, 'Class "')
            || str_contains($message, 'exception=')
            || (bool) preg_match('/^[A-Za-z0-9_\\\\]+Exception\b/', $message);
    }

    private function humanizeUnknownCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return $this->t('health_reason_unknown', 'Unknown reason', 'Chưa xác định nguyên nhân');
        }

        $label = str_replace(['_', '-'], ' ', $code);

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function t(string $key, string $en, string $vi, array $replace = []): string
    {
        $fullKey = 'seo-content-ai::filament.projects.'.$key;
        try {
            $translated = __($fullKey, $replace);
            if (is_string($translated) && $translated !== '' && $translated !== $fullKey) {
                return $translated;
            }
        } catch (\Throwable) {
            // Pure PHPUnit without translator.
        }

        $template = SystemDateTime::preset() === 'en' ? $en : $vi;
        foreach ($replace as $name => $value) {
            $template = str_replace(':'.$name, $value, $template);
        }

        return $template;
    }
}
