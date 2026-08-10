<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Task lifecycle statuses — bao gồm legacy + status mới cho Phase 3+.
 *
 * Model (`SeoProjectTask::$casts`) vẫn lưu `status` dưới dạng string —
 * cố ý KHÔNG dùng Eloquent enum cast (`'status' => self::class`), vì:
 * - Raw SQL / dashboard aggregate query so sánh trực tiếp bằng string
 *   (`SeoProjectTask::STATUS_*`), không qua Eloquent hydrate.
 * - Filament table/filter options và badge presenter đọc string thô.
 * - Legacy/free-form values (xem {@see ContentProjectTaskStatusNormalizer})
 *   phải fail-soft qua tryNormalize() thay vì Eloquent ném lỗi cast khi
 *   gặp giá trị lạ.
 * Dùng {@see ContentProjectTaskStatusNormalizer} để chuẩn hoá về enum khi cần.
 */
enum SeoProjectTaskStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Writing = 'writing';
    case Processing = 'processing';
    case Reviewing = 'reviewing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function legacyValues(): array
    {
        return [
            self::Pending->value,
            self::Writing->value,
            self::Reviewing->value,
            self::Completed->value,
            self::Failed->value,
        ];
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Archived,
            self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Pending,
            self::Writing,
            self::Processing,
            self::Reviewing => true,
            default => false,
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
