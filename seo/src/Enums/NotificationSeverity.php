<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum NotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';
    case Critical = 'critical';

    public function filamentStatus(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Danger, self::Critical => 'danger',
        };
    }

    public function filamentIcon(): string
    {
        return match ($this) {
            self::Info => 'heroicon-o-information-circle',
            self::Warning => 'heroicon-o-exclamation-triangle',
            self::Danger => 'heroicon-o-x-circle',
            self::Critical => 'heroicon-o-fire',
        };
    }

    public function filamentIconColor(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Danger => 'danger',
            self::Critical => 'danger',
        };
    }
}
