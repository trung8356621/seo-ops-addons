<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Enums;

enum WpSyncJobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Stale = 'stale';

    /**
     * @return list<string>
     */
    public static function unfinishedValues(): array
    {
        // Badge + Sync Queue tab: chưa hoàn tất thành công (failed vẫn giữ để retry).
        return [
            self::Pending->value,
            self::Processing->value,
            self::Failed->value,
            self::Stale->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Pending->value, self::Processing->value];
    }

    /**
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return [
            self::Completed->value,
            self::Failed->value,
            self::Cancelled->value,
            self::Stale->value,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::terminalValues(), true);
    }

    /** Public status cho overlay / poll (pending → queued). */
    public function toPublicStatus(): string
    {
        return match ($this) {
            self::Pending => 'queued',
            self::Processing => 'processing',
            self::Completed => 'success',
            self::Failed => 'failed',
            self::Cancelled => 'cancelled',
            self::Stale => 'stale',
        };
    }
}
