<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Enums;

/**
 * Status của seo_project_run_items — khớp legacy JSON run.items.status.
 */
enum SeoProjectRunItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Manual = 'manual';
    /** Intentional pause (e.g. outline review checkpoint) — not a failure. */
    case Paused = 'paused';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public static function fromLegacy(?string $status): self
    {
        return match (trim((string) $status)) {
            'success' => self::Success,
            'failed' => self::Failed,
            'manual' => self::Manual,
            'processing' => self::Processing,
            'skipped' => self::Skipped,
            'paused' => self::Paused,
            default => self::Pending,
        };
    }

    /**
     * Status đưa vào JSON mirror UI cũ (không có processing/skipped).
     */
    public function toLegacyJsonStatus(): string
    {
        return match ($this) {
            self::Processing => self::Pending->value,
            self::Skipped => self::Success->value,
            // Keep paused visible — never map to failed/success.
            self::Paused => self::Paused->value,
            default => $this->value,
        };
    }
}
